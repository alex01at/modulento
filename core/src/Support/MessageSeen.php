<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use PDO;
use PDOException;

/**
 * Which messages an account has not looked at yet. Opening an order or a
 * thread marks it seen; the count of unread messages is what the page asks
 * for, every few seconds, to show the badge on the account's picture.
 */
final class MessageSeen
{
    public function __construct(private PDO $db)
    {
    }

    /** Marks the order's messages as read up to the newest one; a later message is unread. */
    public function markOrder(int $accountId, int $orderId): void
    {
        $this->mark($accountId, 'order', $orderId, 0, $this->newest('SELECT MAX(id) FROM order_message WHERE order_id = :ref', ['ref' => $orderId]));
    }

    /** Marks one visitor's thread on an offer as read up to its newest message. */
    public function markThread(int $accountId, int $offerId, int $askerId): void
    {
        $this->mark($accountId, 'offer', $offerId, $askerId, $this->newest('SELECT MAX(id) FROM offer_message WHERE offer_id = :ref AND asker_id = :sub', ['ref' => $offerId, 'sub' => $askerId]));
    }

    /** Messages from others the account has not seen: in its orders, and in the threads on its offers or its own questions. */
    public function unread(int $accountId): int
    {
        $stmt = $this->db->prepare(
            "SELECT
                (SELECT COUNT(*) FROM order_message m
                    JOIN orders o ON o.id = m.order_id
                    LEFT JOIN message_seen s ON s.account_id = :me1 AND s.scope = 'order' AND s.ref_id = o.id AND s.sub_id = 0
                    WHERE m.account_id <> :me2
                      AND (o.buyer_id = :me3 OR o.provider_id IN (SELECT id FROM provider WHERE account_id = :me4))
                      AND m.id > COALESCE(s.seen_id, 0))
                +
                (SELECT COUNT(*) FROM offer_message m
                    JOIN offer f ON f.id = m.offer_id
                    JOIN provider p ON p.id = f.provider_id
                    LEFT JOIN message_seen s ON s.account_id = :me5 AND s.scope = 'offer' AND s.ref_id = m.offer_id AND s.sub_id = m.asker_id
                    WHERE m.author_id <> :me6
                      AND (p.account_id = :me7 OR m.asker_id = :me8)
                      AND m.id > COALESCE(s.seen_id, 0))"
        );
        $stmt->execute([
            'me1' => $accountId, 'me2' => $accountId, 'me3' => $accountId, 'me4' => $accountId,
            'me5' => $accountId, 'me6' => $accountId, 'me7' => $accountId, 'me8' => $accountId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /** The id of the newest message of a conversation: what counts as read once it was opened. */
    private function newest(string $sql, array $params): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function mark(int $accountId, string $scope, int $refId, int $subId, int $seenId): void
    {
        $key = ['account' => $accountId, 'scope' => $scope, 'ref' => $refId, 'sub' => $subId];

        $update = $this->db->prepare('UPDATE message_seen SET seen_id = :seen WHERE account_id = :account AND scope = :scope AND ref_id = :ref AND sub_id = :sub');
        $update->execute($key + ['seen' => $seenId]);
        if ($update->rowCount() > 0) {
            return;
        }
        try {
            $this->db->prepare('INSERT INTO message_seen (account_id, scope, ref_id, sub_id, seen_id) VALUES (:account, :scope, :ref, :sub, :seen)')
                ->execute($key + ['seen' => $seenId]);
        } catch (PDOException) {
            // The same page opened twice at once: the other request was first.
        }
    }
}
