<?php

declare(strict_types=1);

namespace Modulento\Core\Account;

use Modulento\Core\Support\Auth;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\RememberCookie;
use PDO;

/**
 * "Stay logged in": a device that ticked the box at login holds a cookie
 * "selector:secret" and is logged in again from it once its session is gone.
 *
 * The selector finds the row; the secret is compared against its stored
 * hash. Every use replaces the secret, so a copied cookie works at most
 * until the owner's browser comes back - and then shows: a secret that was
 * replaced long ago can only come from a copy, and every device of the
 * account is logged out.
 */
final class LoginTokens
{
    public const TTL_SECONDS = 30 * 86400;

    /**
     * How long the secret from before a rotation still logs in. A browser
     * that reopens with several tabs sends them all with the old cookie;
     * only the first answer carries the new one. Without this, the other
     * tabs would look like a thief. Long enough for a slow connection,
     * short enough that a copied cookie gains next to nothing from it.
     */
    public const GRACE_SECONDS = 30;

    private const USER_AGENT_LENGTH = 255;

    public function __construct(private PDO $db)
    {
    }

    /** Remembers this device for the account and hands it the cookie. */
    public function issue(int $accountId): void
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM account WHERE id = :id');
        $stmt->execute(['id' => $accountId]);
        $stamp = Auth::stamp((string) $stmt->fetchColumn());

        $selector = bin2hex(random_bytes(12));
        $secret = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            'INSERT INTO account_login_token (account_id, selector, token_hash, auth_stamp, user_agent, created_at, last_used_at, expires_at)
             VALUES (:account_id, :selector, :hash, :stamp, :user_agent, :created_at, :last_used_at, :expires_at)'
        );
        $stmt->execute([
            'account_id' => $accountId,
            'selector' => $selector,
            'hash' => hash('sha256', $secret),
            'stamp' => $stamp,
            'user_agent' => self::userAgent(),
            'created_at' => Clock::now(),
            'last_used_at' => Clock::now(),
            'expires_at' => Clock::now(self::TTL_SECONDS),
        ]);

        RememberCookie::set($selector . ':' . $secret, time() + self::TTL_SECONDS);
    }

    /**
     * For a request that came with the cookie but without a login.
     *
     * @return int|null the account to log in
     */
    public function resume(): ?int
    {
        $cookie = self::parse(RememberCookie::read());
        $row = $cookie !== null ? $this->find($cookie['selector']) : null;
        if ($cookie === null || $row === null) {
            RememberCookie::clear();
            return null;
        }

        $hash = hash('sha256', $cookie['secret']);
        $isCurrent = hash_equals($row['token_hash'], $hash);
        $isPrevious = !$isCurrent && $row['previous_hash'] !== null && hash_equals($row['previous_hash'], $hash)
            && $row['rotated_at'] !== null && $row['rotated_at'] >= Clock::now(-self::GRACE_SECONDS);

        // The selector is known but the secret is not (or no longer): the
        // cookie was copied, and either the copy or the original has been
        // used since. Which of the two is asking cannot be told, so nobody
        // stays remembered.
        if (!$isCurrent && !$isPrevious) {
            $this->revokeAll((int) $row['account_id']);
            RememberCookie::clear();
            return null;
        }

        if ($row['expires_at'] <= Clock::now()
            || $row['status'] !== 'active'
            || $row['email_verified_at'] === null
            || !hash_equals(Auth::stamp((string) $row['password_hash']), $row['auth_stamp'])) {
            $this->delete((int) $row['id']);
            RememberCookie::clear();
            return null;
        }

        // Another request of this browser rotated a moment ago and its
        // answer carries the new cookie: log in, change nothing.
        if ($isPrevious) {
            return (int) $row['account_id'];
        }

        $secret = bin2hex(random_bytes(32));
        // Conditional on the hash just read: of two requests arriving at
        // the same moment only one rotates. The other one's secret was
        // valid when it was read, so it is logged in all the same and
        // leaves the cookie to the winner.
        $stmt = $this->db->prepare(
            'UPDATE account_login_token
             SET token_hash = :new_hash, previous_hash = :previous_hash, rotated_at = :rotated_at,
                 last_used_at = :last_used_at, expires_at = :expires_at
             WHERE id = :id AND token_hash = :old_hash'
        );
        $stmt->execute([
            'new_hash' => hash('sha256', $secret),
            'previous_hash' => $hash,
            'rotated_at' => Clock::now(),
            'last_used_at' => Clock::now(),
            'expires_at' => Clock::now(self::TTL_SECONDS),
            'id' => (int) $row['id'],
            'old_hash' => $hash,
        ]);
        if ($stmt->rowCount() === 1) {
            RememberCookie::set($cookie['selector'] . ':' . $secret, time() + self::TTL_SECONDS);
        }

        return (int) $row['account_id'];
    }

    /** Logging out: this device is no longer remembered, the others are. */
    public function forget(): void
    {
        $row = $this->current();
        if ($row !== null) {
            $this->delete($row['id']);
        }
        if (RememberCookie::present()) {
            RememberCookie::clear();
        }
    }

    /**
     * After the logged-in account changed its password or address: every
     * token is void. A device that was remembered and has just proven the
     * password gets a new one, the way its session stays valid.
     */
    public function renew(int $accountId): void
    {
        $row = $this->current();
        $wasRemembered = $row !== null && $row['account_id'] === $accountId;

        $this->revokeAll($accountId);
        if ($wasRemembered) {
            $this->issue($accountId);
        } elseif (RememberCookie::present()) {
            RememberCookie::clear();
        }
    }

    public function revokeAll(int $accountId): void
    {
        $stmt = $this->db->prepare('DELETE FROM account_login_token WHERE account_id = :account_id');
        $stmt->execute(['account_id' => $accountId]);
    }

    /**
     * The devices an account is remembered on, used last first - without
     * selector and hash, so the result can be shown and exported.
     *
     * @return array<int, array{created_at: string, last_used_at: string, expires_at: string, user_agent: ?string, current: bool}>
     */
    public function devices(int $accountId): array
    {
        $current = $this->current();
        $stmt = $this->db->prepare(
            'SELECT id, created_at, last_used_at, expires_at, user_agent FROM account_login_token
             WHERE account_id = :account_id AND expires_at > :now ORDER BY last_used_at DESC, id DESC'
        );
        $stmt->execute(['account_id' => $accountId, 'now' => Clock::now()]);

        return array_map(fn (array $row) => [
            'created_at' => $row['created_at'],
            'last_used_at' => $row['last_used_at'],
            'expires_at' => $row['expires_at'],
            'user_agent' => $row['user_agent'],
            'current' => $current !== null && $current['id'] === (int) $row['id'],
        ], $stmt->fetchAll());
    }

    public function purgeExpired(): void
    {
        $stmt = $this->db->prepare('DELETE FROM account_login_token WHERE expires_at <= :now');
        $stmt->execute(['now' => Clock::now()]);
    }

    /**
     * The token this request's cookie belongs to, if its secret fits - the
     * one before the last rotation counts too, since the browser may not
     * have received the new cookie yet.
     *
     * @return array{id: int, account_id: int}|null
     */
    private function current(): ?array
    {
        $cookie = self::parse(RememberCookie::read());
        $row = $cookie !== null ? $this->find($cookie['selector']) : null;
        if ($cookie === null || $row === null) {
            return null;
        }

        $hash = hash('sha256', $cookie['secret']);
        if (!hash_equals($row['token_hash'], $hash)
            && ($row['previous_hash'] === null || !hash_equals($row['previous_hash'], $hash))) {
            return null;
        }

        return ['id' => (int) $row['id'], 'account_id' => (int) $row['account_id']];
    }

    private function find(string $selector): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.account_id, t.token_hash, t.previous_hash, t.rotated_at, t.auth_stamp, t.expires_at,
                    a.password_hash, a.status, a.email_verified_at
             FROM account_login_token t JOIN account a ON a.id = t.account_id
             WHERE t.selector = :selector'
        );
        $stmt->execute(['selector' => $selector]);

        return $stmt->fetch() ?: null;
    }

    private function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM account_login_token WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** @return array{selector: string, secret: string}|null null for anything this class did not write */
    private static function parse(?string $cookie): ?array
    {
        if ($cookie === null || preg_match('/\A([a-f0-9]{24}):([a-f0-9]{64})\z/', $cookie, $m) !== 1) {
            return null;
        }

        return ['selector' => $m[1], 'secret' => $m[2]];
    }

    private static function userAgent(): ?string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (!is_string($agent)) {
            return null;
        }

        // Whatever a client sends: valid UTF-8, no control characters.
        $agent = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_scrub($agent, 'UTF-8')));

        return $agent !== '' ? mb_substr($agent, 0, self::USER_AGENT_LENGTH) : null;
    }
}
