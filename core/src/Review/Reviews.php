<?php

declare(strict_types=1);

namespace Modulento\Core\Review;

use Modulento\Core\Support\Clock;
use PDO;
use PDOException;

/**
 * Reviews of finished orders. A review can only come from the buyer of an
 * order, once, which is what the platform can truthfully say about where
 * its reviews come from.
 */
final class Reviews
{
    public const MAX_LENGTH = 2000;

    public function __construct(private PDO $db)
    {
    }

    public function findByOrder(int $orderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM review WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);

        return $stmt->fetch() ?: null;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM review WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @param array $order a finished, reviewable order - the caller checks that and who is asking
     * @return string|null language key of the problem, null on success
     */
    public function create(array $order, int $authorId, string $authorName, int $rating, string $body, string $locale): ?string
    {
        $body = trim(str_replace("\r\n", "\n", $body));

        if ($rating < 1 || $rating > 5) {
            return 'core.review.error.rating';
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            return 'core.review.error.too_long';
        }
        if ($order['provider_id'] === null) {
            return 'core.review.error.not_possible';
        }

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO review (order_id, offer_id, provider_id, author_id, author_name, rating, body, locale, status, created_at)
                 VALUES (:order, :offer, :provider, :author, :name, :rating, :body, :locale, 'published', :now)"
            );
            $stmt->execute([
                'order' => $order['id'], 'offer' => $order['offer_id'], 'provider' => $order['provider_id'], 'author' => $authorId,
                'name' => mb_substr($authorName, 0, 100), 'rating' => $rating, 'body' => $body, 'locale' => $locale, 'now' => Clock::now(),
            ]);
        } catch (PDOException) {
            // The unique key on the order: it has been reviewed already.
            return 'core.review.error.exists';
        }

        $this->recount($order['offer_id'], $order['provider_id']);

        return null;
    }

    /** The provider's one public answer. */
    public function reply(int $reviewId, string $reply): ?string
    {
        $reply = trim(str_replace("\r\n", "\n", $reply));
        if ($reply === '' || mb_strlen($reply) > self::MAX_LENGTH) {
            return 'core.review.error.reply';
        }

        $stmt = $this->db->prepare('UPDATE review SET reply = :reply, replied_at = :now WHERE id = :id AND reply IS NULL');
        $stmt->execute(['reply' => $reply, 'now' => Clock::now(), 'id' => $reviewId]);

        return $stmt->rowCount() === 1 ? null : 'core.review.error.replied';
    }

    /** Hides a review or shows it again; a hidden one no longer counts. */
    public function setStatus(int $reviewId, string $status, ?string $note): void
    {
        $review = $this->find($reviewId);
        if ($review === null) {
            return;
        }

        $stmt = $this->db->prepare('UPDATE review SET status = :status, status_note = :note WHERE id = :id');
        $stmt->execute([
            'status' => $status === 'hidden' ? 'hidden' : 'published',
            'note' => $status === 'hidden' && $note !== null && trim($note) !== '' ? trim($note) : null,
            'id' => $reviewId,
        ]);

        $this->recount($review['offer_id'] !== null ? (int) $review['offer_id'] : null, (int) $review['provider_id']);
    }

    /**
     * Published reviews of an offer or of a provider, newest first.
     *
     * @param string $of "offer" or "provider"
     * @return array{rows: array<int, array>, total: int}
     */
    public function listPublic(string $of, int $id, int $page, int $perPage): array
    {
        $column = $of === 'offer' ? 'offer_id' : 'provider_id';

        $count = $this->db->prepare("SELECT COUNT(*) FROM review WHERE {$column} = :id AND status = 'published'");
        $count->execute(['id' => $id]);

        $stmt = $this->db->prepare(
            "SELECT * FROM review WHERE {$column} = :id AND status = 'published'
             ORDER BY id DESC LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage)
        );
        $stmt->execute(['id' => $id]);

        return ['rows' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array{rows: array<int, array>, total: int} every review, for the administration */
    public function listAll(?string $status, int $page, int $perPage): array
    {
        $where = $status !== null ? 'WHERE r.status = :status' : '';
        $params = $status !== null ? ['status' => $status] : [];

        $count = $this->db->prepare("SELECT COUNT(*) FROM review r {$where}");
        $count->execute($params);

        $stmt = $this->db->prepare(
            "SELECT r.*, p.name AS provider_name, o.offer_title FROM review r
             JOIN provider p ON p.id = r.provider_id JOIN orders o ON o.id = r.order_id {$where}
             ORDER BY r.id DESC LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage)
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array<int, array> the reviews an account wrote, for its data export */
    public function byAuthor(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT order_id, rating, body, locale, status, created_at FROM review WHERE author_id = :id ORDER BY id');
        $stmt->execute(['id' => $accountId]);

        return $stmt->fetchAll();
    }

    /** Before an account is deleted: its reviews stay, without the name. */
    public function anonymise(int $accountId): void
    {
        $stmt = $this->db->prepare("UPDATE review SET author_name = '' WHERE author_id = :id");
        $stmt->execute(['id' => $accountId]);
    }

    /** @return array{count: int, average: ?float} from the columns kept with an offer or a provider */
    public static function summary(array $row): array
    {
        $count = (int) ($row['rating_count'] ?? 0);

        return ['count' => $count, 'average' => $count > 0 ? round((int) $row['rating_sum'] / $count, 1) : null];
    }

    /** Brings the numbers kept with an offer and a provider in line with the published reviews. */
    private function recount(?int $offerId, int $providerId): void
    {
        foreach ([['offer', 'offer_id', $offerId], ['provider', 'provider_id', $providerId]] as [$table, $column, $id]) {
            if ($id === null) {
                continue;
            }

            $stmt = $this->db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(rating), 0) AS total FROM review WHERE {$column} = :id AND status = 'published'");
            $stmt->execute(['id' => $id]);
            $numbers = $stmt->fetch();

            $update = $this->db->prepare("UPDATE {$table} SET rating_count = :n, rating_sum = :total WHERE id = :id");
            $update->execute(['n' => (int) $numbers['n'], 'total' => (int) $numbers['total'], 'id' => $id]);
        }
    }
}
