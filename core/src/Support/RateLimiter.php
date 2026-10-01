<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use PDO;

/**
 * Rate limiting for sensitive unauthenticated actions (login, register,
 * password reset) - backed by one small table rather than a new
 * infrastructure dependency. Old rows are removed by the scheduler task
 * "core.rate-limit-cleanup".
 */
final class RateLimiter
{
    public const MAX_AGE_SECONDS = 86400;

    public function __construct(private PDO $db)
    {
    }

    public function tooManyAttempts(string $action, string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM rate_limit_attempt
             WHERE action = :action AND identifier = :identifier AND created_at > :since'
        );
        $stmt->execute(['action' => $action, 'identifier' => $identifier, 'since' => Clock::now(-$windowSeconds)]);

        return (int) $stmt->fetchColumn() >= $maxAttempts;
    }

    public function recordAttempt(string $action, string $identifier): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO rate_limit_attempt (action, identifier, created_at) VALUES (:action, :identifier, :now)'
        );
        $stmt->execute(['action' => $action, 'identifier' => $identifier, 'now' => Clock::now()]);
    }

    /** Counts the attempt and says whether the limit was already reached before it. */
    public function hit(string $action, string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        $limited = $this->tooManyAttempts($action, $identifier, $maxAttempts, $windowSeconds);
        if (!$limited) {
            $this->recordAttempt($action, $identifier);
        }

        return $limited;
    }

    public function cleanup(): void
    {
        $stmt = $this->db->prepare('DELETE FROM rate_limit_attempt WHERE created_at < :before');
        $stmt->execute(['before' => Clock::now(-self::MAX_AGE_SECONDS)]);
    }
}
