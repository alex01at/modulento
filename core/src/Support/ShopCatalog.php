<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

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
}
