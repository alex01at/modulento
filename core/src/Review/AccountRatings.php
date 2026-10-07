<?php

declare(strict_types=1);

namespace Modulento\Core\Review;

use Modulento\Core\Support\BadWords;
use Modulento\Core\Support\Clock;
use PDO;
use PDOException;

/**
 * Ratings of one account by another - what one account thinks of another as
 * a person or a business, not of one order. Deliberately not the review
 * table: a review belongs to one order, this to a pair of accounts, and the
 * rule for who may rate whom lives in canRate(), not in a foreign key.
 */
final class AccountRatings
{
    public const MAX_LENGTH = 2000;

    public function __construct(private PDO $db, private BadWords $badWords)
    {
    }

    /**
     * Whether $raterId may rate $ratedId now: not itself, not twice, and - the
     * only rule the core ships today - only where the two have a real order
     * between them, as a buyer and as the account a provider profile belongs
     * to, in either direction. A context with its own notion of a genuine
     * encounter (a future dating extension, say) checks that in its own way
     * before calling create(); this method is what the core's own callers
     * (the order page, today) use, not something create() enforces itself.
     */
    public function canRate(int $raterId, int $ratedId): bool
    {
        if ($raterId === $ratedId) {
            return false;
        }

        $already = $this->db->prepare('SELECT COUNT(*) FROM account_rating WHERE rater_id = :rater AND rated_id = :rated');
        $already->execute(['rater' => $raterId, 'rated' => $ratedId]);
        if ((int) $already->fetchColumn() > 0) {
            return false;
        }

        // Native prepares (see Database::connect()) refuse a named placeholder used twice, so each
        // occurrence gets its own name even though two of them share a value.
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM orders o JOIN provider p ON p.id = o.provider_id
             WHERE (o.buyer_id = :a1 AND p.account_id = :b1) OR (o.buyer_id = :b2 AND p.account_id = :a2)'
        );
        $stmt->execute(['a1' => $raterId, 'b1' => $ratedId, 'b2' => $ratedId, 'a2' => $raterId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM account_rating WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function findByPair(int $raterId, int $ratedId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM account_rating WHERE rater_id = :rater AND rated_id = :rated');
        $stmt->execute(['rater' => $raterId, 'rated' => $ratedId]);

        return $stmt->fetch() ?: null;
    }

    /** @return string|null language key of the problem, null on success */
    public function create(int $raterId, string $raterName, int $ratedId, int $rating, string $body, string $locale): ?string
    {
        $body = trim(str_replace("\r\n", "\n", $body));

        if ($rating < 1 || $rating > 5) {
            return 'core.account_rating.error.rating';
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            return 'core.account_rating.error.too_long';
        }
        if ($this->badWords->find($body) !== null) {
            return 'core.badword.found';
        }
        if (!$this->canRate($raterId, $ratedId)) {
            return 'core.account_rating.error.not_possible';
        }

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO account_rating (rater_id, rater_name, rated_id, rating, body, locale, status, created_at)
                 VALUES (:rater, :name, :rated, :rating, :body, :locale, 'published', :now)"
            );
            $stmt->execute([
                'rater' => $raterId, 'name' => mb_substr($raterName, 0, 100), 'rated' => $ratedId,
                'rating' => $rating, 'body' => $body, 'locale' => $locale, 'now' => Clock::now(),
            ]);
        } catch (PDOException) {
            // The unique key on the pair: rated already, by this account.
            return 'core.account_rating.error.exists';
        }

        $this->recount($ratedId);

        return null;
    }

    /** The rated account's one public answer. */
    public function reply(int $ratingId, string $reply): ?string
    {
        $reply = trim(str_replace("\r\n", "\n", $reply));
        if ($reply === '' || mb_strlen($reply) > self::MAX_LENGTH) {
            return 'core.account_rating.error.reply';
        }

        $stmt = $this->db->prepare('UPDATE account_rating SET reply = :reply, replied_at = :now WHERE id = :id AND reply IS NULL');
        $stmt->execute(['reply' => $reply, 'now' => Clock::now(), 'id' => $ratingId]);

        return $stmt->rowCount() === 1 ? null : 'core.account_rating.error.replied';
    }

    /** Hides a rating or shows it again; a hidden one no longer counts. */
    public function setStatus(int $ratingId, string $status, ?string $note): void
    {
        $rating = $this->find($ratingId);
        if ($rating === null) {
            return;
        }

        $stmt = $this->db->prepare('UPDATE account_rating SET status = :status, status_note = :note WHERE id = :id');
        $stmt->execute([
            'status' => $status === 'hidden' ? 'hidden' : 'published',
            'note' => $status === 'hidden' && $note !== null && trim($note) !== '' ? trim($note) : null,
            'id' => $ratingId,
        ]);

        $this->recount((int) $rating['rated_id']);
    }

    /**
     * Published ratings of an account, newest first.
     *
     * @return array{rows: array<int, array>, total: int}
     */
    public function listPublic(int $accountId, int $page, int $perPage): array
    {
        $count = $this->db->prepare("SELECT COUNT(*) FROM account_rating WHERE rated_id = :id AND status = 'published'");
        $count->execute(['id' => $accountId]);

        $stmt = $this->db->prepare(
            "SELECT * FROM account_rating WHERE rated_id = :id AND status = 'published'
             ORDER BY id DESC LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, (min($page, 100000) - 1) * $perPage)
        );
        $stmt->execute(['id' => $accountId]);

        return ['rows' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array{rows: array<int, array>, total: int} every rating, for the administration */
    public function listAll(?string $status, int $page, int $perPage): array
    {
        $where = $status !== null ? 'WHERE r.status = :status' : '';
        $params = $status !== null ? ['status' => $status] : [];

        $count = $this->db->prepare("SELECT COUNT(*) FROM account_rating r {$where}");
        $count->execute($params);

        $stmt = $this->db->prepare(
            "SELECT r.*, a.email AS rated_email, a.display_name AS rated_name FROM account_rating r
             JOIN account a ON a.id = r.rated_id {$where}
             ORDER BY r.id DESC LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, (min($page, 100000) - 1) * $perPage)
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /**
     * Every rating about the account, any status - its own "about me" page, not
     * what a visitor sees: a hidden one stays visible to the account itself,
     * with the reason, the same way a buyer still sees their own hidden review.
     *
     * @return array{rows: array<int, array>, total: int}
     */
    public function aboutAccount(int $accountId, int $page, int $perPage): array
    {
        $count = $this->db->prepare('SELECT COUNT(*) FROM account_rating WHERE rated_id = :id');
        $count->execute(['id' => $accountId]);

        $stmt = $this->db->prepare(
            'SELECT * FROM account_rating WHERE rated_id = :id
             ORDER BY id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, (min($page, 100000) - 1) * $perPage)
        );
        $stmt->execute(['id' => $accountId]);

        return ['rows' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array<int, array> ratings the account wrote, for its data export */
    public function byAuthor(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT rated_id, rating, body, locale, status, created_at FROM account_rating WHERE rater_id = :id ORDER BY id');
        $stmt->execute(['id' => $accountId]);

        return $stmt->fetchAll();
    }

    /** @return array<int, array> ratings written about the account, for its data export */
    public function byRated(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT rater_name, rating, body, locale, status, created_at FROM account_rating WHERE rated_id = :id ORDER BY id');
        $stmt->execute(['id' => $accountId]);

        return $stmt->fetchAll();
    }

    /** Before an account is deleted: ratings it wrote stay, without its name. */
    public function anonymise(int $accountId): void
    {
        $stmt = $this->db->prepare("UPDATE account_rating SET rater_name = '' WHERE rater_id = :id");
        $stmt->execute(['id' => $accountId]);
    }

    /** Brings the numbers kept with an account in line with its published ratings. */
    private function recount(int $accountId): void
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(rating), 0) AS total FROM account_rating WHERE rated_id = :id AND status = 'published'");
        $stmt->execute(['id' => $accountId]);
        $numbers = $stmt->fetch();

        $update = $this->db->prepare('UPDATE account SET rating_count = :n, rating_sum = :total WHERE id = :id');
        $update->execute(['n' => (int) $numbers['n'], 'total' => (int) $numbers['total'], 'id' => $accountId]);
    }
}
