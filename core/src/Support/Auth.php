<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use PDO;

final class Auth
{
    private const WILDCARD = '*';

    private bool $accountLoaded = false;
    private ?array $account = null;
    /** @var string[]|null */
    private ?array $permissions = null;

    public function __construct(private PDO $db)
    {
    }

    public function login(int $accountId): void
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM account WHERE id = :id');
        $stmt->execute(['id' => $accountId]);

        Session::regenerate();
        Session::set('account_id', $accountId);
        Session::set('auth_stamp', self::stamp((string) $stmt->fetchColumn()));
        $this->accountLoaded = false;
        $this->permissions = null;

        $stmt = $this->db->prepare('UPDATE account SET last_login_at = :now WHERE id = :id');
        $stmt->execute(['now' => Clock::now(), 'id' => $accountId]);
    }

    /**
     * After the logged-in account changed its own password: keeps this
     * session valid while every other session of the account - which
     * still carries the old stamp - is logged out.
     */
    public function refreshStamp(): void
    {
        $accountId = Session::get('account_id');
        if ($accountId === null) {
            return;
        }

        $stmt = $this->db->prepare('SELECT password_hash FROM account WHERE id = :id');
        $stmt->execute(['id' => (int) $accountId]);
        Session::set('auth_stamp', self::stamp((string) $stmt->fetchColumn()));
    }

    /**
     * Signs in as another account on behalf of an administrator. The
     * administrator is remembered for ending it; the account's last login is
     * not changed by it.
     */
    public function impersonate(int $accountId, int $administrator): void
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM account WHERE id = :id');
        $stmt->execute(['id' => $accountId]);

        Session::regenerate();
        Session::set('account_id', $accountId);
        Session::set('auth_stamp', self::stamp((string) $stmt->fetchColumn()));
        Session::set('impersonator', $administrator);
        $this->accountLoaded = false;
        $this->permissions = null;
    }

    /** The administrator who signed in as the current account, or null. */
    public function impersonator(): ?int
    {
        $value = Session::get('impersonator');

        return $value === null ? null : (int) $value;
    }

    /** Back to the administrator's own account. Null when no one was impersonated. */
    public function endImpersonation(): ?int
    {
        $administrator = $this->impersonator();
        if ($administrator === null) {
            return null;
        }

        Session::regenerate();
        Session::remove('impersonator');
        Session::set('account_id', $administrator);
        $stmt = $this->db->prepare('SELECT password_hash FROM account WHERE id = :id');
        $stmt->execute(['id' => $administrator]);
        Session::set('auth_stamp', self::stamp((string) $stmt->fetchColumn()));
        $this->accountLoaded = false;
        $this->permissions = null;

        return $administrator;
    }

    /** Whether an account holds a permission, through its roles ("*" holds all). */
    public function accountCan(int $accountId, string $permission): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM account_role ar JOIN role_permission rp ON rp.role_id = ar.role_id
             WHERE ar.account_id = :id AND rp.permission IN (:permission, '*')"
        );
        $stmt->execute(['id' => $accountId, 'permission' => $permission]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function logout(): void
    {
        Session::destroy();
        $this->accountLoaded = true;
        $this->account = null;
        $this->permissions = null;
    }

    public function check(): bool
    {
        return $this->account() !== null;
    }

    /**
     * Re-read on every request, so two things take effect on the very next
     * request rather than at the next login: blocking an account, and a
     * changed password (the session's stamp no longer matches, which logs
     * out a session an intruder may still hold).
     *
     * @return array{id: int, email: string, display_name: ?string, locale: string, created_at: string}|null
     */
    public function account(): ?array
    {
        if ($this->accountLoaded) {
            return $this->account;
        }

        $this->accountLoaded = true;
        $accountId = Session::get('account_id');
        if ($accountId === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id, email, display_name, locale, created_at, password_hash
             FROM account WHERE id = :id AND status = 'active'"
        );
        $stmt->execute(['id' => (int) $accountId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        // A session from before stamps existed (logged in on a version
        // older than 0.2.0) has none; it receives one instead of being
        // logged out by the update.
        if (Session::get('auth_stamp') === null) {
            Session::set('auth_stamp', self::stamp($row['password_hash']));
        }
        if (!hash_equals(self::stamp($row['password_hash']), (string) Session::get('auth_stamp'))) {
            return null;
        }

        unset($row['password_hash']);
        $row['id'] = (int) $row['id'];
        $this->account = $row;

        return $this->account;
    }

    public function can(string $permission): bool
    {
        $account = $this->account();
        if ($account === null) {
            return false;
        }

        if ($this->permissions === null) {
            $stmt = $this->db->prepare(
                'SELECT DISTINCT rp.permission
                 FROM account_role ar
                 JOIN role_permission rp ON rp.role_id = ar.role_id
                 WHERE ar.account_id = :id'
            );
            $stmt->execute(['id' => $account['id']]);
            $this->permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        return in_array(self::WILDCARD, $this->permissions, true)
            || in_array($permission, $this->permissions, true);
    }

    /** A fingerprint of the password hash - reveals nothing about it, changes whenever it does. */
    public static function stamp(string $passwordHash): string
    {
        return substr(hash('sha256', 'auth-stamp:' . $passwordHash), 0, 32);
    }
}
