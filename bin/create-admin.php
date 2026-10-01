<?php

declare(strict_types=1);

// Usage: php bin/create-admin.php admin@example.com
// Creates the account (or resets its password) and gives it the admin role.
// The web installer does the same for the first admin; this is for later
// ones and for a lost password.

use Modulento\Core\Support\AdminAccount;
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

if (strlen($password) < AdminAccount::MIN_PASSWORD_LENGTH) {
    fwrite(STDERR, "The password needs at least 12 characters.\n");
    exit(1);
}

$config = require $root . '/config/config.php';
AdminAccount::create(Database::connect($config['db']), $email, $password);

echo "Admin account ready: {$email}\n";
