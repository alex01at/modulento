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

    /** @return int the message's id */
    public function add(int $offerId, int $askerId, int $authorId, string $body): int
    {
        $this->db->prepare(
            'INSERT INTO offer_message (offer_id, asker_id, author_id, body, created_at) VALUES (:offer, :asker, :author, :body, :now)'
        )->execute([
            'offer' => $offerId,
            'asker' => $askerId,
            'author' => $authorId,
            'body' => $body,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** One visitor's thread on an offer, oldest first. @return list<array{id: int, asker_id: int, author_id: ?int, body: string, created_at: string}> */
    public function thread(int $offerId, int $askerId): array
    {
        $stmt = $this->db->prepare('SELECT id, asker_id, author_id, body, created_at FROM offer_message WHERE offer_id = :offer AND asker_id = :asker ORDER BY id');
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
            'SELECT m.id, m.asker_id, m.author_id, m.body, m.created_at, a.display_name AS asker_name
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
}
