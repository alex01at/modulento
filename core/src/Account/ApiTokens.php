<?php

declare(strict_types=1);

namespace Modulento\Core\Account;

use Modulento\Core\Support\Auth;
use Modulento\Core\Support\Clock;
use PDO;

/**
 * Logins of an app: a device that signed in through the mobile API holds
 * "selector.secret" and sends it with every request as a Bearer token.
 *
 * The selector finds the row; the secret is compared against its stored
 * hash. Built like LoginTokens, but without a cookie and without rotation:
 * an app keeps its token in its own storage, so there is no browser that
 * reopens with several tabs. A token is valid until it is revoked, has
 * expired, or the account's password changes.
 */
final class ApiTokens
{
    public const NAME_LENGTH = 100;

    /** How long a revoked or expired row is kept before the cleanup removes it. */
    private const KEEP_SECONDS = 30 * 86400;

    public function __construct(private PDO $db)
    {
    }

    /**
     * A new token for the account. The secret is returned here, once, in
     * plain text - afterwards only its hash exists.
     *
     * @return array{id: int, selector: string, secret: string}
     */
    public function issue(int $accountId, ?string $name): array
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM account WHERE id = :id');
        $stmt->execute(['id' => $accountId]);
        $stamp = Auth::stamp((string) $stmt->fetchColumn());

        $selector = bin2hex(random_bytes(12));
        $secret = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            'INSERT INTO account_api_token (account_id, selector, token_hash, name, auth_stamp, created_at, last_used_at)
             VALUES (:account_id, :selector, :hash, :name, :stamp, :created_at, :last_used_at)'
        );
        $stmt->execute([
            'account_id' => $accountId,
            'selector' => $selector,
            'hash' => hash('sha256', $secret),
            'name' => self::name($name),
            'stamp' => $stamp,
            'created_at' => Clock::now(),
            'last_used_at' => Clock::now(),
        ]);

        return ['id' => (int) $this->db->lastInsertId(), 'selector' => $selector, 'secret' => $secret];
    }

    /**
     * For a request that came with a Bearer token.
     *
     * @return int|null the account the token logs in
     */
    public function resolve(string $selector, string $secret): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.account_id, t.token_hash, t.auth_stamp, t.expires_at, t.revoked_at,
                    a.password_hash, a.status
             FROM account_api_token t JOIN account a ON a.id = t.account_id
             WHERE t.selector = :selector'
        );
        $stmt->execute(['selector' => $selector]);
        $row = $stmt->fetch();

        if (!$row
            || !hash_equals((string) $row['token_hash'], hash('sha256', $secret))
            || $row['revoked_at'] !== null
            || ($row['expires_at'] !== null && $row['expires_at'] <= Clock::now())
            || $row['status'] !== 'active'
            || !hash_equals(Auth::stamp((string) $row['password_hash']), (string) $row['auth_stamp'])) {
            return null;
        }

        $stmt = $this->db->prepare('UPDATE account_api_token SET last_used_at = :now WHERE id = :id');
        $stmt->execute(['now' => Clock::now(), 'id' => (int) $row['id']]);

        return (int) $row['account_id'];
    }

    /**
     * The app logins of an account that still work, newest first - without
     * selector and hash, so the result can be shown.
     *
     * @return array<int, array{id: int, name: ?string, created_at: string, last_used_at: string, expires_at: ?string}>
     */
    public function list(int $accountId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, created_at, last_used_at, expires_at FROM account_api_token
             WHERE account_id = :account_id AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > :now)
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['account_id' => $accountId, 'now' => Clock::now()]);

        return array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'created_at' => $row['created_at'],
            'last_used_at' => $row['last_used_at'],
            'expires_at' => $row['expires_at'],
        ], $stmt->fetchAll());
    }

    /** Revokes one token, only if it belongs to the account. */
    public function revoke(int $id, int $accountId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE account_api_token SET revoked_at = :now
             WHERE id = :id AND account_id = :account_id AND revoked_at IS NULL'
        );
        $stmt->execute(['now' => Clock::now(), 'id' => $id, 'account_id' => $accountId]);

        return $stmt->rowCount() === 1;
    }

    /** Revokes the token a request was sent with: logging out of the app on this one device. */
    public function revokeBySelector(string $selector, int $accountId): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM account_api_token WHERE selector = :selector');
        $stmt->execute(['selector' => $selector]);
        $id = $stmt->fetchColumn();

        return $id !== false && $this->revoke((int) $id, $accountId);
    }

    /**
     * After a changed password, a blocked account or "log out everywhere".
     * The stamp of a token no longer fits after a password change anyway,
     * but rows nobody can use have no reason to stay valid on paper.
     */
    public function revokeAll(int $accountId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE account_api_token SET revoked_at = :now WHERE account_id = :account_id AND revoked_at IS NULL'
        );
        $stmt->execute(['now' => Clock::now(), 'account_id' => $accountId]);
    }

    /** Removes what was revoked or has expired a while ago. */
    public function purgeExpired(): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM account_api_token WHERE revoked_at <= :revoked_before OR expires_at <= :expired_before'
        );
        $stmt->execute([
            'revoked_before' => Clock::now(-self::KEEP_SECONDS),
            'expired_before' => Clock::now(-self::KEEP_SECONDS),
        ]);
    }

    /**
     * The selector and secret of an "Authorization: Bearer" header - given
     * as the request's HTTP_AUTHORIZATION, or null where the server did not
     * set that.
     *
     * @return array{selector: string, secret: string}|null null for anything this class did not issue
     */
    public static function parseHeader(mixed $header): ?array
    {
        // Behind a rewrite some servers hand the header over under this name.
        $header ??= $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (!is_string($header) || preg_match('/^Bearer\s+([a-f0-9]{24})\.([a-f0-9]{64})$/', $header, $m) !== 1) {
            return null;
        }

        return ['selector' => $m[1], 'secret' => $m[2]];
    }

    private static function name(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        // Whatever a client sends: valid UTF-8, no control characters.
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_scrub($name, 'UTF-8')));

        return $name !== '' ? mb_substr($name, 0, self::NAME_LENGTH) : null;
    }
}
