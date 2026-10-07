<?php

declare(strict_types=1);

namespace Modulento\Core\Notification;

use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Modules;
use PDO;

/**
 * Short, in-app notices behind the bell icon in the header - never a
 * second channel of its own, always written at a point that already tells
 * the account something by e-mail (Order\OrderNotifier, the admin
 * "announce" helpers, Catalogue\OfferController, an extension's own
 * notice such as the auction's outbid mail).
 *
 * A notice is rendered with trans(message_key, params); where the text
 * itself is language-dependent (a state's name, say), the caller resolves
 * it in the recipient's language before it is stored - like the matching
 * e-mail already does. A notice is frozen in that language from then on.
 */
final class Notifications
{
    public function __construct(private PDO $db, private Modules $modules)
    {
    }

    /** @param array<string, string> $params */
    public function create(int $accountId, string $type, string $messageKey, array $params, string $link): void
    {
        if (!$this->modules->enabled('notifications')) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO notification (account_id, type, message_key, params, link, created_at) VALUES (:account, :type, :key, :params, :link, :now)'
        );
        $stmt->execute([
            'account' => $accountId,
            'type' => $type,
            'key' => $messageKey,
            'params' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'link' => $link,
            'now' => Clock::now(),
        ]);
    }

    public function unreadCount(int $accountId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM notification WHERE account_id = :id AND read_at IS NULL');
        $stmt->execute(['id' => $accountId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{rows: array<int, array{id: int, type: string, message_key: string, params: array<string, string>, link: string, unread: bool, created_at: string}>, total: int} */
    public function list(int $accountId, int $page, int $perPage): array
    {
        $count = $this->db->prepare('SELECT COUNT(*) FROM notification WHERE account_id = :id');
        $count->execute(['id' => $accountId]);

        $stmt = $this->db->prepare(
            'SELECT * FROM notification WHERE account_id = :id ORDER BY id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, (min($page, 100000) - 1) * $perPage)
        );
        $stmt->execute(['id' => $accountId]);

        return [
            'rows' => array_map(fn (array $row) => [
                'id' => (int) $row['id'],
                'type' => $row['type'],
                'message_key' => $row['message_key'],
                'params' => json_decode((string) $row['params'], true) ?: [],
                'link' => $row['link'],
                'unread' => $row['read_at'] === null,
                'created_at' => $row['created_at'],
            ], $stmt->fetchAll()),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    public function markAllRead(int $accountId): void
    {
        $stmt = $this->db->prepare('UPDATE notification SET read_at = :now WHERE account_id = :id AND read_at IS NULL');
        $stmt->execute(['now' => Clock::now(), 'id' => $accountId]);
    }

    /** Keeps the table from growing without bound - read notices only; unread ones stay until seen. */
    public function purgeRead(int $days): void
    {
        $stmt = $this->db->prepare('DELETE FROM notification WHERE read_at IS NOT NULL AND read_at < :before');
        $stmt->execute(['before' => Clock::now(-$days * 86400)]);
    }
}
