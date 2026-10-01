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
        Session::regenerate();
        Session::set('account_id', $accountId);
        $this->accountLoaded = false;
        $this->permissions = null;

        $stmt = $this->db->prepare('UPDATE account SET last_login_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $accountId]);
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
     * Re-read on every request and limited to active accounts, so blocking
     * an account takes effect on its very next request instead of only at
     * its next login.
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
            "SELECT id, email, locale, created_at FROM account WHERE id = :id AND status = 'active'"
        );
        $stmt->execute(['id' => (int) $accountId]);
        $this->account = $stmt->fetch() ?: null;

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
            $stmt->execute(['id' => (int) $account['id']]);
            $this->permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        return in_array(self::WILDCARD, $this->permissions, true)
            || in_array($permission, $this->permissions, true);
    }
}
