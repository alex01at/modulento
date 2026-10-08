<?php

declare(strict_types=1);

namespace Modulento\Core\Request;

use Modulento\Core\Content\Pages;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Settings;
use PDO;

/**
 * The other direction from the catalogue: a buyer describes what they need
 * instead of a provider listing what they sell. A request is public only
 * while it is "published" and its account is active - PUBLIC_WHERE is that
 * rule, every query for visitors uses it.
 */
final class Requests
{
    public const SETTING_APPROVAL = 'core.request_approval';
    public const STATUSES = ['pending', 'published', 'rejected', 'closed', 'fulfilled'];

    private const PUBLIC_WHERE = "r.status = 'published' AND a.status = 'active'";
    private const FROM = 'FROM request r JOIN account a ON a.id = r.account_id';

    public function __construct(private PDO $db, private Settings $settings)
    {
    }

    public function approvalRequired(): bool
    {
        return $this->settings->get(self::SETTING_APPROVAL, 'required') !== 'off';
    }

    public function currency(): string
    {
        return $this->settings->get('core.currency', 'EUR');
    }

    /**
     * Switching approval off publishes every request that was waiting.
     *
     * @return int[] ids of the requests published by this call
     */
    public function setApprovalRequired(bool $required): array
    {
        $this->settings->set(self::SETTING_APPROVAL, $required ? 'required' : 'off');
        if ($required) {
            return [];
        }

        $waiting = array_map('intval', $this->db->query("SELECT id FROM request WHERE status = 'pending'")->fetchAll(PDO::FETCH_COLUMN));
        foreach ($waiting as $id) {
            $this->setStatus($id, 'published', null, null);
        }

        return $waiting;
    }

    /**
     * @param int|null $budgetMin,$budgetMax minor units
     * @return int the new request's id
     */
    public function create(int $accountId, ?int $categoryId, string $title, string $description, ?int $budgetMin, ?int $budgetMax, ?string $neededBy): int
    {
        $now = Clock::now();
        $status = $this->approvalRequired() ? 'pending' : 'published';
        $stmt = $this->db->prepare(
            "INSERT INTO request (account_id, category_id, title, slug, description, budget_min, budget_max, currency, needed_by, status, published_at, created_at, updated_at)
             VALUES (:account, :category, :title, :slug, :description, :budget_min, :budget_max, :currency, :needed_by, :status, :published_at, :now, :now2)"
        );
        $stmt->execute([
            'account' => $accountId, 'category' => $categoryId, 'title' => $title, 'slug' => $this->uniqueSlug($title),
            'description' => $description, 'budget_min' => $budgetMin, 'budget_max' => $budgetMax, 'currency' => $this->currency(),
            'needed_by' => $neededBy, 'status' => $status, 'published_at' => $status === 'published' ? $now : null,
            'now' => $now, 'now2' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Saving never changes the status of an existing request (same convention as Catalogue\Offers::save()). */
    public function update(int $id, ?int $categoryId, string $title, string $description, ?int $budgetMin, ?int $budgetMax, ?string $neededBy): void
    {
        $stmt = $this->db->prepare(
            'UPDATE request SET category_id = :category, title = :title, description = :description,
                budget_min = :budget_min, budget_max = :budget_max, needed_by = :needed_by, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'category' => $categoryId, 'title' => $title, 'description' => $description,
            'budget_min' => $budgetMin, 'budget_max' => $budgetMax, 'needed_by' => $neededBy, 'now' => Clock::now(), 'id' => $id,
        ]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, a.email AS account_email, a.locale AS account_locale, a.status AS account_status '
            . self::FROM . ' WHERE r.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->cast($row) : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, a.email AS account_email, a.locale AS account_locale, a.status AS account_status '
            . self::FROM . ' WHERE r.slug = :slug'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row ? $this->cast($row) : null;
    }

    public function isPublic(array $request): bool
    {
        return $request['status'] === 'published' && $request['account_status'] === 'active';
    }

    /**
     * Requests for visitors.
     *
     * @param array{category_ids?: int[]} $filter
     * @return array{rows: array<int, array>, total: int}
     */
    public function listPublic(array $filter, int $page, int $perPage): array
    {
        $where = [self::PUBLIC_WHERE];
        $params = [];
        if (($filter['category_ids'] ?? []) !== []) {
            $where[] = 'r.category_id IN (' . $this->placeholders('cat', $filter['category_ids'], $params) . ')';
        }
        $whereSql = implode(' AND ', $where);

        $count = $this->db->prepare('SELECT COUNT(*) ' . self::FROM . " WHERE {$whereSql}");
        $count->execute($params);

        $stmt = $this->db->prepare(
            'SELECT r.*, a.email AS account_email, a.locale AS account_locale, a.status AS account_status '
            . self::FROM . " WHERE {$whereSql} ORDER BY r.published_at DESC, r.id DESC LIMIT " . max(1, $perPage)
            . ' OFFSET ' . max(0, (min($page, 100000) - 1) * $perPage)
        );
        $stmt->execute($params);

        return ['rows' => array_map(fn (array $r) => $this->cast($r), $stmt->fetchAll()), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array{rows: array<int, array>, total: int} */
    public function listAll(?int $accountId, ?string $status, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($accountId !== null) {
            $where[] = 'r.account_id = :account';
            $params['account'] = $accountId;
        }
        if ($status !== null) {
            $where[] = 'r.status = :status';
            $params['status'] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $count = $this->db->prepare('SELECT COUNT(*) ' . self::FROM . " WHERE {$whereSql}");
        $count->execute($params);

        $stmt = $this->db->prepare(
            'SELECT r.*, a.email AS account_email, a.locale AS account_locale, a.status AS account_status '
            . self::FROM . " WHERE {$whereSql} ORDER BY r.updated_at DESC, r.id DESC LIMIT " . max(1, $perPage)
            . ' OFFSET ' . max(0, (min($page, 100000) - 1) * $perPage)
        );
        $stmt->execute($params);

        return ['rows' => array_map(fn (array $r) => $this->cast($r), $stmt->fetchAll()), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array<string, int> status => count, for the administration's tabs */
    public function counts(): array
    {
        $rows = $this->db->query('SELECT status, COUNT(*) AS n FROM request GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

        return array_map('intval', $rows);
    }

    public function setStatus(int $id, string $status, ?string $note, ?int $decidedBy): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }

        $now = Clock::now();
        $set = ['status = :status', 'status_note = :note', 'updated_at = :now'];
        $params = ['status' => $status, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'now' => $now, 'id' => $id];

        if ($status === 'published') {
            $set[] = 'published_at = COALESCE(published_at, :published)';
            $params['published'] = $now;
        }
        if ($decidedBy !== null) {
            $set[] = 'decided_at = :decided_at';
            $set[] = 'decided_by = :decided_by';
            $params += ['decided_at' => $now, 'decided_by' => $decidedBy];
        }

        $stmt = $this->db->prepare('UPDATE request SET ' . implode(', ', $set) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    /** An application was accepted: the request is done, pointing at the application and the order it became. */
    public function fulfil(int $id, int $applicationId, int $orderId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE request SET status = 'fulfilled', accepted_application_id = :application, order_id = :order, updated_at = :now WHERE id = :id"
        );
        $stmt->execute(['application' => $applicationId, 'order' => $orderId, 'now' => Clock::now(), 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM request WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['account_id'] = (int) $row['account_id'];
        $row['category_id'] = $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $row['budget_min'] = $row['budget_min'] !== null ? (int) $row['budget_min'] : null;
        $row['budget_max'] = $row['budget_max'] !== null ? (int) $row['budget_max'] : null;
        $row['decided_by'] = $row['decided_by'] !== null ? (int) $row['decided_by'] : null;
        $row['accepted_application_id'] = $row['accepted_application_id'] !== null ? (int) $row['accepted_application_id'] : null;
        $row['order_id'] = $row['order_id'] !== null ? (int) $row['order_id'] : null;

        return $row;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Pages::slugify($title) ?: 'request';
        $slug = $base;
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM request WHERE slug = :slug');

        for ($suffix = 2; ; $suffix++) {
            $stmt->execute(['slug' => $slug]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $suffix;
        }
    }

    private function placeholders(string $prefix, array $values, array &$params): string
    {
        $names = [];
        foreach (array_values($values) as $i => $value) {
            $names[] = ':' . $prefix . $i;
            $params[$prefix . $i] = $value;
        }

        return implode(', ', $names);
    }
}
