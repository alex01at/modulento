<?php

declare(strict_types=1);

// The PHP built-in dev server (php -S ... public/index.php) invokes this
// script for every request. Returning false lets it serve a real file
// (assets) directly. Apache doesn't use this file as a router, so this has
// no effect in production - public/.htaccess handles it there.
if (PHP_SAPI === 'cli-server') {
    $requestedFile = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($requestedFile)) {
        return false;
    }
}

use Modulento\Core\Install\Installer;
use Modulento\Core\Kernel;
use Modulento\Core\NotConfiguredException;

$root = dirname(__DIR__);

// vendor/ is part of a release package but not of the source code, so this
// is what someone sees who uploaded GitHub's "Download ZIP" instead.
if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Modulento: the folder vendor/ is missing.\n\n"
        . "This is the source code, not a release package. Upload modulento-<version>.zip\n"
        . "from the project's Releases page instead, or run \"composer install\" here.\n";
    return;
}

require $root . '/vendor/autoload.php';

// Errors never reach the visitor; they go to the log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $root . '/var/log/php-error.log');
error_reporting(E_ALL);

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
if (!empty($_SERVER['HTTPS'])) {
    header('Strict-Transport-Security: max-age=31536000');
}

try {
    $app = Kernel::boot($root, web: true);
    if ($app->config['app']['env'] === 'dev') {
        ini_set('display_errors', '1');
    }
    $app->router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (NotConfiguredException $e) {
    // No database settings yet: every request goes to the setup page.
    (new Installer($root))->handle($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (Throwable $e) {
    error_log((string) $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo '500 - Internal error';
}
