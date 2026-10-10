<?php

declare(strict_types=1);

namespace Modulento\Core\Payment;

use Modulento\Core\Support\HttpClient;

/**
 * What the core asks of Stripe. The platform has a Stripe account with
 * Connect; every provider gets a connected Standard account of their own,
 * and a payment is a direct charge on that account: the money goes from
 * the buyer to the provider and never through the platform, which takes no
 * fee.
 */
final class StripeGateway
{
    private const API = 'https://api.stripe.com';
    /** How old a webhook's signature may be, in seconds. */
    public const WEBHOOK_TOLERANCE = 300;

    public function __construct(private HttpClient $http, private string $secretKey)
    {
    }

    /** Creates a connected Standard account. @return string its id ("acct_...") */
    public function createAccount(): string
    {
        $account = $this->call('POST', '/v1/accounts', ['type' => 'standard']);

        return self::accountId($account['id'] ?? null) ?? throw new PaymentException('core.payment.error.unexpected', 'Stripe POST /v1/accounts: no account id');
    }

    /** @return string the address at Stripe where the provider completes the account */
    public function accountLink(string $account, string $refreshUrl, string $returnUrl): string
    {
        $link = $this->call('POST', '/v1/account_links', [
            'account' => $account,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return is_string($link['url'] ?? null) ? $link['url'] : throw new PaymentException('core.payment.error.unexpected', 'Stripe POST /v1/account_links: no url');
    }

    /** Whether a connected account can take payments yet. */
    public function chargesEnabled(string $account): bool
    {
        if (self::accountId($account) === null) {
            throw new PaymentException('core.payment.error.unexpected', 'Stripe: malformed account id');
        }

        return ($this->call('GET', '/v1/accounts/' . $account)['charges_enabled'] ?? false) === true;
    }

    /**
     * A Checkout Session on the connected account, for one amount.
     *
     * @param int $amount in minor units
     * @return array{id: string, url: string} the session and where the buyer pays
     */
    public function createCheckoutSession(string $account, int $amount, string $currency, string $productName, int $orderId, string $successUrl, string $cancelUrl): array
    {
        $session = $this->call('POST', '/v1/checkout/sessions', [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $amount,
                    'product_data' => ['name' => $productName],
                ],
            ]],
            'client_reference_id' => (string) $orderId,
            'metadata' => ['order_id' => (string) $orderId],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ], $account);

        if (!is_string($session['id'] ?? null) || !is_string($session['url'] ?? null)) {
            throw new PaymentException('core.payment.error.unexpected', 'Stripe POST /v1/checkout/sessions: no id or url');
        }

        return ['id' => $session['id'], 'url' => $session['url']];
    }

    /**
     * A Checkout Session on the platform's own account for a subscription:
     * the buyer pays every period and Stripe repeats the charge.
     *
     * @param int $amount in minor units, for one period of $months months - the price of ONE seat when $quantity > 1
     * @return array{id: string, url: string}
     */
    public function createSubscriptionCheckout(int $amount, string $currency, int $months, string $productName, string $reference, string $successUrl, string $cancelUrl, int $quantity = 1): array
    {
        $session = $this->call('POST', '/v1/checkout/sessions', [
            'mode' => 'subscription',
            'line_items' => [[
                'quantity' => $quantity,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $amount,
                    'recurring' => ['interval' => 'month', 'interval_count' => $months],
                    'product_data' => ['name' => $productName],
                ],
            ]],
            'client_reference_id' => $reference,
            'metadata' => ['subscription_order' => $reference],
            // Repeated on every invoice, so that a renewal can be matched to its subscription.
            'subscription_data' => ['metadata' => ['subscription_order' => $reference]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        if (!is_string($session['id'] ?? null) || !is_string($session['url'] ?? null)) {
            throw new PaymentException('core.payment.error.unexpected', 'Stripe POST /v1/checkout/sessions (subscription): no id or url');
        }

        return ['id' => $session['id'], 'url' => $session['url']];
    }

    /**
     * The id of a subscription's one line item - where quantity lives in
     * Stripe's model, not on the subscription itself. Captured once, right
     * after a Checkout session completes, so a seat count can be updated
     * live afterwards without asking Stripe again.
     */
    public function subscriptionItemId(string $subscription): ?string
    {
        if (preg_match('/^sub_[A-Za-z0-9]+$/', $subscription) !== 1) {
            throw new PaymentException('core.payment.error.unexpected', 'Stripe: malformed subscription id');
        }

        $data = $this->call('GET', '/v1/subscriptions/' . $subscription);
        $item = $data['items']['data'][0]['id'] ?? null;

        return is_string($item) ? $item : null;
    }

    /** Changes the seat count of a running subscription; Stripe prorates the difference onto the next invoice. */
    public function updateSubscriptionItemQuantity(string $itemId, int $quantity): void
    {
        if (preg_match('/^si_[A-Za-z0-9]+$/', $itemId) !== 1) {
            throw new PaymentException('core.payment.error.unexpected', 'Stripe: malformed subscription item id');
        }

        $this->call('POST', '/v1/subscription_items/' . $itemId, ['quantity' => $quantity]);
    }

    /** Ends the renewal at the close of the current period; the subscription runs to its end. */
    public function cancelSubscription(string $subscription): void
    {
        if (preg_match('/^sub_[A-Za-z0-9]+$/', $subscription) !== 1) {
            throw new PaymentException('core.payment.error.unexpected', 'Stripe: malformed subscription id');
        }

        $this->call('POST', '/v1/subscriptions/' . $subscription, ['cancel_at_period_end' => 'true']);
    }

    /** @return array{id: string, paid: bool, amount: int, currency: string} what Stripe knows about a session */
    public function checkoutSession(string $account, string $sessionId): array
    {
        if (preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId) !== 1) {
            throw new PaymentException('core.payment.error.unexpected', 'Stripe: malformed session id');
        }

        return self::sessionSummary($this->call('GET', '/v1/checkout/sessions/' . $sessionId, null, $account));
    }

    /**
     * The fields of a Checkout Session the core decides by, from an API
     * answer or from a webhook.
     *
     * @return array{id: string, paid: bool, amount: int, currency: string}
     */
    public static function sessionSummary(array $session): array
    {
        return [
            'id' => is_string($session['id'] ?? null) ? $session['id'] : '',
            'paid' => ($session['payment_status'] ?? null) === 'paid',
            // -1 can never equal an order's total.
            'amount' => is_int($session['amount_total'] ?? null) ? $session['amount_total'] : -1,
            'currency' => is_string($session['currency'] ?? null) ? strtoupper($session['currency']) : '',
        ];
    }

    /**
     * Checks a webhook's "Stripe-Signature" header ("t=<time>,v1=<hex>"):
     * HMAC-SHA256 over "<time>.<raw body>" with the endpoint's signing
     * secret. The time is part of what is signed, so an old delivery that
     * someone recorded cannot be sent again later.
     *
     * @return array|null the event, or null if the signature does not fit
     */
    public static function verifyWebhook(string $payload, string $header, string $secret, ?int $now = null): ?array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($name === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($secret === '' || $timestamp === null || $signatures === [] || abs(($now ?? time()) - $timestamp) > self::WEBHOOK_TOLERANCE) {
            return null;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $valid = false;
        foreach ($signatures as $signature) {
            $valid = hash_equals($expected, $signature) || $valid;
        }

        $event = $valid ? json_decode($payload, true) : null;

        return is_array($event) ? $event : null;
    }

    public static function accountId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^acct_[A-Za-z0-9]+$/', $value) === 1 ? $value : null;
    }

    /**
     * @param array<string, mixed>|null $fields sent form-encoded, nested fields in Stripe's bracket notation
     * @param string|null $account the connected account the call is made for
     */
    private function call(string $method, string $path, ?array $fields = null, ?string $account = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->secretKey];
        if ($account !== null) {
            $headers['Stripe-Account'] = $account;
        }
        if ($fields !== null) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        $answer = $this->http->request($method, self::API . $path, $headers, $fields !== null ? http_build_query($fields) : null);
        $data = json_decode($answer['body'], true);
        // Path without ids: the log says what failed, not for whom.
        $what = 'Stripe ' . $method . ' ' . preg_replace('#/(acct|cs)_[^/]+#', '/…', $path) . ': HTTP ' . $answer['status'];

        if ($answer['status'] === 0) {
            throw new PaymentException('core.payment.error.unreachable', $what);
        }
        if ($answer['status'] < 200 || $answer['status'] >= 300 || !is_array($data)) {
            $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
            // Type and code only: the message can quote what was sent.
            $code = trim((string) preg_replace('/[^a-z0-9_ ]/', '', (is_string($error['type'] ?? null) ? $error['type'] : '') . ' ' . (is_string($error['code'] ?? null) ? $error['code'] : '')));

            throw new PaymentException(
                in_array($answer['status'], [401, 403], true) ? 'core.payment.error.credentials' : 'core.payment.error.refused',
                $what . ($code !== '' ? ' (' . $code . ')' : '')
            );
        }

        return $data;
    }
}
