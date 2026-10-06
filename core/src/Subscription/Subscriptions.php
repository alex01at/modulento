<?php

declare(strict_types=1);

namespace Modulento\Core\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Modules;
use PDO;
use PDOException;

/**
 * Plans an operator sells, and the plan an account has now. An extension asks
 * allows($account, 'feature') for a feature it sells; with the module switched
 * off every feature is allowed, so the feature is open as if nothing was sold.
 * Only a feature that a plan lists is granted to an account with that plan.
 */
final class Subscriptions
{
    /** Statuses that still count: past due counts until its period is over. */
    private const COUNTING = ['trialing', 'active', 'past_due'];

    /** @var array<string, string> feature key => language key of its name, declared by extensions */
    private array $declared = [];

    public function __construct(private PDO $db, private Modules $modules)
    {
    }

    /** An extension declares a feature it sells, so that a plan can include it by choice. */
    public function declareFeature(string $key, string $labelKey): void
    {
        if (preg_match('/^[a-z0-9._-]{1,60}$/', $key) !== 1) {
            throw new InvalidArgumentException('feature: key');
        }
        $this->declared[$key] = $labelKey;
    }

    /** @return array<string, string> */
    public function declaredFeatures(): array
    {
        return $this->declared;
    }

    /** The plans that can be booked now, for the public overview. */
    public function activePlans(): array
    {
        return array_values(array_filter($this->plans(), fn (array $plan) => $plan['active']));
    }

    public function plan(int $id): ?array
    {
        foreach ($this->plans() as $plan) {
            if ($plan['id'] === $id) {
                return $plan;
            }
        }

        return null;
    }

    /** @return list<array{id: int, slug: string, name: string, price_cents: int, currency: string, period_months: int, features: list<string>, active: bool}> */
    public function plans(): array
    {
        $rows = $this->db->query('SELECT * FROM subscription_plan ORDER BY price_cents, id')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'name' => $row['name'],
            'price_cents' => (int) $row['price_cents'],
            'currency' => $row['currency'],
            'period_months' => (int) $row['period_months'],
            'features' => $this->features($row['features']),
            'active' => (bool) $row['active'],
        ], $rows);
    }

    /** @param list<string> $features */
    public function createPlan(string $slug, string $name, int $priceCents, string $currency, int $periodMonths, array $features): int
    {
        if (preg_match('/^[a-z0-9-]{1,40}$/', $slug) !== 1) {
            throw new InvalidArgumentException('plan: slug');
        }
        $values = $this->validated($name, $priceCents, $currency, $periodMonths, $features);

        try {
            $stmt = $this->db->prepare('INSERT INTO subscription_plan (slug, name, price_cents, currency, period_months, features) VALUES (:slug, :name, :price, :currency, :period, :features)');
            $stmt->execute(['slug' => $slug] + $values);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new InvalidArgumentException('plan: slug taken', 0, $e);
            }
            throw $e;
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Changes a plan. The key stays; a plan that is no longer active is not offered
     * any more, but the accounts that have it keep their features until it ends.
     *
     * @param list<string> $features
     */
    public function updatePlan(int $id, string $name, int $priceCents, string $currency, int $periodMonths, array $features, bool $active): void
    {
        $values = $this->validated($name, $priceCents, $currency, $periodMonths, $features);
        $stmt = $this->db->prepare('UPDATE subscription_plan SET name = :name, price_cents = :price, currency = :currency, period_months = :period, features = :features, active = :active WHERE id = :id');
        $stmt->execute($values + ['active' => $active ? 1 : 0, 'id' => $id]);
    }

    /** Deletes a plan that no account has had. A plan with a history can only be switched off. */
    public function deletePlan(int $id): void
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM subscription WHERE plan_id = :id');
        $stmt->execute(['id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('plan: in use');
        }
        $this->db->prepare('DELETE FROM subscription_plan WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Gives the account a plan from now on, or none with null. The subscription
     * it had is cancelled, not deleted. Without an end the plan is open-ended,
     * as when an operator grants it by hand.
     */
    public function assign(int $accountId, ?int $planId, ?string $periodEnd = null, ?string $providerRef = null): void
    {
        if ($periodEnd !== null && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $periodEnd) !== 1) {
            throw new InvalidArgumentException('subscription: period end');
        }
        if ($planId !== null && !$this->planIsActive($planId)) {
            throw new InvalidArgumentException('subscription: unknown or inactive plan');
        }

        $now = Clock::now();
        // Inside a transaction of the caller (an order settled by its payment), this one joins it.
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare("UPDATE subscription SET status = 'canceled', updated_at = :now WHERE account_id = :account AND status <> 'canceled'")
                ->execute(['now' => $now, 'account' => $accountId]);
            if ($planId !== null) {
                $this->db->prepare("INSERT INTO subscription (account_id, plan_id, status, period_end, provider_ref, created_at, updated_at) VALUES (:account, :plan, 'active', :end, :ref, :created, :updated)")
                    ->execute(['account' => $accountId, 'plan' => $planId, 'end' => $periodEnd, 'ref' => $providerRef, 'created' => $now, 'updated' => $now]);
            }
            if ($own) {
                $this->db->commit();
            }
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** The newest subscriptions of all accounts, for the administration. */
    public function recent(int $limit): array
    {
        $rows = $this->db->query(
            'SELECT s.id, s.status, s.period_end, s.created_at, a.email, p.name AS plan_name
             FROM subscription s JOIN account a ON a.id = s.account_id JOIN subscription_plan p ON p.id = s.plan_id
             ORDER BY s.id DESC LIMIT ' . max(1, $limit)
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'status' => $row['status'],
            'period_end' => $row['period_end'],
            'created_at' => $row['created_at'],
            'email' => $row['email'],
            'plan_name' => $row['plan_name'],
        ], $rows);
    }

    /** The subscription that counts for the account now, or null. */
    public function current(int $accountId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.id, s.status, s.period_end, s.provider_ref, p.slug, p.name, p.features
             FROM subscription s JOIN subscription_plan p ON p.id = s.plan_id
             WHERE s.account_id = :account AND s.status IN ('trialing', 'active', 'past_due')
               AND (s.period_end IS NULL OR s.period_end > :now)
             ORDER BY s.id DESC LIMIT 1"
        );
        $stmt->execute(['account' => $accountId, 'now' => Clock::now()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'status' => $row['status'],
            'period_end' => $row['period_end'],
            'provider_ref' => $row['provider_ref'],
            'slug' => $row['slug'],
            'name' => $row['name'],
            'features' => $this->features($row['features']),
        ];
    }

    /** Whether the account may use the feature. Switched off, the module allows everything. */
    public function allows(int $accountId, string $feature): bool
    {
        if (!$this->modules->enabled('subscriptions')) {
            return true;
        }

        $current = $this->current($accountId);

        return $current !== null && in_array($feature, $current['features'], true);
    }

    /**
     * @param list<string> $features
     * @return array{name: string, price: int, currency: string, period: int, features: string}
     */
    private function validated(string $name, int $priceCents, string $currency, int $periodMonths, array $features): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('plan: name');
        }
        if ($priceCents < 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || $periodMonths < 1 || $periodMonths > 36) {
            throw new InvalidArgumentException('plan: price, currency or period');
        }
        if (count($features) > 20 || array_filter($features, fn ($f) => !is_string($f) || preg_match('/^[a-z0-9._-]{1,60}$/', $f) !== 1) !== []) {
            throw new InvalidArgumentException('plan: features');
        }

        return ['name' => $name, 'price' => $priceCents, 'currency' => $currency, 'period' => $periodMonths, 'features' => implode(',', array_values(array_unique($features)))];
    }

    /** The subscription a Stripe subscription id belongs to, with the months of its plan. */
    public function byProviderRef(string $ref): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.account_id, s.status, s.period_end, p.period_months, p.name AS plan_name, p.currency
             FROM subscription s JOIN subscription_plan p ON p.id = s.plan_id WHERE s.provider_ref = :ref ORDER BY s.id DESC LIMIT 1'
        );
        $stmt->execute(['ref' => $ref]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['id' => (int) $row['id'], 'account_id' => (int) $row['account_id'], 'status' => $row['status'], 'period_end' => $row['period_end'], 'months' => (int) $row['period_months'], 'plan_name' => $row['plan_name']];
    }

    /** A paid renewal: the subscription runs on for the months of its plan, from its end or from now. */
    public function extend(int $subscriptionId, int $months): void
    {
        $stmt = $this->db->prepare('SELECT period_end FROM subscription WHERE id = :id');
        $stmt->execute(['id' => $subscriptionId]);
        $end = $stmt->fetchColumn();
        $from = is_string($end) && $end > Clock::now() ? $end : Clock::now();

        $this->db->prepare("UPDATE subscription SET status = 'active', period_end = :end, updated_at = :now WHERE id = :id")
            ->execute(['end' => self::addMonths($from, $months), 'now' => Clock::now(), 'id' => $subscriptionId]);
    }

    /** Stripe reports a change of the subscription's state (past due, cancelled). */
    public function setStatusByProviderRef(string $ref, string $status): void
    {
        $this->db->prepare('UPDATE subscription SET status = :status, updated_at = :now WHERE provider_ref = :ref AND status <> :canceled')
            ->execute(['status' => $status, 'now' => Clock::now(), 'ref' => $ref, 'canceled' => 'canceled']);
    }

    /** A UTC time of the form Y-m-d H:i:s, moved by whole months. */
    public static function addMonths(string $from, int $months): string
    {
        return (new DateTimeImmutable($from, new DateTimeZone('UTC')))->modify('+' . $months . ' months')->format('Y-m-d H:i:s');
    }

    private function planIsActive(int $planId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM subscription_plan WHERE id = :id AND active = 1');
        $stmt->execute(['id' => $planId]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /** @return list<string> */
    private function features(string $list): array
    {
        return array_values(array_filter(explode(',', $list), fn (string $f) => $f !== ''));
    }
}
