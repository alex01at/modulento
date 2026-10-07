<?php

declare(strict_types=1);

namespace Modulento\Core\Catalogue;

use PDO;

/**
 * The messages about an offer before anyone orders. One visitor and the
 * provider talk in a thread per offer; the provider sees every thread.
 */
final class OfferMessages
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @param string|null $flaggedWord the word the word filter matched, if
     *        any - the message is stored and delivered regardless; it is
     *        never refused, only flagged for an administrator to decide
     * @return int the message's id
     */
    public function add(int $offerId, int $askerId, int $authorId, string $body, ?string $flaggedWord = null): int
    {
        $this->db->prepare(
            'INSERT INTO offer_message (offer_id, asker_id, author_id, body, flagged_word, created_at) VALUES (:offer, :asker, :author, :body, :flagged, :now)'
        )->execute([
            'offer' => $offerId,
            'asker' => $askerId,
            'author' => $authorId,
            'body' => $body,
            'flagged' => $flaggedWord,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** One visitor's thread on an offer, oldest first. @return list<array{id: int, asker_id: int, author_id: ?int, body: string, status: string, created_at: string}> */
    public function thread(int $offerId, int $askerId): array
    {
        $stmt = $this->db->prepare('SELECT id, asker_id, author_id, body, status, created_at FROM offer_message WHERE offer_id = :offer AND asker_id = :asker ORDER BY id');
        $stmt->execute(['offer' => $offerId, 'asker' => $askerId]);

        return $stmt->fetchAll();
    }

    /**
     * Every thread of an offer, for its provider: one entry per visitor with
     * the visitor's display name and the messages of that thread.
     *
     * @return list<array{asker_id: int, asker_name: string, messages: list<array<string, mixed>>}>
     */
    public function threads(int $offerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.id, m.asker_id, m.author_id, m.body, m.status, m.created_at, a.display_name AS asker_name
             FROM offer_message m JOIN account a ON a.id = m.asker_id
             WHERE m.offer_id = :offer ORDER BY m.id'
        );
        $stmt->execute(['offer' => $offerId]);

        $threads = [];
        foreach ($stmt->fetchAll() as $row) {
            $askerId = (int) $row['asker_id'];
            $threads[$askerId] ??= ['asker_id' => $askerId, 'asker_name' => (string) ($row['asker_name'] ?? ''), 'messages' => []];
            $threads[$askerId]['messages'][] = $row;
        }

        return array_values($threads);
    }

    /**
     * Every offer message, for the administration - a visitor or a
     * provider never sees this across offers, only their own thread(s).
     *
     * @return array{rows: array<int, array>, total: int}
     */
    public function listAll(bool $flaggedOnly, int $page, int $perPage): array
    {
        $where = $flaggedOnly ? 'WHERE m.flagged_word IS NOT NULL AND m.decided_at IS NULL' : '';

        $count = $this->db->query("SELECT COUNT(*) FROM offer_message m {$where}");

        $stmt = $this->db->prepare(
            "SELECT m.*, a.display_name, a.email
             FROM offer_message m LEFT JOIN account a ON a.id = m.author_id
             {$where} ORDER BY m.id DESC LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, (min($page, 100000) - 1) * $perPage)
        );
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    public function flaggedCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM offer_message WHERE flagged_word IS NOT NULL AND decided_at IS NULL')->fetchColumn();
    }

    public function messageCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM offer_message')->fetchColumn();
    }

    /**
     * One row per thread (offer + asker) the account takes part in, as
     * asker or as the offer's own provider, with its latest message - for
     * the account's own inbox (Controller\InboxController). The offer's
     * title and, when the account is the asker, the provider's name are
     * resolved by the caller (Offers::find()/text()) - a few rows at a
     * time, not a listing across every offer.
     *
     * @return array<int, array{offer_id: int, asker_id: int, is_asker: bool, asker_name: string, body: string, status: string, created_at: string, unread: bool}>
     */
    public function inboxThreads(int $accountId, ?int $providerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.offer_id, m.asker_id, m.body, m.status, m.created_at, m.id AS last_message_id,
                    a.display_name AS asker_name, s.seen_id
             FROM offer_message m
             JOIN offer f ON f.id = m.offer_id
             LEFT JOIN account a ON a.id = m.asker_id
             LEFT JOIN message_seen s ON s.account_id = :me1 AND s.scope = \'offer\' AND s.ref_id = m.offer_id AND s.sub_id = m.asker_id
             WHERE m.id = (SELECT MAX(id) FROM offer_message WHERE offer_id = m.offer_id AND asker_id = m.asker_id)
               AND (m.asker_id = :me2 OR f.provider_id = :provider)
             ORDER BY m.created_at DESC LIMIT 100'
        );
        $stmt->execute(['me1' => $accountId, 'me2' => $accountId, 'provider' => $providerId]);

        return array_map(fn (array $row) => [
            'offer_id' => (int) $row['offer_id'],
            'asker_id' => (int) $row['asker_id'],
            'is_asker' => (int) $row['asker_id'] === $accountId,
            'asker_name' => (string) ($row['asker_name'] ?? ''),
            'body' => $row['body'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'unread' => (int) $row['last_message_id'] > (int) ($row['seen_id'] ?? 0),
        ], $stmt->fetchAll());
    }

    /** @param string $status 'visible' or 'hidden' */
    public function setStatus(int $id, string $status, int $adminId): bool
    {
        if (!in_array($status, ['visible', 'hidden'], true)) {
            return false;
        }

        $stmt = $this->db->prepare('UPDATE offer_message SET status = :status, decided_at = :now, decided_by = :by WHERE id = :id');
        $stmt->execute(['status' => $status, 'now' => gmdate('Y-m-d H:i:s'), 'by' => $adminId, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** Clears the flag without changing whether the message is shown. */
    public function dismissFlag(int $id, int $adminId): bool
    {
        $stmt = $this->db->prepare('UPDATE offer_message SET decided_at = :now, decided_by = :by WHERE id = :id');
        $stmt->execute(['now' => gmdate('Y-m-d H:i:s'), 'by' => $adminId, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }
}
