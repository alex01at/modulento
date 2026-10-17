<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use RuntimeException;

/**
 * The public catalog (GET /v1/catalog) of a configured license server
 * (core.update.license_server, see Support\LicenseServerClient) - products
 * an operator sells that an administrator can browse and, once bought,
 * install straight from this installation's own admin area. Browsing never
 * fails loudly: no license server configured, or one that is unreachable,
 * just means an empty catalog, not a broken page.
 *
 * @phpstan-type CatalogProduct array{id: string, name: string, description: string, price_cents: int, currency: string, screenshot_url: ?string}
 */
final class ShopCatalog
{
    public function __construct(private HttpClient $http, private string $licenseServerUrl)
    {
    }

    /** @return list<CatalogProduct> */
    public function fetch(): array
    {
        if ($this->licenseServerUrl === '') {
            return [];
        }

        $response = $this->http->request('GET', rtrim($this->licenseServerUrl, '/') . '/v1/catalog', ['Accept' => 'application/json']);
        if ($response['status'] !== 200) {
            return [];
        }

        $body = json_decode($response['body'], true);
        if (!is_array($body) || !is_array($body['products'] ?? null)) {
            return [];
        }

        $products = [];
        foreach ($body['products'] as $product) {
            if (self::isValid($product)) {
                $products[] = [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'description' => $product['description'],
                    'price_cents' => $product['price_cents'],
                    'currency' => $product['currency'],
                    'screenshot_url' => is_string($product['screenshot_url'] ?? null) ? $product['screenshot_url'] : null,
                ];
            }
        }

        return $products;
    }

    /** Never trusts an external response further than this - a malformed entry is left out, not fatal to the rest. */
    private static function isValid(mixed $product): bool
    {
        return is_array($product)
            && is_string($product['id'] ?? null) && preg_match('/^[a-z][a-z0-9-]{0,63}$/', $product['id']) === 1
            && is_string($product['name'] ?? null) && $product['name'] !== ''
            && is_string($product['description'] ?? null)
            && is_int($product['price_cents'] ?? null) && $product['price_cents'] > 0
            && is_string($product['currency'] ?? null) && preg_match('/^[A-Z]{3}$/', $product['currency']) === 1;
    }

    /**
     * Starts buying a cataloged product: the license server opens a Stripe
     * Checkout Session for its current price and hands back where to send
     * the browser, plus a reference to poll checkStatus() with afterwards.
     * $successUrl may contain the literal substring "{reference}" - the
     * license server fills it in, since the reference does not exist yet
     * when this call is made.
     *
     * @return array{reference: string, checkout_url: string}
     * @throws \RuntimeException a language key, on anything other than success
     */
    public function startPurchase(string $productId, string $domain, string $successUrl, string $cancelUrl): array
    {
        if ($this->licenseServerUrl === '') {
            throw new RuntimeException('core.admin.shop.error.no_server');
        }

        $response = $this->http->request(
            'POST',
            rtrim($this->licenseServerUrl, '/') . '/v1/purchase/start',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode(['product' => $productId, 'domain' => $domain, 'success_url' => $successUrl, 'cancel_url' => $cancelUrl], JSON_UNESCAPED_SLASHES)
        );

        $body = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($body) || !is_string($body['reference'] ?? null) || !is_string($body['checkout_url'] ?? null)) {
            throw new RuntimeException(match (is_array($body) ? (string) ($body['error'] ?? '') : '') {
                'not_for_sale' => 'core.admin.shop.error.not_for_sale',
                'rate_limited' => 'core.admin.shop.error.rate_limited',
                default => 'core.admin.shop.error.unreachable',
            });
        }

        return ['reference' => $body['reference'], 'checkout_url' => $body['checkout_url']];
    }

    /**
     * @return array{status: string, product: ?string, license_key: ?string}
     *         status: "pending", "completed" or "not_found"; license_key
     *         is only ever non-null the one time it is read back right
     *         after completion.
     */
    public function checkStatus(string $reference): array
    {
        $fallback = ['status' => 'not_found', 'product' => null, 'license_key' => null];
        if ($this->licenseServerUrl === '') {
            return $fallback;
        }

        $response = $this->http->request(
            'GET',
            rtrim($this->licenseServerUrl, '/') . '/v1/purchase/status?ref=' . rawurlencode($reference),
            ['Accept' => 'application/json']
        );
        $body = json_decode($response['body'], true);
        if (!is_array($body) || !is_string($body['status'] ?? null)) {
            return $fallback;
        }

        return [
            'status' => $body['status'],
            'product' => is_string($body['product'] ?? null) ? $body['product'] : null,
            'license_key' => is_string($body['license_key'] ?? null) ? $body['license_key'] : null,
        ];
    }
}
