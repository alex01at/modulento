<?php

declare(strict_types=1);

namespace Modulento\Core\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Modulento\Core\Payment\BankAccount;
use Modulento\Core\Payment\PaymentException;
use Modulento\Core\Payment\Payments;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Modules;
use Modulento\Core\Support\Settings;
use PDO;
use PDOException;

/**
 * How an account pays for a plan: by bank transfer to the operator's account,
 * confirmed by hand once the money is there, or by Stripe on the platform's own
 * account. Unlike orders, the money of a subscription belongs to the operator,
 * so it goes to the operator's account and never to a provider.
 */
final class SubscriptionBilling
{
    private const BANK = 'core.subscriptions.bank';

    public function __construct(
        private PDO $db,
        private Settings $settings,
        private Modules $modules,
        private Subscriptions $subscriptions,
        private Payments $payments
    ) {
    }

    // --- The operator's bank account ----------------------------------------------

    /** @return array{holder: string, iban: string, bic: string}|null */
    public function bank(): ?array
    {
        $stored = $this->settings->get(self::BANK);
        $bank = $stored !== '' ? json_decode($stored, true) : null;

        return is_array($bank) && is_string($bank['iban'] ?? null) ? $bank : null;
    }

    /** Empty fields remove the account. The BIC is optional; the IBAN is checked. */
    public function saveBank(string $holder, string $iban, string $bic): void
    {
        $holder = trim($holder);
        $iban = BankAccount::normalize($iban);
        $bic = BankAccount::normalize($bic);

        if ($holder === '' && $iban === '' && $bic === '') {
            $this->settings->set(self::BANK, '');

            return;
        }
        if ($holder === '' || mb_strlen($holder) > 100 || !BankAccount::isIban($iban) || ($bic !== '' && !BankAccount::isBic($bic))) {
            throw new InvalidArgumentException('bank: holder, iban or bic');
        }

        $this->settings->set(self::BANK, (string) json_encode(['holder' => $holder, 'iban' => $iban, 'bic' => $bic], JSON_UNESCAPED_UNICODE));
    }

    // --- Which ways are offered -----------------------------------------------------

    /** @return array{transfer: bool, stripe: bool} the ways an account can pay for a plan now */
    public function methods(): array
    {
        return [
            'transfer' => $this->payments->isEnabled(Payments::TRANSFER) && $this->bank() !== null,
            'stripe' => $this->payments->isEnabled(Payments::STRIPE) && $this->payments->stripeConfigured(),
        ];
    }

    // --- Orders -----------------------------------------------------------------------

    /** An order to pay by transfer; the page shows the account and the reference. */
    public function startTransfer(int $accountId, int $planId): array
    {
        return $this->createOrder($accountId, $planId, 'transfer');
    }

    /** An order paid by Stripe: the address of the checkout the buyer goes to. */
    public function startStripe(int $accountId, int $planId, string $successUrl, string $cancelUrl): string
    {
        $order = $this->createOrder($accountId, $planId, 'stripe');
        $plan = $this->subscriptions->plan($planId);

        try {
            $session = $this->payments->startSubscriptionCheckout($order['amount_cents'], $order['currency'], $plan['period_months'], $plan['name'], $order['reference'], $successUrl, $cancelUrl);
        } catch (PaymentException $e) {
            $this->setOrderStatus((int) $order['id'], 'canceled');
            throw $e;
        }

        $this->db->prepare('UPDATE subscription_order SET stripe_session = :session WHERE id = :id')->execute(['session' => $session['id'], 'id' => $order['id']]);

        return $session['url'];
    }

    /** One order of the account, or null if it is not the account's. */
    public function orderOf(int $accountId, int $orderId): ?array
    {
        $stmt = $this->db->prepare($this->orderSql() . ' WHERE o.id = :id AND o.account_id = :account');
        $stmt->execute(['id' => $orderId, 'account' => $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->order($row);
    }

    /** The transfers still waiting for their money, for the administration. */
    public function pendingTransfers(): array
    {
        return array_map(fn (array $row) => $this->order($row), $this->db->query($this->orderSql() . " WHERE o.method = 'transfer' AND o.status = 'pending' ORDER BY o.id")->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The operator confirms that the money of a transfer has arrived: the plan is
     * given to the account for its months, from now.
     */
    public function confirmTransfer(int $orderId): void
    {
        $order = $this->orderById($orderId);
        if ($order === null || $order['method'] !== 'transfer' || $order['status'] !== 'pending') {
            throw new InvalidArgumentException('order: not a pending transfer');
        }

        $this->db->beginTransaction();
        try {
            if ($this->setOrderStatus($orderId, 'paid') !== 1) {
                $this->db->rollBack();
                throw new InvalidArgumentException('order: already settled');
            }
            $this->subscriptions->assign($order['account_id'], $order['plan_id'], Subscriptions::addMonths(Clock::now(), $order['months']));
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Stops the renewal of the account's Stripe subscription; it runs to the end of the period. */
    public function cancelOwn(int $accountId): bool
    {
        $current = $this->subscriptions->current($accountId);
        if ($current === null || $current['provider_ref'] === null) {
            return false;
        }

        $this->payments->cancelStripeSubscription($current['provider_ref']);

        return true;
    }

    // --- Stripe's notifications -------------------------------------------------------

    /**
     * Called with every verified notification. Only the ones about subscriptions
     * are acted on, and only while the module is on.
     */
    public function stripeEvent(array $event): void
    {
        if (!$this->modules->enabled('subscriptions')) {
            return;
        }

        $type = $event['type'] ?? null;
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];

        if ($type === 'checkout.session.completed' && ($object['mode'] ?? null) === 'subscription') {
            $this->stripeSessionPaid($object);
        } elseif ($type === 'invoice.paid' && ($object['billing_reason'] ?? null) === 'subscription_cycle' && is_string($object['subscription'] ?? null)) {
            // The first invoice is the checkout's payment, handled above.
            $row = $this->subscriptions->byProviderRef($object['subscription']);
            if ($row !== null) {
                $this->subscriptions->extend($row['id'], $row['months']);
            }
        } elseif ($type === 'invoice.payment_failed' && is_string($object['subscription'] ?? null)) {
            $this->subscriptions->setStatusByProviderRef($object['subscription'], 'past_due');
        } elseif ($type === 'customer.subscription.deleted' && is_string($object['id'] ?? null)) {
            $this->subscriptions->setStatusByProviderRef($object['id'], 'canceled');
        }
    }

    private function stripeSessionPaid(array $session): void
    {
        $reference = $session['metadata']['subscription_order'] ?? $session['client_reference_id'] ?? null;
        $order = is_string($reference) ? $this->orderByReference($reference) : null;
        if ($order === null || $order['method'] !== 'stripe' || $order['status'] !== 'pending' || ($session['payment_status'] ?? null) !== 'paid') {
            return;
        }
        $currency = strtoupper((string) ($session['currency'] ?? ''));
        if (($session['amount_total'] ?? null) !== $order['amount_cents'] || $currency !== $order['currency']) {
            error_log('Subscription order ' . $order['reference'] . ' was reported with another amount or currency and is not recorded as paid');

            return;
        }

        $this->db->beginTransaction();
        try {
            if ($this->setOrderStatus((int) $order['id'], 'paid') !== 1) {
                $this->db->rollBack();

                return;
            }
            $subscription = is_string($session['subscription'] ?? null) ? $session['subscription'] : null;
            $this->subscriptions->assign($order['account_id'], $order['plan_id'], Subscriptions::addMonths(Clock::now(), $order['months']), $subscription);
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // --- internals -----------------------------------------------------------------------

    private function createOrder(int $accountId, int $planId, string $method): array
    {
        $plan = $this->subscriptions->plan($planId);
        if ($plan === null || !$plan['active']) {
            throw new InvalidArgumentException('order: unknown or inactive plan');
        }

        for ($try = 0; ; $try++) {
            $reference = 'ABO-' . strtoupper(bin2hex(random_bytes(4)));
            try {
                $this->db->prepare("INSERT INTO subscription_order (account_id, plan_id, method, status, reference, amount_cents, currency, created_at) VALUES (:account, :plan, :method, 'pending', :reference, :amount, :currency, :now)")
                    ->execute(['account' => $accountId, 'plan' => $planId, 'method' => $method, 'reference' => $reference, 'amount' => $plan['price_cents'], 'currency' => $plan['currency'], 'now' => Clock::now()]);
                break;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000' || $try >= 4) {
                    throw $e;
                }
            }
        }

        return $this->orderById((int) $this->db->lastInsertId());
    }

    private function setOrderStatus(int $orderId, string $status): int
    {
        $stmt = $this->db->prepare("UPDATE subscription_order SET status = :status, paid_at = :paid WHERE id = :id AND status = 'pending'");
        $stmt->execute(['status' => $status, 'paid' => $status === 'paid' ? Clock::now() : null, 'id' => $orderId]);

        return $stmt->rowCount();
    }

    private function orderById(int $id): ?array
    {
        $stmt = $this->db->prepare($this->orderSql() . ' WHERE o.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->order($row);
    }

    private function orderByReference(string $reference): ?array
    {
        $stmt = $this->db->prepare($this->orderSql() . ' WHERE o.reference = :reference');
        $stmt->execute(['reference' => $reference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->order($row);
    }

    private function orderSql(): string
    {
        return 'SELECT o.id, o.account_id, o.plan_id, o.method, o.status, o.reference, o.amount_cents, o.currency, o.created_at, o.paid_at,
                       a.email, p.name AS plan_name, p.period_months, p.slug
                FROM subscription_order o
                JOIN account a ON a.id = o.account_id
                JOIN subscription_plan p ON p.id = o.plan_id';
    }

    private function order(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'account_id' => (int) $row['account_id'],
            'plan_id' => (int) $row['plan_id'],
            'method' => $row['method'],
            'status' => $row['status'],
            'reference' => $row['reference'],
            'amount_cents' => (int) $row['amount_cents'],
            'currency' => $row['currency'],
            'created_at' => $row['created_at'],
            'paid_at' => $row['paid_at'],
            'email' => $row['email'],
            'plan_name' => $row['plan_name'],
            'months' => (int) $row['period_months'],
        ];
    }
}
