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
    // Printed at the end: any output here would count as "headers sent"
    // and stop the checks that follow from reading a response status.
    if (!$condition) {
        $failures++;
        $GLOBALS['failed'][] = $label;
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
$pdo->exec("CREATE TABLE account (id INTEGER PRIMARY KEY, email TEXT UNIQUE, display_name TEXT, password_hash TEXT, status TEXT,
    email_verified_at TEXT, locale TEXT, created_at TEXT, last_login_at TEXT)");
$pdo->exec("CREATE TABLE account_token (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    purpose TEXT, token_hash TEXT UNIQUE, payload TEXT, expires_at TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE rate_limit_attempt (id INTEGER PRIMARY KEY, action TEXT, identifier TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE x_example_login (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, logged_in_at TEXT)");
$pdo->exec("PRAGMA foreign_keys = ON");
$pdo->exec("CREATE TABLE role (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE role_permission (role_id INTEGER, permission TEXT)");
$pdo->exec("CREATE TABLE account_role (account_id INTEGER, role_id INTEGER)");
$pdo->exec("CREATE TABLE extension (id TEXT PRIMARY KEY, version TEXT, enabled INTEGER)");
$pdo->exec("CREATE TABLE setting (name TEXT PRIMARY KEY, value TEXT)");
$testHash = password_hash('correct horse battery', PASSWORD_BCRYPT, ['cost' => 4]);
$insertAccount = $pdo->prepare("INSERT INTO account (id, email, password_hash, status, email_verified_at, locale, created_at) VALUES (?, ?, ?, ?, '2026-01-01 00:00:00', 'de', '2026-01-01 00:00:00')");
foreach ([[1, 'plain@example.test', 'active'], [2, 'editor@example.test', 'active'], [3, 'admin@example.test', 'active'], [4, 'blocked@example.test', 'blocked']] as [$id, $email, $status]) {
    $insertAccount->execute([$id, $email, $testHash, $status]);
}
$pdo->exec("INSERT INTO role VALUES (1, 'editor'), (2, 'admin')");
$pdo->exec("INSERT INTO role_permission VALUES (1, 'demo.edit'), (2, '*')");
$pdo->exec("INSERT INTO account_role VALUES (2, 1), (3, 2), (4, 2)");
$pdo->exec("INSERT INTO extension VALUES ('example', '0.1.0', 1)");

$mailLog = sys_get_temp_dir() . '/modulento-test-mail-' . bin2hex(random_bytes(4)) . '.log';
$config = [
    'app' => ['env' => 'dev', 'url' => 'https://example.test', 'name' => 'Testseite', 'root' => $root, 'cron_token' => 'secret-cron-token'],
    'mail' => ['from' => 'noreply@example.test', 'transport' => 'log', 'log_path' => $mailLog],
];

/** The newest logged mail to an address, and the link inside it. @return array{subject: string, link: string}|null */
function lastMail(string $mailLog, string $to): ?array
{
    $mails = is_file($mailLog) ? array_filter(explode("\n--\n", (string) file_get_contents($mailLog))) : [];
    foreach (array_reverse($mails) as $mail) {
        if (str_starts_with(ltrim($mail), 'To: ' . $to . "\n")) {
            preg_match('/^Subject: (.*)$/m', $mail, $subject);
            preg_match('#https://example\.test(/\S+)#', $mail, $link);

            return ['subject' => $subject[1] ?? '', 'link' => $link[1] ?? ''];
        }
    }

    return null;
}

/** @return array{called: bool, status: int, body: string} */
function request(PDO $pdo, array $config, string $method, string $path, int|false|null $accountId, array $post = []): array
{
    // $accountId false keeps the session of the previous request, as a
    // browser would; null starts as a visitor; an id starts logged in.
    if ($accountId !== false) {
        $_SESSION = [];
        if ($accountId !== null) {
            $hash = $pdo->query('SELECT password_hash FROM account WHERE id = ' . (int) $accountId)->fetchColumn();
            $_SESSION = ['account_id' => $accountId, 'auth_stamp' => Modulento\Core\Support\Auth::stamp((string) $hash)];
        }
    }
    $_SESSION['_csrf'] = 'test-token';
    if ($method === 'POST') {
        $post += ['_csrf' => 'test-token'];
    }
    $_POST = $post;
    $_SERVER['REQUEST_URI'] = $path;
    unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_REFERER']);
    http_response_code(200);

    $app = new Modulento\Core\App($config, $pdo, 'de');
    $app->translator->load($config['app']['root'] . '/core/lang', 'core');

    Modulento\Core\Kernel::registerCore($app);

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
$r = request($pdo, $config, 'POST', '/save', 1, ['_csrf' => '']);
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
$r = request($pdo, $config, 'GET', '/admin/example', 3);
check('extension admin page renders for an admin, with its menu entry', $r['status'] === 200
    && str_contains($r['body'], 'href="/admin/example"') && str_contains($r['body'], 'noch niemand angemeldet'));

$pdo->exec("UPDATE extension SET enabled = 0");
$r = request($pdo, $config, 'GET', '/example', null);
check('disabled extension: its route is gone', $r['status'] === 404);

// --- Accounts: register, confirm, log in, reset, change, export, delete ---
$post = fn (string $path, array $fields, int|false|null $as = false) => request($pdo, $config, 'POST', $path, $as, $fields);
$get = fn (string $path, int|false|null $as = false) => request($pdo, $config, 'GET', $path, $as);
$row = fn (string $email) => $pdo->query("SELECT * FROM account WHERE email = " . $pdo->quote($email))->fetch();
$pw = 'a new long password';

$r = $post('/register', ['email' => 'New@Example.test ', 'password' => 'short', 'password_repeat' => 'short'], null);
check('register: short password is refused', str_contains($r['body'], 'mindestens 12 Zeichen') && $row('new@example.test') === false);
$r = $post('/register', ['email' => 'new@example.test', 'password' => $pw, 'password_repeat' => $pw . 'x'], null);
check('register: differing repeat is refused', $row('new@example.test') === false);
$r = $post('/register', ['email' => 'bot@example.test', 'password' => $pw, 'password_repeat' => $pw, 'website' => 'http://spam'], null);
check('register: filled bot trap creates nothing', $row('bot@example.test') === false && lastMail($mailLog, 'bot@example.test') === null);

$post('/register', ['email' => 'New@Example.test ', 'password' => $pw, 'password_repeat' => $pw], null);
$new = $row('new@example.test');
check('register: account is created unconfirmed, address normalised', $new !== false && $new['email_verified_at'] === null && password_verify($pw, $new['password_hash']));
$mail = lastMail($mailLog, 'new@example.test');
check('register: confirmation mail with link', $mail !== null && str_starts_with($mail['link'], '/verify-email/') && $mail['subject'] === 'Bitte bestätige deine E-Mail-Adresse');
check('token is stored hashed only', $pdo->query("SELECT COUNT(*) FROM account_token WHERE token_hash = " . $pdo->quote(substr($mail['link'], 14)))->fetchColumn() == 0
    && $pdo->query("SELECT COUNT(*) FROM account_token WHERE token_hash = " . $pdo->quote(hash('sha256', substr($mail['link'], 14))))->fetchColumn() == 1);

$post('/login', ['email' => 'new@example.test', 'password' => $pw], null);
check('login: refused while unconfirmed', $get('/account')['body'] === '' && ($_SESSION['account_id'] ?? null) === null);
$post('/verify-email/resend', []);
$resent = lastMail($mailLog, 'new@example.test');
check('resend: new link, old one stops working', $resent['link'] !== $mail['link']);
$get($mail['link'], null);
check('verify: replaced link is refused', $row('new@example.test')['email_verified_at'] === null);
$get($resent['link'], null);
check('verify: link confirms the address', $row('new@example.test')['email_verified_at'] !== null);
$get($resent['link'], null);
check('verify: link works once', $pdo->query('SELECT COUNT(*) FROM account_token')->fetchColumn() == 0);

$post('/register', ['email' => 'new@example.test', 'password' => $pw, 'password_repeat' => $pw], null);
check('register again: no second account, owner is told', $pdo->query("SELECT COUNT(*) FROM account WHERE email = 'new@example.test'")->fetchColumn() == 1
    && lastMail($mailLog, 'new@example.test')['subject'] === 'Du hast bereits ein Konto');

$post('/login', ['email' => 'new@example.test', 'password' => 'wrong password!'], null);
check('login: wrong password is refused', ($_SESSION['account_id'] ?? null) === null);
$post('/login', ['email' => 'new@example.test', 'password' => $pw], null);
check('login: works after confirmation', ($_SESSION['account_id'] ?? null) == $new['id']);
$r = $get('/account');
check('account page renders for the logged-in account', $r['status'] === 200 && str_contains($r['body'], 'new@example.test'));

$post('/account/profile', ['display_name' => 'Neue Person', 'locale' => 'en']);
check('profile: name and language are saved', $row('new@example.test')['display_name'] === 'Neue Person' && $row('new@example.test')['locale'] === 'en');
$post('/account/profile', ['display_name' => 'x', 'locale' => 'xx']);
check('profile: unknown language is refused', $row('new@example.test')['display_name'] === 'Neue Person');

$post('/account/password', ['current_password' => 'wrong', 'password' => 'another long password', 'password_repeat' => 'another long password']);
check('password change: needs the current password', password_verify($pw, $row('new@example.test')['password_hash']));
$otherSession = $_SESSION;
$post('/account/password', ['current_password' => $pw, 'password' => 'another long password', 'password_repeat' => 'another long password']);
$pw = 'another long password';
check('password change: saved, this session stays logged in', password_verify($pw, $row('new@example.test')['password_hash']) && $get('/account')['status'] === 200);
check('password change: owner is notified', lastMail($mailLog, 'new@example.test')['subject'] === 'Dein Passwort wurde geändert');
$current = $_SESSION;
$_SESSION = $otherSession;
check('password change: a session with the old password is logged out', $get('/account')['body'] === '');
$_SESSION = $current;

$post('/account/email', ['email' => 'admin@example.test', 'current_password' => $pw]);
check('email change: an address in use sends nothing', lastMail($mailLog, 'admin@example.test') === null);
$post('/account/email', ['email' => 'moved@example.test', 'current_password' => $pw]);
check('email change: nothing changes before the link is opened', $row('moved@example.test') === false);
$change = lastMail($mailLog, 'moved@example.test');
$current = $_SESSION;
$get($change['link'], 1);
check('email change: link is useless in another account', $row('moved@example.test') === false && $row('plain@example.test') !== false);
$_SESSION = $current;
$post('/account/email', ['email' => 'moved@example.test', 'current_password' => $pw]);
$get(lastMail($mailLog, 'moved@example.test')['link']);
check('email change: confirmed link changes the address', $row('moved@example.test') !== false && $row('new@example.test') === false);

$pdo->exec("UPDATE extension SET enabled = 1");
$r = $get('/account/export');
$pdo->exec("UPDATE extension SET enabled = 0");
$export = json_decode($r['body'], true);
check('export: core data and the extension\'s part', ($export['core']['email'] ?? '') === 'moved@example.test' && !isset($export['core']['password_hash']) && array_key_exists('example', $export));

$post('/forgot-password', ['email' => 'nobody@example.test'], null);
check('forgot: unknown address sends nothing', lastMail($mailLog, 'nobody@example.test') === null);
$post('/forgot-password', ['email' => 'moved@example.test'], null);
$reset = lastMail($mailLog, 'moved@example.test');
check('forgot: reset mail with link', str_starts_with($reset['link'], '/reset-password/'));
check('reset: form opens for a valid link', str_contains($get($reset['link'], null)['body'], 'name="password_repeat"'));
$post($reset['link'], ['password' => 'short', 'password_repeat' => 'short'], null);
check('reset: a refused password does not use up the link', $pdo->query("SELECT COUNT(*) FROM account_token WHERE purpose = 'reset_password'")->fetchColumn() == 1);
$_SESSION = $current;
$post($reset['link'], ['password' => 'the third long password', 'password_repeat' => 'the third long password'], null);
check('reset: new password is set', password_verify('the third long password', $row('moved@example.test')['password_hash']));
$_SESSION = $current;
check('reset: sessions with the old password are logged out', $get('/account')['body'] === '');
$post($reset['link'], ['password' => 'a fourth long password', 'password_repeat' => 'a fourth long password'], null);
check('reset: link works once', password_verify('the third long password', $row('moved@example.test')['password_hash']));
$pdo->exec("UPDATE account_token SET expires_at = '2020-01-01 00:00:00'");
$post('/forgot-password', ['email' => 'moved@example.test'], null);
$expired = lastMail($mailLog, 'moved@example.test');
$pdo->exec("UPDATE account_token SET expires_at = '2020-01-01 00:00:00'");
$post($expired['link'], ['password' => 'a fourth long password', 'password_repeat' => 'a fourth long password'], null);
check('reset: expired link is refused', password_verify('the third long password', $row('moved@example.test')['password_hash']));

for ($i = 0; $i < 6; $i++) {
    $post('/login', ['email' => 'plain@example.test', 'password' => 'guess number ' . $i], null);
}
$post('/login', ['email' => 'plain@example.test', 'password' => 'correct horse battery'], null);
check('login: locked after repeated wrong passwords, even with the right one', ($_SESSION['account_id'] ?? null) === null);
$pdo->exec('DELETE FROM rate_limit_attempt');

$post('/login', ['email' => 'moved@example.test', 'password' => 'the third long password'], null);
$post('/account/delete', ['current_password' => 'wrong']);
check('delete: needs the current password', $row('moved@example.test') !== false);
$post('/account/delete', ['current_password' => 'the third long password']);
check('delete: account, its tokens and its session are gone', $row('moved@example.test') === false && ($_SESSION['account_id'] ?? null) === null
    && $pdo->query('SELECT COUNT(*) FROM account_token')->fetchColumn() == 0);

$pdo->exec("UPDATE account SET status = 'blocked' WHERE id = 4");
$post('/account/delete', ['current_password' => 'correct horse battery'], 3);
check('delete: the only administrator cannot delete itself', $row('admin@example.test') !== false);

$accounts = new Modulento\Core\Account\Accounts($pdo);
$accounts->create('stale@example.test', $pw, 'de', verified: false);
$accounts->create('fresh@example.test', $pw, 'de', verified: false);
$pdo->exec("UPDATE account SET created_at = '2020-01-01 00:00:00' WHERE email = 'stale@example.test'");
check('cleanup: only old unconfirmed registrations are removed', $accounts->deleteUnverifiedOlderThan(7 * 86400) === 1
    && $row('fresh@example.test') !== false && $row('admin@example.test') !== false);
$pdo->exec("DELETE FROM account WHERE email = 'fresh@example.test'");
@unlink($mailLog);

// --- Router: rest-of-path parameter --------------------------------------
$r = request($pdo, $config, 'GET', '/assets/theme/theme.css', null);
check('theme asset is served through the router', $r['status'] === 200 && str_contains($r['body'], '.site-header'));
$r = request($pdo, $config, 'GET', '/assets/admin/admin.css', null);
check('admin asset is served', $r['status'] === 200 && str_contains($r['body'], '.admin-menu'));
$r = request($pdo, $config, 'GET', '/assets/theme/../theme.json', null);
check('asset route does not leave the assets folder', $r['status'] === 404);
$r = request($pdo, $config, 'GET', '/assets/theme/missing.css', null);
check('missing asset: 404', $r['status'] === 404);

$r = request($pdo, $config, 'GET', '/cron/wrong', null);
check('cron URL with a wrong token: 404', $r['status'] === 404);
$r = request($pdo, $config, 'GET', '/admin/themes', 1);
check('theme administration needs its permission', $r['status'] === 403);
$r = request($pdo, $config, 'GET', '/admin/themes', 3);
check('theme administration lists the default theme', $r['status'] === 200 && str_contains($r['body'], '(default)') && !str_contains($r['body'], '(admin)'));

// --- Assets --------------------------------------------------------------
$assetDir = sys_get_temp_dir() . '/modulento-test-' . bin2hex(random_bytes(4));
mkdir($assetDir . '/assets/img', 0777, true);
file_put_contents($assetDir . '/assets/img/logo.svg', '<svg/>');
file_put_contents($assetDir . '/assets/page.php', '<?php');
file_put_contents($assetDir . '/secret.css', 'x');
symlink($assetDir . '/secret.css', $assetDir . '/assets/link.css');
$locate = fn (string $path) => Modulento\Core\Controller\AssetController::locate([$assetDir . '/assets'], $path);
check('asset in a sub folder is found', $locate('img/logo.svg') !== null);
check('asset: path climbing out is refused', $locate('../secret.css') === null);
check('asset: symlink pointing out is refused', $locate('link.css') === null);
check('asset: non-static file type is refused', $locate('page.php') === null);

// --- Themes --------------------------------------------------------------
$themesDir = sys_get_temp_dir() . '/modulento-test-' . bin2hex(random_bytes(4));
foreach (['default' => ['templates', 'assets'], 'admin' => ['templates'], 'shop' => ['templates'], 'broken' => []] as $id => $subDirs) {
    mkdir($themesDir . '/' . $id, 0777, true);
    foreach ($subDirs as $subDir) {
        mkdir($themesDir . '/' . $id . '/' . $subDir);
    }
    file_put_contents($themesDir . '/' . $id . '/theme.json', $id === 'broken' ? '{' : json_encode(['id' => $id, 'name' => ucfirst($id), 'version' => '1.0.0']));
}
$themes = fn () => new Modulento\Core\Theme\ThemeManager($themesDir, new Modulento\Core\Support\Settings($pdo));
check('themes: admin theme and broken folders are not selectable', array_keys($themes()->siteThemes()) === ['default', 'shop']);
check('themes: default is active when nothing is chosen', $themes()->active() === 'default');
$pdo->exec("INSERT INTO setting VALUES ('core.theme', 'shop')");
check('themes: chosen theme is active', $themes()->active() === 'shop');
check('themes: active theme is searched before default', $themes()->siteDirs('templates') === [$themesDir . '/shop/templates', $themesDir . '/default/templates']);
check('themes: a folder the theme lacks comes from default alone', $themes()->siteDirs('assets') === [$themesDir . '/default/assets']);
$pdo->exec("UPDATE setting SET value = 'gone' WHERE name = 'core.theme'");
check('themes: falls back to default when the chosen folder is gone', $themes()->active() === 'default');
$pdo->exec("UPDATE setting SET value = 'admin' WHERE name = 'core.theme'");
check('themes: the admin theme can never become the site theme', $themes()->active() === 'default');
$pdo->exec("DELETE FROM setting");

// Every template a controller renders must exist in the shipped themes.
foreach (['default' => ['layout/base.twig', 'home.twig', 'error.twig', 'auth/login.twig'], 'admin' => ['layout.twig', 'index.twig', 'extensions.twig', 'themes.twig', 'tasks.twig', 'updates.twig']] as $theme => $templates) {
    foreach ($templates as $template) {
        check("theme {$theme} ships {$template}", is_file("{$root}/themes/{$theme}/templates/{$template}"));
    }
}
check('the core itself contains no theme templates', !is_dir($root . '/core/templates'));

// --- Installer: .env values survive the dotenv parser --------------------
$nasty = ['A' => 'plain', 'B' => 'with space', 'C' => 'p$ss"w\\ord#1', 'D' => '${A}', 'E' => "line\nbreak", 'F' => ''];
$parsed = Dotenv\Dotenv::parse(Modulento\Core\Install\Installer::envFile($nasty));
check('env file round trip', $parsed === ['A' => 'plain', 'B' => 'with space', 'C' => 'p$ss"w\\ord#1', 'D' => '${A}', 'E' => 'linebreak', 'F' => '']);

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
    'themes/default/theme.json' => '{}',
    'themes/admin/theme.json' => '{}',
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

foreach ($GLOBALS['failed'] ?? [] as $label) {
    echo "FAIL  {$label}\n";
}
echo $failures === 0 ? "OK ({$checks} checks)\n" : "{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
