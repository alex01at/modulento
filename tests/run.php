<?php

declare(strict_types=1);

// Plain-PHP checks for everything that needs no database:
//   php tests/run.php
// Exit code 0 means every check passed.

use Modulento\Core\Extension\Manifest;
use Modulento\Core\Support\Csrf;
use Modulento\Core\Support\Events;
use Modulento\Core\Support\Migrator;
use Modulento\Core\Support\Money;
use Modulento\Core\Support\Scheduler;
use Modulento\Core\Support\Translator;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$failures = 0;
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        echo "FAIL  {$label}\n";
    }
}

function throws(string $exceptionClass, callable $fn): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $exceptionClass;
    }

    return false;
}

function manifestDir(string $folder, ?string $json): string
{
    $dir = sys_get_temp_dir() . '/modulento-test-' . bin2hex(random_bytes(4)) . '/' . $folder;
    mkdir($dir, 0777, true);
    if ($json !== null) {
        file_put_contents($dir . '/extension.json', $json);
    }

    return $dir;
}

// --- Money ---------------------------------------------------------------
check('money de', Money::format(123450, 'EUR', 'de') === '1.234,50 €');
check('money en', Money::format(123450, 'EUR', 'en') === '€1,234.50');
check('money negative', Money::format(-5, 'EUR', 'de') === '-0,05 €');
check('money multi-letter symbol', Money::format(1000, 'CHF', 'en') === 'CHF 10.00');
check('money unknown currency', Money::format(1000, 'SEK', 'de') === '10,00 SEK');

// --- Translator ----------------------------------------------------------
$translator = new Translator('de', 'Testseite');
$translator->load($root . '/core/lang', 'core');
check('trans placeholder', $translator->trans('core.home.title') === 'Willkommen bei Testseite');
check('trans unknown key falls back to key', $translator->trans('core.nope') === 'core.nope');
check('trans rejects foreign prefix', throws(LogicException::class, fn () => $translator->load($root . '/core/lang', 'other')));
check('locale: german preferred', Translator::detectLocale('de-AT,de;q=0.9,en;q=0.8') === 'de');
check('locale: quality order', Translator::detectLocale('fr;q=0.9,en;q=0.8,de;q=0.7') === 'en');
check('locale: unsupported', Translator::detectLocale('fr-FR,fr;q=0.9') === 'en');
check('locale: missing header', Translator::detectLocale(null) === 'en');

// Every language folder must have identical keys in all locales.
foreach ([$root . '/core/lang', ...glob($root . '/extensions/*/lang')] as $langDir) {
    $de = array_keys(require $langDir . '/de.php');
    $en = array_keys(require $langDir . '/en.php');
    check("lang parity {$langDir}", array_diff($de, $en) === [] && array_diff($en, $de) === []);
}

// --- Migrator ------------------------------------------------------------
$statements = Migrator::statements("-- comment\nCREATE TABLE a (\n  id INT\n);\n\nINSERT INTO a VALUES (1); \nINSERT INTO a VALUES ('x;y')");
check('migrator splits statements', count($statements) === 3);
check('migrator keeps semicolon inside a line', $statements[2] === "INSERT INTO a VALUES ('x;y')");
check('migrator drops comments', !str_contains($statements[0], 'comment'));
check('core migration parses', count(Migrator::statements((string) file_get_contents($root . '/core/migrations/001_core.sql'))) === 9);

// --- Scheduler -----------------------------------------------------------
$now = strtotime('2026-01-01 12:00:00 UTC');
check('task never run is due', Scheduler::isDue(null, 5, $now));
check('task run 5 min ago is due', Scheduler::isDue('2026-01-01 11:55:00', 5, $now));
check('task run 4:45 ago is due (tolerance)', Scheduler::isDue('2026-01-01 11:55:15', 5, $now));
check('task run 2 min ago is not due', !Scheduler::isDue('2026-01-01 11:58:00', 5, $now));

// --- Events --------------------------------------------------------------
$events = new Events();
$seen = [];
$events->listen(stdClass::class, function (object $event) use (&$seen): void {
    $seen[] = 'a';
});
$events->listen(stdClass::class, function (object $event) use (&$seen): void {
    $seen[] = 'b';
});
$events->dispatch(new stdClass());
$events->dispatch(new ArrayObject());
check('events reach listeners in order, only for their class', $seen === ['a', 'b']);

// --- Csrf ----------------------------------------------------------------
$_SESSION = [];
check('csrf rejects when no token exists', !Csrf::verify(''));
$token = Csrf::token();
check('csrf accepts own token', Csrf::verify($token));
check('csrf rejects wrong token', !Csrf::verify('nope'));
check('csrf rejects missing token', !Csrf::verify(null));

// --- Manifest ------------------------------------------------------------
$valid = '{"id":"shop","name":"Shop","version":"1.0.0","api":1,"namespace":"Acme\\\\Shop"}';
$manifest = Manifest::fromDir(manifestDir('shop', $valid));
check('manifest entry class', $manifest->entry === 'Acme\\Shop\\Extension');
check('manifest rejects folder mismatch', throws(InvalidArgumentException::class, fn () => Manifest::fromDir(manifestDir('other', $valid))));
check('manifest rejects missing file', throws(InvalidArgumentException::class, fn () => Manifest::fromDir(manifestDir('shop', null))));
check('manifest rejects broken json', throws(InvalidArgumentException::class, fn () => Manifest::fromDir(manifestDir('shop', '{'))));
check('manifest rejects id "core"', throws(InvalidArgumentException::class, fn () => Manifest::fromDir(manifestDir('core', str_replace('shop', 'core', $valid)))));
check('manifest rejects path-like id', throws(InvalidArgumentException::class, fn () => Manifest::fromDir(manifestDir('shop', str_replace('"shop"', '"../x"', $valid)))));
check('manifest rejects string api', throws(InvalidArgumentException::class, fn () => Manifest::fromDir(manifestDir('shop', str_replace('"api":1', '"api":"1"', $valid)))));
check('example extension manifest is valid', Manifest::fromDir($root . '/extensions/example')->id === 'example');

// --- Router access control and extension loading -------------------------
// Runs against an in-memory SQLite database with just the tables Auth and
// ExtensionManager read. Everything that needs MariaDB itself (migrations,
// login, scheduler runs) is not covered here.
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE account (id INTEGER PRIMARY KEY, email TEXT, status TEXT, locale TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE role (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE role_permission (role_id INTEGER, permission TEXT)");
$pdo->exec("CREATE TABLE account_role (account_id INTEGER, role_id INTEGER)");
$pdo->exec("CREATE TABLE extension (id TEXT PRIMARY KEY, version TEXT, enabled INTEGER)");
$pdo->exec("INSERT INTO account VALUES (1, 'plain@example.test', 'active', 'de', ''), (2, 'editor@example.test', 'active', 'de', ''),
    (3, 'admin@example.test', 'active', 'de', ''), (4, 'blocked@example.test', 'blocked', 'de', '')");
$pdo->exec("INSERT INTO role VALUES (1, 'editor'), (2, 'admin')");
$pdo->exec("INSERT INTO role_permission VALUES (1, 'demo.edit'), (2, '*')");
$pdo->exec("INSERT INTO account_role VALUES (2, 1), (3, 2), (4, 2)");
$pdo->exec("INSERT INTO extension VALUES ('example', '0.1.0', 1)");

$config = ['app' => ['env' => 'dev', 'url' => '', 'name' => 'Testseite', 'theme' => '', 'root' => $root]];

/** @return array{called: bool, status: int, body: string} */
function request(PDO $pdo, array $config, string $method, string $path, ?int $accountId, array $post = []): array
{
    $_SESSION = $accountId === null ? [] : ['account_id' => $accountId];
    $_SESSION['_csrf'] = 'test-token';
    $_POST = $post;
    $_SERVER['REQUEST_URI'] = $path;
    unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_REFERER']);
    http_response_code(200);

    $app = new Modulento\Core\App($config, $pdo, 'de');
    $app->translator->load($config['app']['root'] . '/core/lang', 'core');

    $called = false;
    $handler = function (array $params, Modulento\Core\App $app) use (&$called): void {
        $called = true;
    };
    $app->router->get('/open', $handler, Modulento\Core\Support\Router::PUBLIC);
    $app->router->get('/members', $handler);
    $app->router->get('/edit/{id}', $handler, 'demo.edit');
    $app->router->post('/save', $handler);
    $app->router->post('/hook', $handler, Modulento\Core\Support\Router::PUBLIC, csrfExempt: true);
    $app->extensions->loadEnabled($app);

    ob_start();
    $app->router->dispatch($method, $path);
    $body = (string) ob_get_clean();

    return ['called' => $called, 'status' => (int) http_response_code(), 'body' => $body];
}

$r = request($pdo, $config, 'GET', '/open', null);
check('public route runs for a visitor', $r['called']);
$r = request($pdo, $config, 'GET', '/members', null);
check('login-only route does not run for a visitor', !$r['called'] && $r['body'] === '');
$r = request($pdo, $config, 'GET', '/members', 1);
check('login-only route runs for an account', $r['called']);
$r = request($pdo, $config, 'GET', '/members', 4);
check('blocked account is treated as a visitor', !$r['called']);
$r = request($pdo, $config, 'GET', '/edit/7', 1);
check('permission route: 403 without the permission', !$r['called'] && $r['status'] === 403 && str_contains($r['body'], 'Kein Zugriff'));
$r = request($pdo, $config, 'GET', '/edit/7', 2);
check('permission route runs with the permission', $r['called']);
$r = request($pdo, $config, 'GET', '/edit/7', 3);
check('wildcard role passes any permission', $r['called']);
$r = request($pdo, $config, 'GET', '/edit/7', 4);
check('blocked admin passes nothing', !$r['called']);
$r = request($pdo, $config, 'POST', '/save', 1);
check('POST without CSRF token does not run', !$r['called']);
$r = request($pdo, $config, 'POST', '/save', 1, ['_csrf' => 'wrong']);
check('POST with wrong CSRF token does not run', !$r['called']);
$r = request($pdo, $config, 'POST', '/save', 1, ['_csrf' => 'test-token']);
check('POST with CSRF token runs', $r['called']);
$r = request($pdo, $config, 'POST', '/save', null, ['_csrf' => 'test-token']);
check('POST by a visitor to a login-only route: 403', !$r['called'] && $r['status'] === 403);
$r = request($pdo, $config, 'POST', '/hook', null);
check('CSRF-exempt public POST runs', $r['called']);
$r = request($pdo, $config, 'GET', '/nowhere', 3);
check('unknown path: 404', !$r['called'] && $r['status'] === 404 && str_contains($r['body'], 'Seite nicht gefunden'));

$r = request($pdo, $config, 'GET', '/example', null);
check('enabled extension: route, template and language file work', $r['status'] === 200 && str_contains($r['body'], 'Diese Seite stammt aus der Erweiterung'));
$r = request($pdo, $config, 'GET', '/admin/example', 2);
check('extension permission route: 403 without it', $r['status'] === 403);
$pdo->exec("CREATE TABLE x_example_login (id INTEGER PRIMARY KEY, account_id INTEGER, logged_in_at TEXT)");
$r = request($pdo, $config, 'GET', '/admin/example', 3);
check('extension admin page renders for an admin, with its menu entry', $r['status'] === 200
    && str_contains($r['body'], 'href="/admin/example"') && str_contains($r['body'], 'noch niemand angemeldet'));

$pdo->exec("UPDATE extension SET enabled = 0");
$r = request($pdo, $config, 'GET', '/example', null);
check('disabled extension: its route is gone', $r['status'] === 404);

// --- Updater: applying a package -----------------------------------------
// Downloading from GitHub is not covered; installPackage() is everything
// that happens after the download.

/** @param array<string, string> $files @param array<string, string> $symlinks name => target */
function buildPackage(string $path, array $files, array $symlinks = []): string
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
    }
    foreach ($symlinks as $name => $target) {
        $zip->addFromString($name, $target);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
    }
    $zip->close();

    return (string) hash_file('sha256', $path);
}

function installation(): string
{
    $dir = sys_get_temp_dir() . '/modulento-test-' . bin2hex(random_bytes(4));
    foreach (['core/src', 'public', 'vendor', 'var/log', 'extensions/thirdparty'] as $sub) {
        mkdir($dir . '/' . $sub, 0777, true);
    }
    file_put_contents($dir . '/core/src/App.php', 'old core');
    file_put_contents($dir . '/public/index.php', 'old index');
    file_put_contents($dir . '/.env', 'DB_PASS="keep"');
    file_put_contents($dir . '/var/log/app.log', 'keep');
    file_put_contents($dir . '/extensions/thirdparty/extension.json', 'keep');
    file_put_contents($dir . '/VERSION', '0.1.0');

    return $dir;
}

$package = [
    'public/index.php' => 'new index',
    'core/src/App.php' => 'new core',
    'core/src/New.php' => 'added',
    'vendor/autoload.php' => 'autoload',
    'composer.json' => '{}',
    'VERSION' => '0.2.0',
    '.env' => 'DB_PASS="from package"',
    'var/log/app.log' => 'from package',
];

$dir = installation();
$migrated = 0;
$updater = new Modulento\Core\Support\Updater($dir, 'owner/repo', '', function () use (&$migrated): void {
    $migrated++;
});
$zipPath = $dir . '/package.zip';
$sha = buildPackage($zipPath, $package);
$backup = $updater->installPackage($zipPath, '0.2.0', $sha);

check('update replaces and adds files', file_get_contents($dir . '/core/src/App.php') === 'new core' && is_file($dir . '/core/src/New.php'));
check('update writes the new version', $updater->currentVersion() === '0.2.0');
check('update leaves .env alone', file_get_contents($dir . '/.env') === 'DB_PASS="keep"');
check('update leaves var/ alone', file_get_contents($dir . '/var/log/app.log') === 'keep');
check('update keeps third-party extensions', is_file($dir . '/extensions/thirdparty/extension.json'));
check('update runs the migrations once', $migrated === 1);
check('update removes the downloaded package', !is_file($zipPath));

$backupZip = new ZipArchive();
$backupZip->open($backup);
check('backup holds the previous files', $backupZip->getFromName('core/src/App.php') === 'old core' && $backupZip->getFromName('.env') !== false);
check('backup leaves out logs', $backupZip->getFromName('var/log/app.log') === false);
$backupZip->close();

/** Whether installPackage() refuses the package and leaves the installation as it was. */
function refuses(string $expectedKey, array $files, array $symlinks = [], string $version = '0.2.0', ?string $sha = null): bool
{
    $dir = installation();
    $migrated = 0;
    $updater = new Modulento\Core\Support\Updater($dir, 'owner/repo', '', function () use (&$migrated): void {
        $migrated++;
    });
    $zipPath = $dir . '/package.zip';
    $realSha = buildPackage($zipPath, $files, $symlinks);

    try {
        $updater->installPackage($zipPath, $version, $sha ?? $realSha);
    } catch (Modulento\Core\Support\UpdateException $e) {
        return $e->messageKey === $expectedKey
            && file_get_contents($dir . '/core/src/App.php') === 'old core'
            && $updater->currentVersion() === '0.1.0'
            && $migrated === 0;
    }

    return false;
}

check('update refuses a wrong checksum', refuses('core.update.error.checksum', $package, sha: str_repeat('0', 64)));
check('update refuses a path leaving the folder', refuses('core.update.error.unsafe_entry', $package + ['../evil.php' => 'x']));
check('update refuses an absolute path', refuses('core.update.error.unsafe_entry', $package + ['/etc/evil' => 'x']));
check('update refuses a symlink entry', refuses('core.update.error.unsafe_entry', $package, ['public/link' => '/etc/passwd']));
check('update refuses an incomplete package', refuses('core.update.error.incomplete', array_diff_key($package, ['vendor/autoload.php' => 1])));
check('update refuses a package whose VERSION differs', refuses('core.update.error.version_mismatch', $package, version: '0.3.0'));

$dir = installation();
mkdir($dir . '/.git');
$updater = new Modulento\Core\Support\Updater($dir, 'owner/repo', '', fn () => null);
$result = $updater->applyUpdate();
check('update refuses to touch a git working copy', !$result['success'] && $result['message_key'] === 'core.update.error.dev_checkout');
check('updater is off without a repository', !(new Modulento\Core\Support\Updater($dir, '', '', fn () => null))->isEnabled());

echo $failures === 0 ? "OK ({$checks} checks)\n" : "{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
