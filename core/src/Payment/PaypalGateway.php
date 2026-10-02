<?php

declare(strict_types=1);

namespace Modulento\Core\Payment;

use Modulento\Core\Support\HttpClient;

/**
 * What the core asks of PayPal, with the REST credentials of the
 * provider's own PayPal app: the payment is one between the buyer and that
 * provider's PayPal account. (Taking payments for other merchants' accounts
 * would need PayPal's partner programme, which a self-hosted site has not.)
 *
 * Amounts are written with two decimals; currencies without decimals (JPY)
 * or with three are not supported.
 */
final class PaypalGateway
{
    private const LIVE = 'https://api-m.paypal.com';
    private const SANDBOX = 'https://api-m.sandbox.paypal.com';

    private ?string $token = null;

    public function __construct(private HttpClient $http, private string $clientId, private string $secret, private bool $sandbox)
    {
    }

    /** Asks for an access token - the check that the credentials work. @throws PaymentException */
    public function verify(): void
    {
        $this->token();
    }

    /**
     * An order at PayPal for one amount, to be captured once the buyer
     * approved it.
     *
     * @param int $amount in minor units
     * @param string $requestId makes a repeated request (a timeout, a second click) return the same order
     * @return array{id: string, url: string} PayPal's order id and where the buyer approves the payment
     */
    public function createOrder(int $amount, string $currency, string $customId, string $description, string $returnUrl, string $cancelUrl, string $requestId): array
    {
        $order = $this->call('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'custom_id' => $customId,
                'description' => mb_substr($description, 0, 127),
                'amount' => ['currency_code' => strtoupper($currency), 'value' => self::decimal($amount)],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'user_action' => 'PAY_NOW',
            ]]],
        ], ['PayPal-Request-Id' => $requestId]);

        $url = null;
        foreach (is_array($order['links'] ?? null) ? $order['links'] : [] as $link) {
            // "payer-action" when a payment source was named, "approve" otherwise.
            if (is_array($link) && in_array($link['rel'] ?? null, ['payer-action', 'approve'], true) && is_string($link['href'] ?? null)) {
                $url = $link['href'];
                break;
            }
        }

        if (self::orderId($order['id'] ?? null) === null || $url === null) {
            throw new PaymentException('core.payment.error.unexpected', 'PayPal POST /v2/checkout/orders: no id or approval link');
        }

        return ['id' => $order['id'], 'url' => $url];
    }

    /** @return array{status: string, paid: bool, amount: ?int, currency: ?string, custom_id: ?string} what PayPal knows about an order */
    public function order(string $orderId): array
    {
        return self::summary($this->call('GET', '/v2/checkout/orders/' . self::checked($orderId)));
    }

    /**
     * Takes the money of an order the buyer approved.
     *
     * @return array{status: string, paid: bool, amount: ?int, currency: ?string, custom_id: ?string}
     */
    public function capture(string $orderId): array
    {
        // The full order in the answer, so that amount and currency of the
        // capture can be compared with what was ordered.
        return self::summary($this->call('POST', '/v2/checkout/orders/' . self::checked($orderId) . '/capture', null, ['Prefer' => 'return=representation']));
    }

    /**
     * "Paid" means: the order is completed and one of its captures is, in
     * which case that capture's amount and currency are returned. A capture
     * that PayPal still holds back ("PENDING") is not a payment yet.
     *
     * @return array{status: string, paid: bool, amount: ?int, currency: ?string, custom_id: ?string}
     */
    public static function summary(array $order): array
    {
        $unit = is_array($order['purchase_units'][0] ?? null) ? $order['purchase_units'][0] : [];
        $completed = null;
        foreach (is_array($unit['payments']['captures'] ?? null) ? $unit['payments']['captures'] : [] as $capture) {
            if (is_array($capture) && ($capture['status'] ?? null) === 'COMPLETED') {
                $completed = $capture;
                break;
            }
        }

        $status = is_string($order['status'] ?? null) ? $order['status'] : '';
        $value = $completed['amount']['value'] ?? null;
        $currency = $completed['amount']['currency_code'] ?? null;

        return [
            'status' => $status,
            'paid' => $status === 'COMPLETED' && $completed !== null,
            'amount' => is_string($value) ? self::minorUnits($value) : null,
            'currency' => is_string($currency) ? strtoupper($currency) : null,
            // Named on the purchase unit, and repeated on its capture.
            'custom_id' => is_string($unit['custom_id'] ?? null) ? $unit['custom_id'] : (is_string($completed['custom_id'] ?? null) ? $completed['custom_id'] : null),
        ];
    }

    /** 11900 becomes "119.00" - without a float in between. */
    public static function decimal(int $minorUnits): string
    {
        return intdiv($minorUnits, 100) . '.' . str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "119.00" becomes 11900; null for anything that is not an amount with two decimals. */
    public static function minorUnits(string $decimal): ?int
    {
        return preg_match('/^(\d{1,9})\.(\d{2})$/', $decimal, $parts) === 1 ? (int) $parts[1] * 100 + (int) $parts[2] : null;
    }

    public static function orderId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $value) === 1 ? $value : null;
    }

    /** An id becomes part of an address, so it must look like one. */
    private static function checked(string $orderId): string
    {
        return self::orderId($orderId) ?? throw new PaymentException('core.payment.error.unexpected', 'PayPal: malformed order id');
    }

    private function base(): string
    {
        return $this->sandbox ? self::SANDBOX : self::LIVE;
    }

    /** An access token from the client credentials, asked for once per request of ours. */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $answer = $this->http->request('POST', $this->base() . '/v1/oauth2/token', [
            'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->secret),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], 'grant_type=client_credentials');
        $data = json_decode($answer['body'], true);

        if ($answer['status'] === 0) {
            throw new PaymentException('core.payment.error.unreachable', 'PayPal POST /v1/oauth2/token: HTTP 0');
        }
        if ($answer['status'] !== 200 || !is_array($data) || !is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            throw new PaymentException(
                in_array($answer['status'], [400, 401, 403], true) ? 'core.payment.error.credentials' : 'core.payment.error.refused',
                'PayPal POST /v1/oauth2/token: HTTP ' . $answer['status']
            );
        }

        return $this->token = $data['access_token'];
    }

    /**
     * @param array<string, mixed>|null $json the request body, null for none
     * @param array<string, string> $headers
     */
    private function call(string $method, string $path, ?array $json = null, array $headers = []): array
    {
        $answer = $this->http->request($method, $this->base() . $path, [
            'Authorization' => 'Bearer ' . $this->token(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ] + $headers, $json !== null ? json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($method === 'POST' ? '' : null));
        $data = json_decode($answer['body'], true);
        // Path without ids: the log says what failed, not for whom.
        $what = 'PayPal ' . $method . ' ' . preg_replace('#/orders/[^/]+#', '/orders/…', $path) . ': HTTP ' . $answer['status'];

        if ($answer['status'] === 0) {
            throw new PaymentException('core.payment.error.unreachable', $what);
        }
        if ($answer['status'] < 200 || $answer['status'] >= 300 || !is_array($data)) {
            // PayPal's error name ("UNPROCESSABLE_ENTITY") only: the
            // description can quote what was sent.
            $name = is_array($data) && is_string($data['name'] ?? null) ? preg_replace('/[^A-Z_]/', '', $data['name']) : '';

            throw new PaymentException(
                in_array($answer['status'], [401, 403], true) ? 'core.payment.error.credentials' : 'core.payment.error.refused',
                $what . ($name !== '' ? ' (' . $name . ')' : '')
            );
        }

        return $data;
    }
}
