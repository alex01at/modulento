<?php

declare(strict_types=1);

namespace Modulento\Core\Order;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * Declarations of withdrawal as they arrived through the withdrawal form.
 *
 * This is a record, not a decision: whether a right of withdrawal exists is
 * for the provider to say, so nothing here touches the state of an order.
 */
final class Withdrawals
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array{order_id: ?int, order_number: string, name: string, email: string, statement: string, locale: string, account_id: ?int} $fields
     * @param string|null $receivedAt the moment named in the acknowledgement, so both say the same
     */
    public function create(array $fields, ?string $receivedAt = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO withdrawal (order_id, order_number, name, email, statement, locale, account_id, matched, created_at)
             VALUES (:order_id, :order_number, :name, :email, :statement, :locale, :account_id, :matched, :now)'
        );
        $stmt->execute([
            'order_id' => $fields['order_id'],
            'order_number' => $fields['order_number'],
            'name' => $fields['name'],
            'email' => $fields['email'],
            'statement' => $fields['statement'],
            'locale' => $fields['locale'],
            'account_id' => $fields['account_id'],
            'matched' => $fields['order_id'] !== null ? 1 : 0,
            'now' => $receivedAt ?? Clock::now(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array{rows: array<int, array>, total: int} newest first, for the administration */
    public function list(int $page, int $perPage): array
    {
        $total = (int) $this->db->query('SELECT COUNT(*) FROM withdrawal')->fetchColumn();
        $rows = $this->db->query(
            'SELECT * FROM withdrawal ORDER BY id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, (min($page, 100000) - 1) * $perPage)
        )->fetchAll();

        return ['rows' => array_map([self::class, 'typed'], $rows), 'total' => $total];
    }

    /** Records that an administrator has dealt with a declaration, or takes that back. */
    public function setHandled(int $id, bool $handled, ?int $by): void
    {
        $stmt = $this->db->prepare('UPDATE withdrawal SET handled_at = :at, handled_by = :by WHERE id = :id');
        $stmt->execute(['at' => $handled ? Clock::now() : null, 'by' => $handled ? $by : null, 'id' => $id]);
    }

    /** How many declarations wait for an administrator: not assigned to an order and not dealt with. */
    public function waiting(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM withdrawal WHERE order_id IS NULL AND handled_at IS NULL')->fetchColumn();
    }

    /** @return array<int, array> what an account declared, for its data export */
    public function byAccount(int $accountId): array
    {
        $stmt = $this->db->prepare(
            'SELECT order_number, name, email, statement, locale, matched, created_at FROM withdrawal WHERE account_id = :id ORDER BY id'
        );
        $stmt->execute(['id' => $accountId]);

        return array_map(fn (array $row) => ['matched' => (bool) $row['matched']] + $row, $stmt->fetchAll());
    }

    private static function typed(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['order_id'] = $row['order_id'] !== null ? (int) $row['order_id'] : null;
        $row['account_id'] = $row['account_id'] !== null ? (int) $row['account_id'] : null;
        $row['matched'] = (bool) $row['matched'];

        return $row;
    }
}
