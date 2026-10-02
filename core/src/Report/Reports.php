<?php

declare(strict_types=1);

namespace Modulento\Core\Report;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * Notices about content someone considers illegal, and the decision on
 * each. A notice is a record: acting on the content itself (pausing an
 * offer, suspending a provider, hiding a review) is done where that
 * content is administered, and the decision here says what was done.
 */
final class Reports
{
    public const CATEGORIES = ['illegal_goods', 'intellectual_property', 'fraud', 'hate', 'privacy', 'minors', 'other'];
    public const DECISIONS = ['actioned', 'rejected'];

    public function __construct(private PDO $db)
    {
    }

    /** @param array{url: string, category: string, explanation: string, name: string, email: string, locale: string, account_id: ?int, offer_id: ?int, provider_id: ?int} $fields */
    public function create(array $fields, string $receivedAt): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO report (url, category, explanation, name, email, locale, account_id, offer_id, provider_id, status, created_at)
             VALUES (:url, :category, :explanation, :name, :email, :locale, :account_id, :offer_id, :provider_id, 'open', :now)"
        );
        $stmt->execute($fields + ['now' => $receivedAt]);

        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM report WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** @return array{rows: array<int, array>, total: int} open notices first, then the newest */
    public function list(int $page, int $perPage): array
    {
        $total = (int) $this->db->query('SELECT COUNT(*) FROM report')->fetchColumn();
        $rows = $this->db->query(
            "SELECT * FROM report ORDER BY (status <> 'open'), id DESC LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, (min($page, 100000) - 1) * $perPage)
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }

    public function openCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM report WHERE status = 'open'")->fetchColumn();
    }

    /** Decides an open notice. Returns false if it was decided meanwhile. */
    public function decide(int $id, string $decision, string $note, int $by): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE report SET status = :status, decision_note = :note, decided_at = :now, decided_by = :by WHERE id = :id AND status = 'open'"
        );
        $stmt->execute(['status' => $decision, 'note' => $note, 'now' => Clock::now(), 'by' => $by, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** @return array<int, array> what an account reported, for its data export */
    public function byAccount(int $accountId): array
    {
        $stmt = $this->db->prepare(
            'SELECT url, category, explanation, name, email, status, decision_note, decided_at, created_at FROM report WHERE account_id = :id ORDER BY id'
        );
        $stmt->execute(['id' => $accountId]);

        return $stmt->fetchAll();
    }
}
