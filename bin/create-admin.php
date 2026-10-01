<?php

declare(strict_types=1);

// Usage: php bin/create-admin.php admin@example.com
// Creates the account (or resets its password) and gives it the admin role.
// Registration through the web follows in stage 2.

use Modulento\Core\Support\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$email = strtolower(trim($argv[1] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <e-mail>\n");
    exit(1);
}

// Read from STDIN rather than an argument, so the password does not end up
// in the shell history or the process list.
fwrite(STDOUT, 'Password (at least 12 characters): ');
$isTerminal = stream_isatty(STDIN);
if ($isTerminal) {
    shell_exec('stty -echo');
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($isTerminal) {
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");
}

if (strlen($password) < 12) {
    fwrite(STDERR, "The password needs at least 12 characters.\n");
    exit(1);
}

$config = require $root . '/config/config.php';
$db = Database::connect($config['db']);

$db->beginTransaction();

$stmt = $db->prepare(
    "INSERT INTO account (email, password_hash, status, created_at) VALUES (:email, :hash, 'active', NOW())
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = 'active'"
);
$stmt->execute(['email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);

$stmt = $db->prepare(
    "INSERT IGNORE INTO account_role (account_id, role_id)
     SELECT a.id, r.id FROM account a JOIN role r ON r.name = 'admin' WHERE a.email = :email"
);
$stmt->execute(['email' => $email]);

$db->commit();

echo "Admin account ready: {$email}\n";
