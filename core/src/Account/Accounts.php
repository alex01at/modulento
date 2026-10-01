<?php

declare(strict_types=1);

namespace Modulento\Core\Account;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * The only place that reads and writes the account table. Extensions use
 * this class instead of SQL on core tables.
 */
final class Accounts
{
    public function __construct(private PDO $db)
    {
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM account WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM account WHERE email = :email');
        $stmt->execute(['email' => self::normalizeEmail($email)]);

        return $stmt->fetch() ?: null;
    }

    public function create(string $email, string $password, string $locale, bool $verified): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO account (email, password_hash, status, email_verified_at, locale, created_at)
             VALUES (:email, :hash, 'active', :verified_at, :locale, :created_at)"
        );
        $stmt->execute([
            'email' => self::normalizeEmail($email),
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'verified_at' => $verified ? Clock::now() : null,
            'locale' => $locale,
            'created_at' => Clock::now(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function markVerified(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE account SET email_verified_at = :now WHERE id = :id AND email_verified_at IS NULL');
        $stmt->execute(['now' => Clock::now(), 'id' => $id]);
    }

    public function setPassword(int $id, string $password): void
    {
        $stmt = $this->db->prepare('UPDATE account SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id]);
    }

    /** The address was confirmed through the link sent to it, so it counts as verified. */
    public function setEmail(int $id, string $email): void
    {
        $stmt = $this->db->prepare('UPDATE account SET email = :email, email_verified_at = :now WHERE id = :id');
        $stmt->execute(['email' => self::normalizeEmail($email), 'now' => Clock::now(), 'id' => $id]);
    }

    public function updateProfile(int $id, ?string $displayName, string $locale): void
    {
        $stmt = $this->db->prepare('UPDATE account SET display_name = :name, locale = :locale WHERE id = :id');
        $stmt->execute(['name' => $displayName, 'locale' => $locale, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM account WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** Whether deleting this account would leave nobody who may do everything. */
    public function isLastAdmin(int $id): bool
    {
        $admins = $this->db->query(
            "SELECT DISTINCT a.id FROM account a
             JOIN account_role ar ON ar.account_id = a.id
             JOIN role_permission rp ON rp.role_id = ar.role_id
             WHERE rp.permission = '*' AND a.status = 'active'"
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $admins) === [$id];
    }

    /** Registrations whose address was never confirmed. Returns how many were removed. */
    public function deleteUnverifiedOlderThan(int $seconds): int
    {
        $stmt = $this->db->prepare('DELETE FROM account WHERE email_verified_at IS NULL AND created_at < :before');
        $stmt->execute(['before' => Clock::now(-$seconds)]);

        return $stmt->rowCount();
    }
}
