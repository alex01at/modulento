<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use PDO;

/** Shared by the web installer and bin/create-admin.php. */
final class AdminAccount
{
    public const MIN_PASSWORD_LENGTH = 12;

    /** Creates the account, or resets its password, and gives it the admin role. */
    public static function create(PDO $db, string $email, string $password): void
    {
        $db->beginTransaction();

        $stmt = $db->prepare(
            "INSERT INTO account (email, password_hash, status, created_at) VALUES (:email, :hash, 'active', NOW())
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = 'active'"
        );
        $stmt->execute(['email' => strtolower(trim($email)), 'hash' => password_hash($password, PASSWORD_DEFAULT)]);

        $stmt = $db->prepare(
            "INSERT IGNORE INTO account_role (account_id, role_id)
             SELECT a.id, r.id FROM account a JOIN role r ON r.name = 'admin' WHERE a.email = :email"
        );
        $stmt->execute(['email' => strtolower(trim($email))]);

        $db->commit();
    }
}
