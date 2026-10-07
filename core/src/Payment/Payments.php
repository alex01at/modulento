<?php

declare(strict_types=1);

namespace Modulento\Core\Payment;

use Closure;
use Modulento\Core\App;
use Modulento\Core\Order\OrderNotifier;
use Modulento\Core\Order\Orders;
use Modulento\Core\Order\PaymentMethod;
use Modulento\Core\Order\ProviderPaymentMethod;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\HttpClient;
use Modulento\Core\Support\Secrets;
use Modulento\Core\Support\Settings;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Which ways to pay the operator allows, what each provider has set up for
 * them, and the payments started for orders.
 *
 * Money always goes from the buyer straight to the provider - to the
 * provider's bank account, PayPal account or Stripe account. The platform
 * holds none of it and takes no fee; it only learns that a payment arrived
 * and records that on the order.
 */
final class Payments
{
    public const OFFLINE = 'core.offline';
    public const TRANSFER = 'core.transfer';
    public const PAYPAL = 'core.paypal';
    public const STRIPE = 'core.stripe';

    /** Switched on by the operator; everything else is allowed until switched off. */
    private const OFF_UNLESS_ENABLED = [self::TRANSFER, self::PAYPAL, self::STRIPE];
    /** These store API keys, which is only done encrypted. */
    public const NEEDS_ENCRYPTION = [self::PAYPAL, self::STRIPE];

    /**
     * Where a buyer or provider may be sent: nowhere but the services' own pages.
     * Public: public/index.php reads these too, for the CSP header's form-action,
     * so that the allowed hosts are declared in one place only.
     */
    public const STRIPE_CHECKOUT_HOSTS = ['checkout.stripe.com'];
    public const STRIPE_CONNECT_HOSTS = ['connect.stripe.com'];
    public const PAYPAL_HOSTS = ['www.paypal.com', 'www.sandbox.paypal.com'];

    /** @var Closure[] */
    private array $stripeListeners = [];

    public function __construct(
        private PDO $db,
        private Settings $settings,
        private Orders $orders,
        private Secrets $secrets,
        private HttpClient $http
    ) {
    }

    // --- What the operator allows ------------------------------------------------

    public function encryptionAvailable(): bool
    {
        return Secrets::available();
    }

    public function isEnabled(string $methodId): bool
    {
        if (in_array($methodId, self::NEEDS_ENCRYPTION, true) && !Secrets::available()) {
            return false;
        }

        $stored = $this->settings->get('core.payment.enabled.' . $methodId);

        return $stored === '' ? !in_array($methodId, self::OFF_UNLESS_ENABLED, true) : $stored === '1';
    }

    public function setEnabled(string $methodId, bool $enabled): void
    {
        $this->settings->set('core.payment.enabled.' . $methodId, $enabled ? '1' : '0');
    }

    /**
     * The ways to pay an order of this provider can be paid with: allowed
     * by the operator and, where the provider has to set something up, set
     * up by this provider.
     *
     * @return array<string, PaymentMethod>
     */
    public function availableFor(?int $providerId, App $app): array
    {
        $available = [];
        foreach ($this->orders->paymentMethods() as $id => $method) {
            if (!$this->isEnabled($id)) {
                continue;
            }
            if ($method instanceof ProviderPaymentMethod && ($providerId === null || !$method->availableFor($providerId, $app))) {
                continue;
            }
            $available[$id] = $method;
        }

        return $available;
    }

    // --- The platform's Stripe account ------------------------------------------

    public function stripeConfigured(): bool
    {
        return $this->stripeSecretKey() !== null;
    }

    /** @return array{secret_key: ?string, webhook_secret: ?string} the last characters of each, for "is set" - never the value */
    public function stripeHints(): array
    {
        $hint = fn (?string $value) => $value !== null ? substr($value, -4) : null;

        return ['secret_key' => $hint($this->stripeSecretKey()), 'webhook_secret' => $hint($this->stripeWebhookSecret())];
    }

    /** An empty string removes a value, null leaves it as it is. */
    public function saveStripeKeys(?string $secretKey, ?string $webhookSecret): void
    {
        foreach (['core.payment.stripe.secret_key' => $secretKey, 'core.payment.stripe.webhook_secret' => $webhookSecret] as $name => $value) {
            if ($value !== null) {
                $this->settings->set($name, $value === '' ? '' : $this->secrets->encrypt($value));
            }
        }
    }

    private function stripeSecretKey(): ?string
    {
        return $this->secretSetting('core.payment.stripe.secret_key');
    }

    private function stripeWebhookSecret(): ?string
    {
        return $this->secretSetting('core.payment.stripe.webhook_secret');
    }

    private function secretSetting(string $name): ?string
    {
        $stored = $this->settings->get($name);
        $plain = $stored !== '' ? $this->secrets->decrypt($stored) : null;

        return $plain !== null && $plain !== '' ? $plain : null;
    }

    private function stripe(): StripeGateway
    {
        return new StripeGateway($this->http, $this->stripeSecretKey() ?? throw new PaymentException('core.payment.error.unavailable', 'Stripe: the platform has no API key'));
    }

    // --- What a provider has set up ---------------------------------------------

    /** @return array{data: array<string, mixed>, status: string, updated_at: string}|null */
    public function config(int $providerId, string $method): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT data, status, updated_at FROM provider_payment WHERE provider_id = :provider AND method = :method');
            $stmt->execute(['provider' => $providerId, 'method' => $method]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            $row = self::unlessTableMissing($e);
        }

        return $row ? ['data' => json_decode((string) $row['data'], true) ?: [], 'status' => (string) $row['status'], 'updated_at' => (string) $row['updated_at']] : null;
    }

    /** Whether the provider's setup of a method is complete, so that buyers can be offered it. */
    public function isReady(int $providerId, string $method): bool
    {
        return ($this->config($providerId, $method)['status'] ?? null) === 'ready';
    }

    /** @param array{holder: string, iban: string, bic: string, bank: string} $details already checked and normalised */
    public function saveTransfer(int $providerId, array $details): void
    {
        $this->saveConfig($providerId, self::TRANSFER, $details, 'ready');
    }

    /** @return array{holder: string, iban: string, bic: string, bank: string}|null */
    public function transferDetails(int $providerId): ?array
    {
        $config = $this->config($providerId, self::TRANSFER);

        return $config !== null && $config['status'] === 'ready' ? [
            'holder' => (string) ($config['data']['holder'] ?? ''),
            'iban' => (string) ($config['data']['iban'] ?? ''),
            'bic' => (string) ($config['data']['bic'] ?? ''),
            'bank' => (string) ($config['data']['bank'] ?? ''),
        ] : null;
    }

    /**
     * Stores the credentials of the provider's PayPal app, the secret
     * encrypted. They count as set up once checkPaypal() has succeeded.
     *
     * @param string|null $secret null keeps the stored one
     */
    public function savePaypal(int $providerId, string $clientId, ?string $secret, bool $sandbox): void
    {
        $stored = $this->config($providerId, self::PAYPAL)['data']['secret'] ?? '';

        $this->saveConfig($providerId, self::PAYPAL, [
            'client_id' => $clientId,
            'secret' => $secret !== null ? $this->secrets->encrypt($secret) : $stored,
            'sandbox' => $sandbox,
        ], 'unverified');
    }

    /** Asks PayPal for a token with the stored credentials and records the outcome. @throws PaymentException */
    public function checkPaypal(int $providerId): void
    {
        try {
            $this->paypal($providerId)->verify();
        } catch (PaymentException $e) {
            // Credentials PayPal refuses must not be offered to buyers;
            // PayPal being unreachable says nothing about them.
            if ($e->messageKey === 'core.payment.error.credentials') {
                $this->setConfigStatus($providerId, self::PAYPAL, 'unverified');
            }
            throw $e;
        }

        $this->setConfigStatus($providerId, self::PAYPAL, 'ready');
    }

    /**
     * Creates the provider's connected Stripe account if there is none yet
     * and returns the address at Stripe where the provider completes it.
     *
     * @throws PaymentException
     */
    public function stripeOnboardingUrl(int $providerId, string $refreshUrl, string $returnUrl): string
    {
        $stripe = $this->stripe();
        $account = $this->stripeAccount($providerId);
        if ($account === null) {
            $account = $stripe->createAccount();
            $this->saveConfig($providerId, self::STRIPE, ['account' => $account], 'pending');
        }

        return self::redirectTarget($stripe->accountLink($account, $refreshUrl, $returnUrl), self::STRIPE_CONNECT_HOSTS);
    }

    /**
     * Asks Stripe whether the provider's account can take payments and
     * records the answer.
     *
     * @return string|null "ready", "pending", or null if no account is connected
     * @throws PaymentException
     */
    public function refreshStripeStatus(int $providerId): ?string
    {
        $account = $this->stripeAccount($providerId);
        if ($account === null) {
            return null;
        }

        $status = $this->stripe()->chargesEnabled($account) ? 'ready' : 'pending';
        $this->setConfigStatus($providerId, self::STRIPE, $status);

        return $status;
    }

    public function stripeAccount(int $providerId): ?string
    {
        return StripeGateway::accountId($this->config($providerId, self::STRIPE)['data']['account'] ?? null);
    }

    /** Forgets what a provider set up for a method. Accounts at the services themselves are not touched. */
    public function deleteConfig(int $providerId, string $method): void
    {
        $stmt = $this->db->prepare('DELETE FROM provider_payment WHERE provider_id = :provider AND method = :method');
        $stmt->execute(['provider' => $providerId, 'method' => $method]);
    }

    /** What a provider's data export says about payments: everything but the secrets. */
    public function export(int $providerId): array
    {
        $export = [];
        foreach ([self::TRANSFER, self::PAYPAL, self::STRIPE] as $method) {
            $config = $this->config($providerId, $method);
            if ($config !== null) {
                unset($config['data']['secret']);
                $export[$method] = $config['data'] + ['status' => $config['status'], 'updated_at' => $config['updated_at']];
            }
        }

        return $export;
    }

    private function paypal(int $providerId): PaypalGateway
    {
        $data = $this->config($providerId, self::PAYPAL)['data'] ?? [];
        $secret = is_string($data['secret'] ?? null) ? $this->secrets->decrypt($data['secret']) : null;

        if (!is_string($data['client_id'] ?? null) || $data['client_id'] === '' || $secret === null || $secret === '') {
            throw new PaymentException('core.payment.error.unavailable', 'PayPal: the provider has no usable credentials');
        }

        return new PaypalGateway($this->http, $data['client_id'], $secret, ($data['sandbox'] ?? false) === true);
    }

    private function saveConfig(int $providerId, string $method, array $data, string $status): void
    {
        $stmt = $this->db->prepare(
            $this->config($providerId, $method) !== null
                ? 'UPDATE provider_payment SET data = :data, status = :status, updated_at = :now WHERE provider_id = :provider AND method = :method'
                : 'INSERT INTO provider_payment (provider_id, method, data, status, updated_at) VALUES (:provider, :method, :data, :status, :now)'
        );
        $stmt->execute([
            'provider' => $providerId, 'method' => $method, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'status' => $status, 'now' => Clock::now(),
        ]);
    }

    private function setConfigStatus(int $providerId, string $method, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE provider_payment SET status = :status, updated_at = :now WHERE provider_id = :provider AND method = :method');
        $stmt->execute(['status' => $status, 'now' => Clock::now(), 'provider' => $providerId, 'method' => $method]);
    }

    // --- Payments of orders ---------------------------------------------------------

    /**
     * Starts a payment with Stripe Checkout on the provider's account.
     *
     * @return string where the buyer pays
     * @throws PaymentException
     */
    public function startStripe(array $order, App $app): string
    {
        $account = $order['provider_id'] !== null ? $this->stripeAccount($order['provider_id']) : null;
        if ($account === null || !$this->isReady($order['provider_id'], self::STRIPE)) {
            throw new PaymentException('core.payment.error.unavailable', 'Stripe: the provider has no account that can be charged');
        }

        $stripe = $this->stripe();
        $paymentId = $this->open($order['id'], self::STRIPE, $order['total'], $order['currency']);

        try {
            $session = $stripe->createCheckoutSession(
                $account,
                $order['total'],
                $order['currency'],
                $order['offer_title'] . ' – ' . $order['number'],
                $order['id'],
                $app->url('/orders/' . $order['id'] . '/payments/' . $paymentId . '/return', null, true),
                $app->url('/orders/' . $order['id'], null, true)
            );
            $url = self::redirectTarget($session['url'], self::STRIPE_CHECKOUT_HOSTS);
        } catch (PaymentException $e) {
            $this->close($paymentId, 'failed');
            throw $e;
        }

        $this->attach($paymentId, $session['id']);

        return $url;
    }

    /**
     * Starts a payment with PayPal on the provider's account.
     *
     * @return string where the buyer approves the payment
     * @throws PaymentException
     */
    public function startPaypal(array $order, App $app): string
    {
        if ($order['provider_id'] === null || !$this->isReady($order['provider_id'], self::PAYPAL)) {
            throw new PaymentException('core.payment.error.unavailable', 'PayPal: the provider has not set it up');
        }

        $paypal = $this->paypal($order['provider_id']);
        $paymentId = $this->open($order['id'], self::PAYPAL, $order['total'], $order['currency']);

        try {
            $created = $paypal->createOrder(
                $order['total'],
                $order['currency'],
                (string) $order['id'],
                $order['offer_title'] . ' – ' . $order['number'],
                $app->url('/orders/' . $order['id'] . '/payments/' . $paymentId . '/return', null, true),
                $app->url('/orders/' . $order['id'], null, true),
                bin2hex(random_bytes(16))
            );
            $url = self::redirectTarget($created['url'], self::PAYPAL_HOSTS);
        } catch (PaymentException $e) {
            $this->close($paymentId, 'failed');
            throw $e;
        }

        $this->attach($paymentId, $created['id']);

        return $url;
    }

    /**
     * Asks the service what became of a payment and, if it was paid,
     * records it on the order. For the buyer's return from the service,
     * which may arrive before, after or without the service's own
     * notification.
     *
     * @param array $payment a row of order_payment - its reference is the
     *        one stored when the payment was started, never one from a request
     * @return bool whether this payment is paid
     * @throws PaymentException
     */
    public function confirm(App $app, array $payment): bool
    {
        if ($payment['status'] === 'paid') {
            return true;
        }

        $order = $this->orders->find((int) $payment['order_id']);
        $reference = $payment['provider_reference'];
        if ($payment['status'] !== 'pending' || $reference === null || $order === null || $order['provider_id'] === null) {
            return false;
        }

        if ($payment['method'] === self::STRIPE) {
            $account = $this->stripeAccount($order['provider_id']) ?? throw new PaymentException('core.payment.error.unavailable', 'Stripe: the provider has no account');
            $session = $this->stripe()->checkoutSession($account, $reference);

            return $session['id'] === $reference && $session['paid'] && $this->settle($app, $payment, $session['amount'], $session['currency']);
        }

        if ($payment['method'] === self::PAYPAL) {
            $paypal = $this->paypal($order['provider_id']);
            $state = $paypal->order($reference);
            // Approved by the buyer, not taken yet: only now does money move.
            if ($state['status'] === 'APPROVED') {
                $state = $paypal->capture($reference);
            }

            return $state['paid'] && $state['amount'] !== null && $state['currency'] !== null
                && ($state['custom_id'] === null || $state['custom_id'] === (string) $order['id'])
                && $this->settle($app, $payment, $state['amount'], $state['currency']);
        }

        return false;
    }

    /**
     * A notification from Stripe. Nothing in it is believed before the
     * signature has been checked.
     *
     * @param string $payload the request body exactly as it arrived
     * @return int the HTTP status to answer with
     */
    public function handleStripeWebhook(App $app, string $payload, string $signatureHeader): int
    {
        $secret = $this->stripeWebhookSecret();
        $event = $secret !== null ? StripeGateway::verifyWebhook($payload, $signatureHeader, $secret) : null;
        if ($event === null) {
            return 400;
        }

        // Other parts of the site (subscriptions) act on the same verified events.
        foreach ($this->stripeListeners as $listener) {
            $listener($event);
        }

        // The second one reports payments that complete later (a bank debit).
        if (!in_array($event['type'] ?? null, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)
            || !is_array($event['data']['object'] ?? null)) {
            return 200;
        }

        $session = StripeGateway::sessionSummary($event['data']['object']);
        $payment = $session['id'] !== '' ? $this->findByReference(self::STRIPE, $session['id']) : null;
        if ($payment !== null && $session['paid']) {
            $this->settle($app, $payment, $session['amount'], $session['currency']);
        }

        // Also for a session this site does not know: asking Stripe to
        // send it again would not change that.
        return 200;
    }

    /** @param Closure(array): void $listener called with every verified Stripe event, before the orders are looked at */
    public function onStripeEvent(Closure $listener): void
    {
        $this->stripeListeners[] = $listener;
    }

    /**
     * The address of a Stripe checkout for a subscription, on the platform's
     * own account (no connected account: the operator is the seller).
     */
    public function startSubscriptionCheckout(int $amount, string $currency, int $months, string $productName, string $reference, string $successUrl, string $cancelUrl): array
    {
        $session = $this->stripe()->createSubscriptionCheckout($amount, $currency, $months, $productName, $reference, $successUrl, $cancelUrl);

        return ['id' => $session['id'], 'url' => self::redirectTarget($session['url'], self::STRIPE_CHECKOUT_HOSTS)];
    }

    public function cancelStripeSubscription(string $subscription): void
    {
        $this->stripe()->cancelSubscription($subscription);
    }

    /**
     * Records that a payment arrived - once. Amount and currency reported
     * by the service must be those of the order; the change of state is a
     * conditional update, so the buyer's return and the service's
     * notification arriving together cannot both act.
     *
     * @return bool whether this payment is paid
     */
    public function settle(App $app, array $payment, int $amount, string $currency): bool
    {
        $order = $this->orders->find((int) $payment['order_id']);
        if ($order === null) {
            return false;
        }

        $currency = strtoupper($currency);
        if ($amount !== (int) $payment['amount'] || $amount !== $order['total'] || $currency !== $payment['currency'] || $currency !== $order['currency']) {
            error_log('Payment ' . (int) $payment['id'] . ' of order ' . $order['number'] . ' was reported with another amount or currency and is not recorded as paid');

            return false;
        }

        $this->db->beginTransaction();
        $stmt = $this->db->prepare("UPDATE order_payment SET status = 'paid', updated_at = :now WHERE id = :id AND status = 'pending'");
        $stmt->execute(['now' => Clock::now(), 'id' => $payment['id']]);

        if ($stmt->rowCount() !== 1) {
            $this->db->rollBack();

            return ($this->find((int) $payment['id'])['status'] ?? null) === 'paid';
        }

        // The buyer may have chosen another way to pay meanwhile and then
        // paid in the window that was still open: the order shows how it
        // was paid in fact.
        $this->orders->setPaymentMethod($order['id'], (string) $payment['method']);
        $marked = $this->orders->markPaid($order['id']);
        $this->db->commit();

        if ($marked) {
            OrderNotifier::paid($app, $this->orders->find($order['id']));
        } else {
            error_log('Order ' . $order['number'] . ' was already paid when payment ' . (int) $payment['id'] . ' arrived: the buyer may have paid twice');
        }

        return true;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM order_payment WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function findByReference(string $method, string $reference): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM order_payment WHERE method = :method AND provider_reference = :reference');
        $stmt->execute(['method' => $method, 'reference' => $reference]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array> the payments started for an order, newest first */
    public function ofOrder(int $orderId): array
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM order_payment WHERE order_id = :order ORDER BY id DESC');
            $stmt->execute(['order' => $orderId]);

            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return self::unlessTableMissing($e) ?: [];
        }
    }

    /**
     * The newest payment of an order that a service may still report as
     * paid: looked at before a new one is started, so that nobody pays
     * twice because a return never arrived.
     */
    public function newestPending(int $orderId): ?array
    {
        foreach ($this->ofOrder($orderId) as $payment) {
            if ($payment['status'] === 'pending' && $payment['provider_reference'] !== null) {
                return $payment;
            }
        }

        return null;
    }

    private function open(int $orderId, string $method, int $amount, string $currency): int
    {
        $now = Clock::now();
        $stmt = $this->db->prepare(
            "INSERT INTO order_payment (order_id, method, provider_reference, status, amount, currency, created_at, updated_at)
             VALUES (:order, :method, NULL, 'pending', :amount, :currency, :now, :now2)"
        );
        $stmt->execute(['order' => $orderId, 'method' => $method, 'amount' => $amount, 'currency' => $currency, 'now' => $now, 'now2' => $now]);

        return (int) $this->db->lastInsertId();
    }

    /** Stores the service's id of a payment: from now on the service is asked about this id and no other. */
    private function attach(int $id, string $reference): void
    {
        $stmt = $this->db->prepare("UPDATE order_payment SET provider_reference = :reference, updated_at = :now WHERE id = :id AND status = 'pending'");
        $stmt->execute(['reference' => $reference, 'now' => Clock::now(), 'id' => $id]);
    }

    private function close(int $id, string $status): void
    {
        $stmt = $this->db->prepare("UPDATE order_payment SET status = :status, updated_at = :now WHERE id = :id AND status = 'pending'");
        $stmt->execute(['status' => $status, 'now' => Clock::now(), 'id' => $id]);
    }

    /**
     * An address a service returned is only followed if it is the
     * service's own page, over HTTPS. These hosts are also the ones the
     * content security policy lets a form lead to (public/index.php).
     *
     * @param string[] $hosts
     */
    private static function redirectTarget(string $url, array $hosts): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower($parts['host'] ?? ''), $hosts, true)
            || isset($parts['user']) || preg_match('/[\x00-\x20]/', $url) === 1) {
            throw new PaymentException('core.payment.error.unexpected', 'A payment service named an address on an unexpected host');
        }

        return $url;
    }

    /**
     * 42S02: the tables arrive with a migration that has not run yet (right
     * after an update's files were copied). Order pages and the data export
     * must keep working until it has.
     */
    private static function unlessTableMissing(PDOException $e): false
    {
        return $e->getCode() === '42S02' ? false : throw $e;
    }

    /** For a controller: writes down what failed, without anything secret, and returns what to tell the person. */
    public static function report(RuntimeException $e): string
    {
        error_log('Payment: ' . $e->getMessage());

        return $e instanceof PaymentException ? $e->messageKey : 'core.payment.error.unexpected';
    }
}
