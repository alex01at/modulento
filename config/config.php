<?php

declare(strict_types=1);

use Dotenv\Dotenv;

// Must match Database::connect()'s `SET time_zone = '+00:00'` - PHP and
// MariaDB have to agree on one clock for every deadline the scheduler and
// later the order flows compute.
date_default_timezone_set('UTC');

$root = dirname(__DIR__);

if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

return [
    'app' => [
        'env' => $_ENV['APP_ENV'] ?? 'prod',
        'url' => rtrim($_ENV['APP_URL'] ?? '', '/'),
        'name' => $_ENV['APP_NAME'] ?? 'Modulento',
        // Lets a cron service call /cron/<token> where the hosting panel
        // cannot run bin/cron.php itself. Empty switches that URL off.
        'cron_token' => $_ENV['CRON_TOKEN'] ?? '',
        'root' => $root,
    ],
    'mail' => [
        // Sender address; without one, noreply@<host of APP_URL>.
        'from' => ($_ENV['MAIL_FROM'] ?? '') !== ''
            ? $_ENV['MAIL_FROM']
            : 'noreply@' . (parse_url($_ENV['APP_URL'] ?? '', PHP_URL_HOST) ?: 'localhost'),
        // "dev" never sends: mails are appended to the log file instead.
        'transport' => ($_ENV['APP_ENV'] ?? 'prod') === 'dev' ? 'log' : 'mail',
        'log_path' => $root . '/var/log/mail.log',
    ],
    'update' => [
        // "owner/name" of the GitHub repository whose releases are
        // installed from the administration; empty switches updates off.
        'repo' => $_ENV['UPDATE_REPO'] ?? 'alex01at/modulento',
        'token' => $_ENV['UPDATE_TOKEN'] ?? '',
    ],
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'name' => $_ENV['DB_NAME'] ?? '',
        'user' => $_ENV['DB_USER'] ?? '',
        'pass' => $_ENV['DB_PASS'] ?? '',
    ],
];
