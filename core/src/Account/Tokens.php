<?php

declare(strict_types=1);

namespace Modulento\Core\Account;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * One-time tokens sent by e-mail (confirm address, reset password, confirm
 * a new address). A token is random, expires, works once, and only its
 * hash is stored.
 */
final class Tokens
{
    public const VERIFY_EMAIL = 'verify_email';
    public const RESET_PASSWORD = 'reset_password';
    public const CHANGE_EMAIL = 'change_email';

    public function __construct(private PDO $db)
    {
    }

    /** Replaces any earlier token of the same purpose, so only the newest e-mail works. */
    public function create(int $accountId, string $purpose, int $ttlSeconds, ?string $payload = null): string
    {
        $this->revoke($accountId, $purpose);

        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            'INSERT INTO account_token (account_id, purpose, token_hash, payload, expires_at, created_at)
             VALUES (:account_id, :purpose, :hash, :payload, :expires_at, :created_at)'
        );
        $stmt->execute([
            'account_id' => $accountId,
            'purpose' => $purpose,
            'hash' => hash('sha256', $token),
            'payload' => $payload,
            'expires_at' => Clock::now($ttlSeconds),
            'created_at' => Clock::now(),
        ]);

        return $token;
    }

    /** @return array{account_id: int, payload: ?string}|null the token's data if it is valid, without using it up */
    public function find(string $token, string $purpose): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT account_id, payload FROM account_token
             WHERE token_hash = :hash AND purpose = :purpose AND expires_at > :now'
        );
        $stmt->execute(['hash' => hash('sha256', $token), 'purpose' => $purpose, 'now' => Clock::now()]);
        $row = $stmt->fetch();

        return $row ? ['account_id' => (int) $row['account_id'], 'payload' => $row['payload']] : null;
    }

    /** @return array{account_id: int, payload: ?string}|null the token's data; it cannot be used again */
    public function consume(string $token, string $purpose): ?array
    {
        $data = $this->find($token, $purpose);
        if ($data === null) {
            return null;
        }

        // The DELETE decides: of two simultaneous requests with the same
        // token, only the one that actually removes the row may proceed.
        $stmt = $this->db->prepare('DELETE FROM account_token WHERE token_hash = :hash');
        $stmt->execute(['hash' => hash('sha256', $token)]);

        return $stmt->rowCount() === 1 ? $data : null;
    }

    public function revoke(int $accountId, string $purpose): void
    {
        $stmt = $this->db->prepare('DELETE FROM account_token WHERE account_id = :account_id AND purpose = :purpose');
        $stmt->execute(['account_id' => $accountId, 'purpose' => $purpose]);
    }

    public function purgeExpired(): void
    {
        $stmt = $this->db->prepare('DELETE FROM account_token WHERE expires_at <= :now');
        $stmt->execute(['now' => Clock::now()]);
    }
}
