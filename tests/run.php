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
check('trans rejects foreign prefix', throws(LogicException::class, function () use ($root): void {
    $wrong = new Translator('de', 'Testseite');
    $wrong->load($root . '/core/lang', 'other');
    $wrong->trans('core.home.title');
}));
$both = ['de', 'en'];
check('locale: german preferred', Translator::detectLocale('de-AT,de;q=0.9,en;q=0.8', $both, 'en') === 'de');
check('locale: quality order', Translator::detectLocale('fr;q=0.9,en;q=0.8,de;q=0.7', $both, 'de') === 'en');
check('locale: unsupported falls back to the default', Translator::detectLocale('fr-FR,fr;q=0.9', $both, 'de') === 'de');
check('locale: missing header', Translator::detectLocale(null, $both, 'en') === 'en');
check('locale: a language that is not offered is skipped', Translator::detectLocale('de,en;q=0.5', ['en'], 'en') === 'en');

// A language pack that is incomplete, and the site's own wording.
$langDir = sys_get_temp_dir() . '/modulento-test-' . bin2hex(random_bytes(4));
mkdir($langDir . '/pack', 0777, true);
mkdir($langDir . '/site');
file_put_contents($langDir . '/pack/fr.php', "<?php return ['core.nav.login' => 'Connexion'];");
file_put_contents($langDir . '/pack/de.php', "<?php return ['core.nav.login' => 'Anmelden', 'core.nav.logout' => 'Abmelden'];");
file_put_contents($langDir . '/pack/en.php', "<?php return ['core.nav.login' => 'Log in', 'core.nav.logout' => 'Log out', 'core.only.english' => 'English only'];");
file_put_contents($langDir . '/site/de.php', "<?php return ['core.nav.login' => 'Einloggen', 'anything.goes' => 'frei'];");
$partial = new Translator('fr', 'T');
$partial->load($langDir . '/pack', 'core');
$partial->setFallback('de');
check('translator: text in the current language', $partial->trans('core.nav.login') === 'Connexion');
check('translator: missing text comes from the default language', $partial->trans('core.nav.logout') === 'Abmelden');
check('translator: then from English', $partial->trans('core.only.english') === 'English only');
check('translator: explicit language', $partial->trans('core.nav.login', [], 'en') === 'Log in');
check('translator: inLocale switches and restores', $partial->inLocale('en', fn () => $partial->trans('core.nav.login')) === 'Log in' && $partial->locale() === 'fr');
$partial->loadOverrides($langDir . '/site');
check('translator: the site folder rewords and may add any key', $partial->trans('core.nav.login', [], 'de') === 'Einloggen' && $partial->trans('anything.goes', [], 'de') === 'frei');
check('translator: languages are found by file', Translator::localesIn($langDir . '/pack') === ['de', 'en', 'fr']);
file_put_contents($langDir . '/pack/x.php', '<?php return [];');
file_put_contents($langDir . '/pack/de-evil.php', '<?php return [];');
check('translator: only two-letter codes count as languages', Translator::localesIn($langDir . '/pack') === ['de', 'en', 'fr']);

// Every language folder must have identical keys in all locales.
foreach ([$root . '/core/lang', ...glob($root . '/extensions/*/lang'), ...glob($root . '/themes/*/lang')] as $langDir) {
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
    status_note TEXT, email_verified_at TEXT, terms_accepted_at TEXT, locale TEXT, created_at TEXT, last_login_at TEXT)");
$pdo->exec("CREATE TABLE provider (id INTEGER PRIMARY KEY, account_id INTEGER UNIQUE REFERENCES account (id) ON DELETE CASCADE, type TEXT, status TEXT,
    status_note TEXT, name TEXT, slug TEXT UNIQUE, legal_name TEXT, street TEXT, postal_code TEXT, city TEXT, country TEXT, contact_email TEXT, phone TEXT,
    vat_id TEXT, tax_id TEXT, company_register TEXT, self_certified_at TEXT, details_changed_at TEXT, decided_at TEXT, decided_by INTEGER, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE provider_translation (provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, locale TEXT, headline TEXT, description TEXT, PRIMARY KEY (provider_id, locale))");
$pdo->exec("CREATE TABLE page (id INTEGER PRIMARY KEY, status TEXT, role TEXT UNIQUE, in_header INTEGER, in_footer INTEGER, position INTEGER, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE page_translation (page_id INTEGER REFERENCES page (id) ON DELETE CASCADE, locale TEXT, title TEXT, slug TEXT,
    meta_description TEXT, body TEXT, PRIMARY KEY (page_id, locale), UNIQUE (locale, slug))");
$pdo->exec("CREATE TABLE account_token (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    purpose TEXT, token_hash TEXT UNIQUE, payload TEXT, expires_at TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE rate_limit_attempt (id INTEGER PRIMARY KEY, action TEXT, identifier TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE x_example_login (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, logged_in_at TEXT)");
$pdo->exec("CREATE TABLE category (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES category (id), position INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE category_translation (category_id INTEGER REFERENCES category (id) ON DELETE CASCADE, locale TEXT, name TEXT, slug TEXT, PRIMARY KEY (category_id, locale), UNIQUE (locale, slug))");
$pdo->exec("CREATE TABLE offer (id INTEGER PRIMARY KEY, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, type TEXT, category_id INTEGER REFERENCES category (id) ON DELETE SET NULL,
    status TEXT, status_note TEXT, price_from INTEGER, currency TEXT, decided_at TEXT, decided_by INTEGER, published_at TEXT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE offer_translation (offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, locale TEXT, title TEXT, slug TEXT, summary TEXT, description TEXT, PRIMARY KEY (offer_id, locale), UNIQUE (locale, slug))");
$pdo->exec("CREATE TABLE offer_image (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, name TEXT, extension TEXT, width INTEGER, height INTEGER, position INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE x_freelancer_package (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, tier INTEGER, price INTEGER, delivery_days INTEGER, revisions INTEGER, UNIQUE (offer_id, tier))");
$pdo->exec("CREATE TABLE x_freelancer_package_translation (package_id INTEGER REFERENCES x_freelancer_package (id) ON DELETE CASCADE, locale TEXT, name TEXT, description TEXT, PRIMARY KEY (package_id, locale))");
$pdo->exec("CREATE TABLE x_freelancer_extra (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, position INTEGER, price INTEGER, extra_days INTEGER)");
$pdo->exec("CREATE TABLE x_freelancer_extra_translation (extra_id INTEGER REFERENCES x_freelancer_extra (id) ON DELETE CASCADE, locale TEXT, title TEXT, PRIMARY KEY (extra_id, locale))");
$pdo->exec("CREATE TABLE x_freelancer_requirement (offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, locale TEXT, text TEXT, PRIMARY KEY (offer_id, locale))");
$pdo->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY, buyer_id INTEGER REFERENCES account (id) ON DELETE SET NULL, provider_id INTEGER REFERENCES provider (id) ON DELETE SET NULL,
    offer_id INTEGER REFERENCES offer (id) ON DELETE SET NULL, flow TEXT, state TEXT, previous_state TEXT, state_actor TEXT, buyer_name TEXT, provider_name TEXT, offer_title TEXT,
    total INTEGER, currency TEXT, locale TEXT, payment_method TEXT, payment_state TEXT DEFAULT 'unpaid', paid_at TEXT, data TEXT, due_at TEXT, due_transition TEXT,
    terms_accepted_at TEXT, created_at TEXT, updated_at TEXT, closed_at TEXT)");
$pdo->exec("CREATE TABLE order_item (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, position INTEGER, label TEXT, quantity INTEGER, unit_price INTEGER)");
$pdo->exec("CREATE TABLE order_event (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, transition TEXT, from_state TEXT, to_state TEXT,
    actor_id INTEGER REFERENCES account (id) ON DELETE SET NULL, actor_role TEXT, note TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE order_message (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, account_id INTEGER REFERENCES account (id) ON DELETE SET NULL,
    author_role TEXT, body TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE order_file (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, event_id INTEGER REFERENCES order_event (id) ON DELETE CASCADE,
    message_id INTEGER REFERENCES order_message (id) ON DELETE CASCADE, account_id INTEGER REFERENCES account (id) ON DELETE SET NULL, author_role TEXT, original_name TEXT, stored_name TEXT, size INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE review (id INTEGER PRIMARY KEY, order_id INTEGER UNIQUE REFERENCES orders (id) ON DELETE CASCADE, offer_id INTEGER REFERENCES offer (id) ON DELETE SET NULL,
    provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, author_id INTEGER REFERENCES account (id) ON DELETE SET NULL, author_name TEXT, rating INTEGER, body TEXT, locale TEXT,
    status TEXT DEFAULT 'published', status_note TEXT, reply TEXT, replied_at TEXT, created_at TEXT)");
$pdo->exec("ALTER TABLE offer ADD COLUMN rating_count INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE offer ADD COLUMN rating_sum INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE provider ADD COLUMN rating_count INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE provider ADD COLUMN rating_sum INTEGER NOT NULL DEFAULT 0");
$pdo->exec("PRAGMA foreign_keys = ON");
$pdo->exec("CREATE TABLE role (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE role_permission (role_id INTEGER REFERENCES role (id) ON DELETE CASCADE, permission TEXT)");
$pdo->exec("CREATE TABLE account_role (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, role_id INTEGER REFERENCES role (id) ON DELETE CASCADE)");
$pdo->exec("CREATE TABLE extension (id TEXT PRIMARY KEY, version TEXT, enabled INTEGER)");
$pdo->exec("CREATE TABLE setting (name TEXT PRIMARY KEY, value TEXT)");
$pdo->exec("INSERT INTO setting VALUES ('core.languages', 'de,en'), ('core.default_language', 'de')");
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
    'app' => ['env' => 'dev', 'url' => 'https://example.test', 'name' => 'Testseite', 'root' => $root, 'cron_token' => 'secret-cron-token',
        'uploads' => sys_get_temp_dir() . '/modulento-test-uploads-' . bin2hex(random_bytes(4))],
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

/** @return array{called: bool, status: int, body: string, redirect: ?string} */
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
    parse_str((string) parse_url($path, PHP_URL_QUERY), $_GET);
    $_SERVER['REQUEST_URI'] = $path;
    unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_REFERER']);
    http_response_code(200);

    $app = new Modulento\Core\App($config, $pdo);
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
    Modulento\Core\Kernel::registerLast($app);
    Modulento\Core\Kernel::loadThemeTexts($app);
    $redirect = Modulento\Core\Kernel::prepareRequest($app, $path, startSession: false);

    ob_start();
    if ($redirect === null) {
        $app->router->dispatch($method, $app->path);
    }
    $body = (string) ob_get_clean();

    return ['called' => $called, 'status' => (int) http_response_code(), 'body' => $body, 'redirect' => $redirect];
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
$_SERVER['CONTENT_LENGTH'] = '99999999';
$_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=x';
$_POST = [];
$oversized = new Modulento\Core\App($config, $pdo);
$oversized->translator->load($root . '/core/lang', 'core');
$oversized->translator->setLocale('de');
$oversized->router->post('/upload', fn () => null);
$_SESSION = ['_csrf' => 'test-token'];
$oversized->router->dispatch('POST', '/upload');
check('an upload beyond the server limit is named as such, not as a failed security check', str_contains($_SESSION['_flash']['error'] ?? '', 'zu groß'));
unset($_SERVER['CONTENT_LENGTH'], $_SERVER['CONTENT_TYPE']);
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

// --- Languages in the address ---------------------------------------------
$locales = fn () => new Modulento\Core\Support\Locales(new Modulento\Core\Support\Settings($pdo), $root . '/core/lang');
check('locales: default first', $locales()->enabled() === ['de', 'en'] && $locales()->default() === 'de');
check('locales: no prefix is the default language', $locales()->split('/login') === ['locale' => 'de', 'path' => '/login', 'redirect' => null]);
check('locales: prefix selects the language', $locales()->split('/en/login') === ['locale' => 'en', 'path' => '/login', 'redirect' => null]);
check('locales: prefix alone is the home page', $locales()->split('/en')['path'] === '/' && $locales()->split('/en/')['path'] === '/');
check('locales: default language with prefix redirects to the plain address', $locales()->split('/de/login')['redirect'] === '/login');
check('locales: an unknown or disabled code is an ordinary path', $locales()->split('/fr/login')['path'] === '/fr/login');
check('locales: prefix()', $locales()->prefix('/login', 'en') === '/en/login' && $locales()->prefix('/', 'en') === '/en' && $locales()->prefix('/login', 'de') === '/login' && $locales()->prefix('/login', 'fr') === '/login');
check('locales: a word starting like a code is not a prefix', $locales()->split('/english')['path'] === '/english');

$r = $get('/login', null);
check('page in the default language', str_contains($r['body'], '<html lang="de">') && str_contains($r['body'], 'action="/login"') && str_contains($r['body'], 'Passwort vergessen'));
$r = $get('/en/login', null);
check('page in another language: texts, links and form targets carry the prefix', str_contains($r['body'], '<html lang="en">')
    && str_contains($r['body'], 'action="/en/login"') && str_contains($r['body'], 'href="/en/register"') && str_contains($r['body'], 'Forgot your password'));
check('hreflang alternates for every language', str_contains($r['body'], 'hreflang="de" href="https://example.test/login"') && str_contains($r['body'], 'hreflang="en" href="https://example.test/en/login"'));
check('language menu links to the same page', str_contains($r['body'], '<a href="/login" hreflang="de" lang="de">Deutsch</a>'));
check('assets are not prefixed', str_contains($r['body'], 'href="/assets/theme/theme.css'));
check('default language with prefix redirects, query kept', $get('/de/login?x=1', null)['redirect'] === '/login?x=1');
$r = $get('/en/admin', null);
check('login redirect remembers the page without prefix', ($_SESSION['login_return_to'] ?? '') === '/admin');
$r = $get('/en/nowhere/at/all', null);
check('404 in the visitor\'s language', $r['status'] === 404 && str_contains($r['body'], 'Page not found'));

$post('/en/register', ['email' => 'english@example.test', 'password' => $pw, 'password_repeat' => $pw], null);
$mail = lastMail($mailLog, 'english@example.test');
check('mail in the language of the request, link with prefix', $mail['subject'] === 'Please confirm your e-mail address' && str_starts_with($mail['link'], '/en/verify-email/'));
check('account remembers the language it registered in', $row('english@example.test')['locale'] === 'en');
$pdo->exec("DELETE FROM account WHERE email = 'english@example.test'");

$pdo->exec("UPDATE setting SET value = 'de' WHERE name = 'core.languages'");
check('a disabled language is no prefix any more', $get('/en/login', null)['status'] === 404);
check('with one language there is no language menu', !str_contains($get('/login', null)['body'], 'language-menu'));
$pdo->exec("UPDATE setting SET value = 'de,en' WHERE name = 'core.languages'");

// --- Pages -----------------------------------------------------------------
use Modulento\Core\Support\HtmlSanitizer;

check('sanitizer: scripts and handlers are removed', HtmlSanitizer::clean('<p onclick="x()">Hä <b>fett</b></p><script>alert(1)</script>') === '<p>Hä <b>fett</b></p>');
check('sanitizer: javascript: link loses its target', HtmlSanitizer::clean("<a href=\"java\tscript:alert(1)\">x</a>") === '<a>x</a>');
check('sanitizer: protocol-relative link loses its target', HtmlSanitizer::clean('<a href="//evil.test">x</a>') === '<a>x</a>');
check('sanitizer: external link is kept and marked', HtmlSanitizer::clean('<a href="https://a.test/?x=1&y=2" target="_blank">y</a>') === '<a href="https://a.test/?x=1&amp;y=2" rel="noopener noreferrer">y</a>');
check('sanitizer: unknown elements keep their text', HtmlSanitizer::clean('<div><span style="x">Text</span><img src=x onerror=alert(1)></div><iframe src=x></iframe>') === 'Text');
check('sanitizer: tables and headings survive, h1 does not', HtmlSanitizer::clean('<h1>T</h1><h2>U</h2><table><tr><td colspan="2" style="a">1</td></tr></table>') === 'T<h2>U</h2><table><tr><td colspan="2">1</td></tr></table>');

use Modulento\Core\Content\Pages;

check('slug: umlauts and punctuation', Pages::slugify(' Über uns & Größe! ') === 'ueber-uns-groesse' && Pages::slugify('Conditions générales') === 'conditions-generales');

$text = fn (string $title, string $body = '<p>Text</p>', string $slug = '') => ['title' => $title, 'slug' => $slug, 'meta_description' => '', 'body' => $body];
$r = $post('/admin/pages/new', ['status' => 'published', 'role' => 'imprint', 'text' => ['de' => $text('Impressum', '<p>Angaben</p><script>x</script>'), 'en' => $text('')]], 1);
check('pages: need their permission', $r['status'] === 403 && $pdo->query('SELECT COUNT(*) FROM page')->fetchColumn() == 0);
$post('/admin/pages/new', ['status' => 'published', 'role' => 'imprint', 'text' => ['de' => $text('Impressum', '<p>Angaben</p><script>x</script>'), 'en' => $text('')]], 3);
$imprint = $pdo->query("SELECT * FROM page_translation WHERE slug = 'impressum'")->fetch();
check('pages: saved with slug from the title, body cleaned, only the filled language', $imprint !== false && $imprint['body'] === '<p>Angaben</p>'
    && $pdo->query('SELECT COUNT(*) FROM page_translation')->fetchColumn() == 1);
$r = $get('/impressum', null);
check('pages: shown at their address', $r['status'] === 200 && str_contains($r['body'], '<h1>Impressum</h1>') && str_contains($r['body'], '<p>Angaben</p>'));
$r = $get('/en/impressum', null);
check('pages: a language without its own text shows the default one, marked as German', $r['status'] === 200 && str_contains($r['body'], '<article lang="de">') && str_contains($r['body'], '<html lang="en">'));
check('pages: legal page is in the footer of every language', str_contains($r['body'], '<a href="/en/impressum">Impressum</a>'));

$post('/admin/pages/' . $imprint['page_id'], ['status' => 'published', 'role' => 'imprint', 'text' => ['de' => $text('Impressum', '<p>Angaben</p>', 'impressum'), 'en' => $text('Imprint', '<p>Details</p>')]], 3);
$r = $get('/en/imprint', null);
check('pages: translated text and slug', $r['status'] === 200 && str_contains($r['body'], '<h1>Imprint</h1>') && str_contains($r['body'], '<article lang="en">'));
check('pages: language menu and hreflang point to the translated slug', str_contains($r['body'], '<a href="/impressum" hreflang="de" lang="de">Deutsch</a>')
    && str_contains($r['body'], 'hreflang="de" href="https://example.test/impressum"'));
check('pages: the other language\'s slug is not an address here', $get('/en/impressum', null)['status'] === 404 && $get('/imprint', null)['status'] === 404);

$post('/admin/pages/new', ['status' => 'published', 'role' => 'imprint', 'text' => ['de' => $text('Zweites Impressum')]], 3);
check('pages: a legal function belongs to one page', $pdo->query('SELECT COUNT(*) FROM page')->fetchColumn() == 1);
$post('/admin/pages/new', ['status' => 'published', 'text' => ['de' => $text('Doppelt', '', 'impressum')]], 3);
check('pages: an address is unique per language', $pdo->query('SELECT COUNT(*) FROM page')->fetchColumn() == 1);
$r = $post('/admin/pages/new', ['status' => 'published', 'text' => ['de' => $text('Login')]], 3);
check('pages: system addresses are refused, the form keeps the input', $pdo->query('SELECT COUNT(*) FROM page')->fetchColumn() == 1 && str_contains($r['body'], 'value="Login"'));
$post('/admin/pages/new', ['status' => 'draft', 'in_header' => '1', 'text' => ['de' => $text('Über uns')]], 3);
check('pages: a draft is neither reachable nor linked', $get('/ueber-uns', null)['status'] === 404 && !str_contains($get('/', null)['body'], 'Über uns'));
$aboutId = $pdo->query("SELECT page_id FROM page_translation WHERE slug = 'ueber-uns'")->fetchColumn();
$post('/admin/pages/' . $aboutId, ['status' => 'published', 'in_header' => '1', 'text' => ['de' => $text('Über uns')]], 3);
check('pages: published page appears in the main menu', str_contains($get('/', null)['body'], '<a href="/ueber-uns">Über uns</a>'));
check('pages: core routes win over a page address', str_contains($get('/login', null)['body'], 'name="password"'));

// --- Terms at registration -------------------------------------------------
check('register: no checkbox while no terms are published', !str_contains($get('/register', null)['body'], 'accept_terms'));
$post('/admin/pages/new', ['status' => 'published', 'role' => 'terms', 'text' => ['de' => $text('AGB')]], 3);
$r = $get('/register', null);
check('register: checkbox with link once terms are published', str_contains($r['body'], 'name="accept_terms"') && str_contains($r['body'], 'href="/agb"'));
$post('/register', ['email' => 'terms@example.test', 'password' => $pw, 'password_repeat' => $pw], null);
check('register: refused without accepting', $row('terms@example.test') === false);
$post('/register', ['email' => 'terms@example.test', 'password' => $pw, 'password_repeat' => $pw, 'accept_terms' => '1'], null);
check('register: acceptance is recorded with its time', ($row('terms@example.test')['terms_accepted_at'] ?? null) !== null);

// --- Settings ----------------------------------------------------------------
$r = $post('/admin/settings', ['site_name' => 'Neuer Name', 'mail_from' => 'post@example.test', 'registration' => 'closed', 'default_locale' => 'en', 'locales' => ['en', 'de']], 1);
check('settings: need their permission', $r['status'] === 403);
$post('/admin/settings', ['site_name' => 'Neuer Name', 'mail_from' => 'post@example.test', 'registration' => 'closed', 'default_locale' => 'en', 'locales' => ['en', 'de']], 3);
$r = $get('/login', null);
check('settings: site name and default language take effect', str_contains($r['body'], 'Neuer Name') && str_contains($r['body'], '<html lang="en">') && str_contains($get('/de/login', null)['body'], '<html lang="de">'));
check('settings: closed registration hides the link and creates nothing', !str_contains($r['body'], '/register')
    && $post('/register', ['email' => 'closed@example.test', 'password' => $pw, 'password_repeat' => $pw, 'accept_terms' => '1'], null) && $row('closed@example.test') === false);
$post('/admin/settings', ['site_name' => '', 'mail_from' => 'nope', 'default_locale' => 'xx'], 3);
check('settings: invalid input changes nothing', str_contains($get('/login', null)['body'], 'Neuer Name'));
$post('/admin/settings', ['site_name' => 'Testseite', 'mail_from' => 'noreply@example.test', 'registration' => 'open', 'default_locale' => 'de', 'locales' => ['de', 'en']], 3);
$post('/admin/settings', ['site_name' => 'Testseite', 'mail_from' => 'noreply@example.test', 'registration' => 'open', 'default_locale' => 'de', 'locales' => []], 3);
check('settings: the default language cannot be switched off', $locales()->enabled() === ['de']);
$post('/admin/settings', ['site_name' => 'Testseite', 'mail_from' => 'noreply@example.test', 'registration' => 'open', 'default_locale' => 'de', 'locales' => ['de', 'en']], 3);
$pdo->exec('DELETE FROM page');
$pdo->exec("DELETE FROM account WHERE email = 'terms@example.test'");

// --- Providers ---------------------------------------------------------------
$provider = fn (int $accountId) => $pdo->query('SELECT * FROM provider WHERE account_id = ' . $accountId)->fetch();
$business = ['type' => 'business', 'name' => 'Müller Design', 'legal_name' => 'Müller Design GmbH', 'street' => 'Hauptstraße 1', 'postal_code' => '1010',
    'city' => 'Wien', 'country' => 'AT', 'contact_email' => 'office@mueller.test', 'phone' => '+43 1 234', 'vat_id' => 'atu 1234-5678', 'tax_id' => '12 345/6789',
    'self_certified' => '1', 'text' => ['de' => ['headline' => 'Logos & mehr', 'description' => "Zeile eins\n<b>Zeile zwei</b>"], 'en' => ['headline' => '', 'description' => '']]];

check('provider form needs a login', $get('/account/provider', null)['body'] === '');
$r = $post('/account/provider', ['type' => 'business', 'name' => 'X'] , 1);
check('provider: incomplete form creates nothing and says why', $provider(1) === false && str_contains($r['body'], 'Bitte fülle Name'));
$r = $post('/account/provider', array_diff_key($business, ['self_certified' => 1]), 1);
check('provider: a business must give the self-declaration', $provider(1) === false && str_contains($r['body'], 'Erklärung'));
$r = $post('/account/provider', ['vat_id' => '123'] + $business, 1);
check('provider: malformed VAT ID is refused, typed values stay in the form', $provider(1) === false && str_contains($r['body'], 'value="Müller Design GmbH"'));

$post('/account/provider', $business, 1);
$p = $provider(1);
check('provider: saved as pending while approval is required', $p !== false && $p['status'] === 'pending' && $p['slug'] === 'mueller-design' && $p['vat_id'] === 'ATU12345678' && $p['self_certified_at'] !== null);
check('provider: only the filled language is stored', $pdo->query('SELECT COUNT(*) FROM provider_translation')->fetchColumn() == 1);
check('provider: pending profile is not public', $get('/providers/mueller-design', null)['status'] === 404 && !str_contains($get('/providers', null)['body'], 'Müller Design'));

$r = $get('/admin/providers', 1);
check('provider administration needs its permission', $r['status'] === 403);
$r = $get('/admin/providers?status=pending', 3);
check('provider administration lists the waiting profile', $r['status'] === 200 && str_contains($r['body'], 'Müller Design'));
$post('/admin/providers/' . $p['id'] . '/decide', ['decision' => 'reject', 'note' => ''], 3);
check('provider: rejecting needs a reason', $provider(1)['status'] === 'pending');
$post('/admin/providers/' . $p['id'] . '/decide', ['decision' => 'reject', 'note' => 'Adresse unvollständig'], 3);
$mail = lastMail($mailLog, 'plain@example.test');
check('provider: rejected with reason, owner is told', $provider(1)['status'] === 'rejected' && $provider(1)['status_note'] === 'Adresse unvollständig' && $mail['subject'] === 'Dein Anbieterprofil wurde nicht freigegeben');
check('provider: owner sees the reason', str_contains($get('/account/provider', 1)['body'], 'Adresse unvollständig'));
$post('/account/provider', ['street' => 'Hauptstraße 1/4'] + $business, 1);
check('provider: a corrected rejected profile waits for review again', $provider(1)['status'] === 'pending' && $provider(1)['status_note'] === null);
$post('/admin/providers/' . $p['id'] . '/decide', ['decision' => 'approve'], 3);
check('provider: approved, owner is told with the public link', $provider(1)['status'] === 'approved' && $provider(1)['decided_by'] == 3
    && lastMail($mailLog, 'plain@example.test')['link'] === '/providers/mueller-design');

$r = $get('/providers/mueller-design', null);
check('provider: public page shows the legal details of a business', $r['status'] === 200 && str_contains($r['body'], 'Müller Design GmbH') && str_contains($r['body'], 'ATU12345678') && str_contains($r['body'], 'Österreich'));
check('provider: the tax number is never public', !str_contains($r['body'], '12 345/6789'));
check('provider: description is escaped, line breaks kept', str_contains($r['body'], "Zeile eins<br />\n&lt;b&gt;Zeile zwei&lt;/b&gt;"));
check('provider: listed in the directory, in every language', str_contains($get('/providers', null)['body'], 'href="/providers/mueller-design"') && str_contains($get('/en/providers', null)['body'], 'href="/en/providers/mueller-design"'));
check('provider: another language shows the default text and translated labels', str_contains($get('/en/providers/mueller-design', null)['body'], 'Logos &amp; mehr') && str_contains($get('/en/providers/mueller-design', null)['body'], 'Business provider'));

$post('/account/provider', ['text' => ['de' => ['headline' => 'Neu', 'description' => 'x'], 'en' => ['headline' => 'New', 'description' => 'y']]] + $business + ['street' => 'Hauptstraße 1/4'], 1);
$r = $get('/admin/providers/' . $p['id'], 3);
check('provider: changing only the presentation is not flagged', $provider(1)['status'] === 'approved' && !str_contains($r['body'], 'seit der letzten Entscheidung'));
sleep(1);
$post('/account/provider', ['street' => 'Nebenstraße 9', 'text' => $business['text']] + $business, 1);
check('provider: changing legal details stays public but is flagged for review', $provider(1)['status'] === 'approved' && str_contains($get('/admin/providers/' . $p['id'], 3)['body'], 'seit der letzten Entscheidung'));

$post('/admin/providers/' . $p['id'] . '/decide', ['decision' => 'suspend', 'note' => 'Beschwerden'], 3);
check('provider: suspended profile is gone from public view', $get('/providers/mueller-design', null)['status'] === 404 && lastMail($mailLog, 'plain@example.test')['subject'] === 'Dein Anbieterprofil wurde gesperrt');
$post('/account/provider', $business, 1);
check('provider: saving does not lift a suspension', $provider(1)['status'] === 'suspended');
$post('/admin/providers/' . $p['id'] . '/decide', ['decision' => 'approve'], 3);

$private = ['type' => 'private', 'name' => 'Müller Design', 'street' => 'Weg 2', 'postal_code' => '80331', 'city' => 'München', 'country' => 'DE'];
$post('/account/provider', $private, 2);
check('provider: a private person needs neither contact nor declaration; same name gets its own address', $provider(2) !== false && $provider(2)['slug'] === 'mueller-design-2' && $provider(2)['legal_name'] === 'Müller Design');

$settings = ['site_name' => 'Testseite', 'mail_from' => 'noreply@example.test', 'registration' => 'open', 'default_locale' => 'de', 'locales' => ['de', 'en']];
$post('/admin/providers/' . $p['id'] . '/decide', ['decision' => 'suspend', 'note' => 'Test'], 3);
$post('/admin/settings', $settings + ['provider_approval' => 'off'], 3);
check('approval switched off: everyone waiting is approved and told', $provider(2)['status'] === 'approved' && lastMail($mailLog, 'editor@example.test')['subject'] === 'Dein Anbieterprofil ist freigegeben');
check('approval switched off: a suspended profile stays suspended', $provider(1)['status'] === 'suspended');
$r = $get('/providers/mueller-design-2', null);
check('provider: of a private person only name and place are public', $r['status'] === 200 && str_contains($r['body'], 'München') && !str_contains($r['body'], 'Weg 2') && str_contains($r['body'], 'Privatperson'));
$pdo->exec('DELETE FROM provider WHERE account_id = 2');
$post('/account/provider', $private, 2);
check('approval switched off: a new profile is active at once', $provider(2)['status'] === 'approved');
$post('/admin/settings', $settings + ['provider_approval' => 'required'], 3);
check('approval switched on again: existing profiles keep their status', $provider(2)['status'] === 'approved');

$pdo->exec("UPDATE account SET status = 'blocked' WHERE id = 2");
check('provider: profile of a blocked account is not public', $get('/providers/mueller-design', null)['status'] === 404 || $get('/providers/' . $provider(2)['slug'], null)['status'] === 404);
$pdo->exec("UPDATE account SET status = 'active' WHERE id = 2");
$export = json_decode($get('/account/export', 2)['body'], true);
check('export contains the provider profile', ($export['provider']['city'] ?? '') === 'München' && !isset($export['provider']['account_email']));

// --- Catalogue: categories, offers, the freelancer extension -----------------
check('money: typed amounts', Money::parse('49') === 4900 && Money::parse('49,9') === 4990 && Money::parse('1.234,50') === 123450 && Money::parse('1,234.50') === 123450
    && Money::parse('abc') === null && Money::parse('-5') === null && Money::parse('1.2.3') === null && Money::input(4990, 'de') === '49,90');

$pdo->exec("INSERT INTO extension VALUES ('freelancer', '0.1.0', 1)");
$name = fn (string $de, string $en = '') => ['de' => ['name' => $de, 'slug' => ''], 'en' => ['name' => $en, 'slug' => '']];
check('categories need their permission', $post('/admin/categories/new', ['text' => $name('Grafik')], 1)['status'] === 403);
$post('/admin/categories/new', ['text' => $name('Grafik & Design', 'Graphics & Design')], 3);
$catId = (int) $pdo->query("SELECT category_id FROM category_translation WHERE slug = 'grafik-design'")->fetchColumn();
$post('/admin/categories/new', ['parent_id' => $catId, 'text' => $name('Logo-Design', 'Logo design')], 3);
$childId = (int) $pdo->query("SELECT category_id FROM category_translation WHERE slug = 'logo-design'")->fetchColumn();
check('categories: created in two levels with an address per language', $catId > 0 && $childId > 0 && $pdo->query("SELECT slug FROM category_translation WHERE category_id = {$catId} AND locale = 'en'")->fetchColumn() === 'graphics-design');
$post('/admin/categories/new', ['parent_id' => $childId, 'text' => $name('Zu tief')], 3);
check('categories: no third level', $pdo->query('SELECT COUNT(*) FROM category')->fetchColumn() == 2);
$post('/admin/categories/' . $catId, ['parent_id' => $childId, 'text' => $name('Grafik & Design')], 3);
check('categories: a category with children cannot become a child', $pdo->query("SELECT parent_id FROM category WHERE id = {$catId}")->fetchColumn() === null);
$post('/admin/categories/' . $catId . '/delete', [], 3);
check('categories: a category with children is not deleted', $pdo->query('SELECT COUNT(*) FROM category')->fetchColumn() == 2);

// Account 1 is an approved business provider, account 2 an approved private one.
$pdo->exec("UPDATE provider SET status = 'approved' WHERE account_id IN (1, 2)");
$providerId = (int) $provider(1)['id'];
$offerForm = ['type' => 'freelancer.service', 'category_id' => $childId,
    'text' => ['de' => ['title' => 'Ich gestalte dein Logo', 'summary' => 'Modern & klar', 'description' => "Zeile eins\n<script>x</script>"], 'en' => ['title' => '', 'summary' => '', 'description' => '']],
    'package' => [1 => ['price' => '49,90', 'delivery_days' => '3', 'revisions' => '1', 'text' => ['de' => ['name' => '', 'description' => 'Ein Entwurf']]],
        2 => ['price' => '99', 'delivery_days' => '5', 'revisions' => '3', 'text' => ['de' => ['name' => 'Komplett', 'description' => 'Drei Entwürfe']]],
        3 => ['price' => '']],
    'extra' => [0 => ['price' => '20', 'extra_days' => '1', 'text' => ['de' => ['title' => 'Quelldatei']]], 1 => ['price' => '', 'text' => ['de' => ['title' => '']]]],
    'requirements' => ['de' => 'Firmenname und Farben']];
$offerRow = fn (string $where = '1 = 1') => $pdo->query("SELECT * FROM offer WHERE {$where} ORDER BY id DESC")->fetch();

check('offers: without a provider profile the form leads to the profile', $get('/account/offers', 3)['body'] === '' && ($_SESSION['_flash']['error'] ?? '') !== '');
$r = $get('/account/offers/new?type=freelancer.service', 1);
check('offer form shows the type\'s own fields', $r['status'] === 200 && str_contains($r['body'], 'name="package[1][price]"') && str_contains($r['body'], 'Paket „Basis“'));
$r = $post('/account/offers/new', ['package' => [1 => ['price' => 'viel', 'delivery_days' => '0']]] + $offerForm, 1);
check('offer: the type\'s validation refuses, typed values stay', $offerRow() === false && str_contains($r['body'], 'Preis zwischen') && str_contains($r['body'], 'value="Ich gestalte dein Logo"') && str_contains($r['body'], 'value="viel"'));
$r = $post('/account/offers/new', ['text' => ['de' => ['title' => '', 'summary' => 'x', 'description' => '']]] + $offerForm, 1);
check('offer: needs a title', $offerRow() === false);

$post('/account/offers/new', $offerForm, 1);
$offer = $offerRow();
check('offer: saved as a draft with the lowest package price', $offer !== false && $offer['status'] === 'draft' && (int) $offer['price_from'] === 4990 && $offer['currency'] === 'EUR' && (int) $offer['provider_id'] === $providerId);
check('offer: packages, extra and requirements stored; empty rows skipped', $pdo->query('SELECT COUNT(*) FROM x_freelancer_package')->fetchColumn() == 2
    && $pdo->query('SELECT COUNT(*) FROM x_freelancer_extra')->fetchColumn() == 1 && $pdo->query('SELECT text FROM x_freelancer_requirement')->fetchColumn() === 'Firmenname und Farben');
$offerId = (int) $offer['id'];
check('offer: a draft is not public', $get('/offers/ich-gestalte-dein-logo', null)['status'] === 404 && !str_contains($get('/offers', null)['body'], 'Ich gestalte'));
check('offer: someone else cannot open or change it', $get('/account/offers/' . $offerId, 2)['status'] === 404 && $post('/account/offers/' . $offerId . '/delete', [], 2)['status'] === 404 && $offerRow() !== false);

// Pictures: decoded and written anew, never stored as uploaded.
$uploadDir = $config['app']['uploads'] . '/offers/' . $offerId;
$makeImage = function (int $width, int $height, string $format = 'png'): string {
    $path = tempnam(sys_get_temp_dir(), 'img');
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
    $format === 'png' ? imagepng($image, $path) : imagejpeg($image, $path);

    return $path;
};
$upload = function (string $path) use ($post, $offerId): array {
    $_FILES = ['image' => ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path), 'name' => 'x.php', 'type' => 'image/png']];
    $result = $post('/account/offers/' . $offerId . '/images', [], 1);
    $_FILES = [];

    return $result;
};
$fake = tempnam(sys_get_temp_dir(), 'img');
file_put_contents($fake, '<?php echo "not a picture";');
$upload($fake);
check('image: a file that is no picture is refused', $pdo->query('SELECT COUNT(*) FROM offer_image')->fetchColumn() == 0 && !is_dir($uploadDir));
$upload($makeImage(3000, 1500));
$image = $pdo->query('SELECT * FROM offer_image')->fetch();
check('image: stored re-encoded and reduced, with a random name', $image !== false && (int) $image['width'] === 1600 && (int) $image['height'] === 800
    && preg_match('/^[0-9a-f]{32}$/', $image['name']) === 1 && is_file("{$uploadDir}/{$image['name']}.{$image['extension']}") && is_file("{$uploadDir}/{$image['name']}_thumb.{$image['extension']}"));
check('image: the thumbnail is small', getimagesize("{$uploadDir}/{$image['name']}_thumb.{$image['extension']}")[0] === 480);
$r = $get("/media/offers/{$offerId}/{$image['name']}_thumb.{$image['extension']}", null);
check('image: served through the media route', $r['status'] === 200 && strlen($r['body']) > 100);
check('image: only generated names are served', $get("/media/offers/{$offerId}/../../../.env", null)['status'] === 404 && $get("/media/offers/{$offerId}/x.php", null)['status'] === 404);
$_FILES = ['image' => ['tmp_name' => $makeImage(10, 10), 'error' => UPLOAD_ERR_OK]];
$post('/account/offers/' . $offerId . '/images', [], 2);
$_FILES = [];
check('image: nobody uploads to another provider\'s offer', $pdo->query('SELECT COUNT(*) FROM offer_image')->fetchColumn() == 1);

$post('/account/offers/' . $offerId . '/submit', [], 1);
check('offer: submitting waits for review while approval is required', $offerRow()['status'] === 'pending' && $get('/offers/ich-gestalte-dein-logo', null)['status'] === 404);
check('offer administration needs its permission', $get('/admin/offers', 1)['status'] === 403);
$r = $get('/admin/offers/' . $offerId, 3);
check('offer administration shows the offer with its packages', $r['status'] === 200 && str_contains($r['body'], 'Komplett') && str_contains($r['body'], '49,90 €'));
$post('/admin/offers/' . $offerId . '/decide', ['decision' => 'reject', 'note' => ''], 3);
check('offer: rejecting needs a reason', $offerRow()['status'] === 'pending');
$post('/admin/offers/' . $offerId . '/decide', ['decision' => 'reject', 'note' => 'Bild fehlt'], 3);
check('offer: rejected, provider is told why', $offerRow()['status'] === 'rejected' && lastMail($mailLog, 'plain@example.test')['subject'] === 'Dein Angebot „Ich gestalte dein Logo“ wurde nicht freigegeben');
$post('/account/offers/' . $offerId . '/submit', [], 1);
$post('/admin/offers/' . $offerId . '/decide', ['decision' => 'approve'], 3);
check('offer: resubmitted and approved, provider gets the public link', $offerRow()['status'] === 'published' && $offerRow()['published_at'] !== null
    && lastMail($mailLog, 'plain@example.test')['link'] === '/offers/ich-gestalte-dein-logo');

$r = $get('/offers/ich-gestalte-dein-logo', null);
check('offer page: text escaped, packages, extra and requirements shown', $r['status'] === 200 && str_contains($r['body'], '&lt;script&gt;') && str_contains($r['body'], '<strong>Basis</strong>')
    && str_contains($r['body'], '<strong>Komplett</strong>') && str_contains($r['body'], '99,00 €') && str_contains($r['body'], 'Quelldatei: + 20,00 €') && str_contains($r['body'], 'Firmenname und Farben'));
check('offer page: picture and link to the provider', str_contains($r['body'], "/media/offers/{$offerId}/{$image['name']}") && str_contains($r['body'], 'href="/providers/mueller-design"'));
$r = $get('/en/offers/ich-gestalte-dein-logo', null);
check('offer page in another language: default text, translated labels', $r['status'] === 200 && str_contains($r['body'], 'Ich gestalte dein Logo') && str_contains($r['body'], '<strong>Basic</strong>') && str_contains($r['body'], 'Delivery in 3 days'));
check('offer list, home page and provider page show the offer', str_contains($get('/offers', null)['body'], 'ab 49,90 €') && str_contains($get('/', null)['body'], 'Ich gestalte dein Logo')
    && str_contains($get('/providers/mueller-design', null)['body'], 'href="/offers/ich-gestalte-dein-logo"'));
check('category page lists offers of its subcategories, in both languages', str_contains($get('/categories/grafik-design', null)['body'], 'Ich gestalte dein Logo')
    && str_contains($get('/en/categories/graphics-design', null)['body'], 'href="/en/offers/ich-gestalte-dein-logo"') && $get('/categories/nope', null)['status'] === 404);
check('search finds by a word of the text and takes % literally', str_contains($get('/offers?q=modern', null)['body'], 'Ich gestalte') && !str_contains($get('/offers?q=%25', null)['body'], 'Ich gestalte')
    && !str_contains($get('/offers?q=gibtsnicht', null)['body'], 'Ich gestalte'));

$post('/account/offers/' . $offerId, ['text' => ['de' => ['title' => 'Ich gestalte dein Logo', 'summary' => 'Neu', 'description' => 'x'], 'en' => ['title' => 'I design your logo', 'summary' => '', 'description' => 'y']]] + $offerForm, 1);
check('offer: editing keeps it published and its address; a translation gets its own', $offerRow()['status'] === 'published' && $get('/offers/ich-gestalte-dein-logo', null)['status'] === 200
    && str_contains($get('/en/offers/i-design-your-logo', null)['body'], 'I design your logo') && $get('/en/offers/ich-gestalte-dein-logo', null)['status'] === 404);

$post('/offers/ich-gestalte-dein-logo/contact', ['message' => 'Hallo, ich hätte gern ein Logo für mein Café.'], null);
check('contact: needs a login', lastMail($mailLog, 'plain@example.test')['subject'] !== 'Anfrage zu deinem Angebot „Ich gestalte dein Logo“');
$post('/offers/ich-gestalte-dein-logo/contact', ['message' => 'zu kurz'], 2);
$post('/offers/ich-gestalte-dein-logo/contact', ['message' => 'Hallo, ich hätte gern ein Logo für mein Café.'], 1);
check('contact: too short and to oneself send nothing', lastMail($mailLog, 'plain@example.test')['subject'] !== 'Anfrage zu deinem Angebot „Ich gestalte dein Logo“');
$post('/offers/ich-gestalte-dein-logo/contact', ['message' => 'Hallo, ich hätte gern ein Logo für mein Café.'], 2);
$mails = (string) file_get_contents($mailLog);
check('contact: the provider gets the message with the sender as reply address', lastMail($mailLog, 'plain@example.test')['subject'] === 'Anfrage zu deinem Angebot „Ich gestalte dein Logo“'
    && str_contains($mails, "Reply-To: editor@example.test") && str_contains($mails, 'Logo für mein Café'));

$post('/account/offers/' . $offerId . '/pause', [], 1);
check('offer: paused by the provider is not public', $offerRow()['status'] === 'paused' && $get('/offers/ich-gestalte-dein-logo', null)['status'] === 404);
$post('/account/offers/' . $offerId . '/resume', [], 1);
check('offer: resumed without a new review', $offerRow()['status'] === 'published');
$post('/admin/offers/' . $offerId . '/decide', ['decision' => 'reject', 'note' => 'Beschwerde'], 3);
$post('/account/offers/' . $offerId . '/resume', [], 1);
check('offer: taken down by an administrator cannot be resumed by the provider', $offerRow()['status'] === 'rejected');
$post('/account/offers/' . $offerId . '/submit', [], 1);
$post('/admin/settings', $settings + ['provider_approval' => 'required', 'offer_approval' => 'off', 'currency' => 'chf'], 3);
check('offer approval switched off: waiting offers are published; currency setting saved', $offerRow()['status'] === 'published' && $pdo->query("SELECT value FROM setting WHERE name = 'core.currency'")->fetchColumn() === 'CHF');
$post('/account/offers/new', ['text' => ['de' => ['title' => 'Zweites Angebot', 'summary' => '', 'description' => '']]] + $offerForm, 1);
$second = $offerRow();
$post('/account/offers/' . $second['id'] . '/submit', [], 1);
check('offer approval off: published at once, in the new currency, with its own address', $offerRow()['status'] === 'published' && $offerRow()['currency'] === 'CHF');
$post('/admin/settings', $settings + ['provider_approval' => 'required', 'offer_approval' => 'required', 'currency' => 'EUR'], 3);
check('sorting by price', strpos($get('/offers?sort=price_low', null)['body'], 'Zweites Angebot') !== false);

$pdo->exec("UPDATE provider SET status = 'suspended' WHERE account_id = 1");
check('offers of a suspended provider disappear', $get('/offers/ich-gestalte-dein-logo', null)['status'] === 404 && !str_contains($get('/offers', null)['body'], 'Ich gestalte'));
$pdo->exec("UPDATE provider SET status = 'approved' WHERE account_id = 1");
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'freelancer'");
check('offers of a disabled extension are hidden, not lost', $get('/offers/ich-gestalte-dein-logo', null)['status'] === 404 && $pdo->query('SELECT COUNT(*) FROM offer')->fetchColumn() == 2);
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'freelancer'");

$post('/admin/categories/' . $childId . '/delete', [], 3);
check('deleting a category keeps its offers', $offerRow("id = {$offerId}")['category_id'] === null && $get('/offers/ich-gestalte-dein-logo', null)['status'] === 200);
$post('/account/offers/' . $second['id'] . '/delete', [], 1);
check('offer: deleting removes it and the type\'s rows', $offerRow('id = ' . (int) $second['id']) === false && $pdo->query('SELECT COUNT(*) FROM x_freelancer_package WHERE offer_id = ' . (int) $second['id'])->fetchColumn() == 0);

// --- Orders: the engine and the freelancer flow --------------------------------
$orderRow = fn (int $id) => $pdo->query("SELECT * FROM orders WHERE id = {$id}")->fetch();
$lastOrder = fn () => (int) $pdo->query('SELECT MAX(id) FROM orders')->fetchColumn();
$act = fn (int $orderId, string $transition, int $as, string $note = '') => $post('/orders/' . $orderId . '/transition', ['transition' => $transition, 'note' => $note], $as);
$orderPath = '/offers/ich-gestalte-dein-logo/order';

$r = $get('/offers/ich-gestalte-dein-logo', 2);
check('offer page offers to order each package', str_contains($r['body'], $orderPath . '?package=1') && str_contains($r['body'], $orderPath . '?package=2'));
check('the provider sees no order button on the own offer', !str_contains($get('/offers/ich-gestalte-dein-logo', 1)['body'], $orderPath . '?package='));
check('ordering needs a login', $get($orderPath, null)['body'] === '');
$r = $get($orderPath . '?package=2', 2);
check('order form: chosen package preselected, legally worded button', $r['status'] === 200 && preg_match('/value="2" checked/', $r['body']) === 1 && str_contains($r['body'], 'Zahlungspflichtig bestellen'));
$post($orderPath, ['package' => '2'], 1);
check('nobody orders their own offer', $lastOrder() === 0);
$post($orderPath, ['package' => '3'], 2);
check('a package that does not exist cannot be ordered', $lastOrder() === 0);
$post($orderPath, ['package' => '2', 'extras' => ['5']], 2);
check('an extra that does not exist cannot be ordered', $lastOrder() === 0);

$post($orderPath, ['package' => '2', 'extras' => ['0'], 'note' => 'Firma: Beispiel GmbH', 'total' => '1', 'price' => '1', 'unit_price' => '1'], 2);
$orderId = $lastOrder();
$order = $orderRow($orderId);
check('order: created with prices from the offer, whatever the request claims', $orderId > 0 && (int) $order['total'] === 9900 + 2000 && $order['state'] === 'placed'
    && $order['payment_state'] === 'unpaid' && $order['payment_method'] === 'core.offline' && $order['offer_title'] === 'Ich gestalte dein Logo');
check('order: items, flow data and the first history entry', $pdo->query("SELECT GROUP_CONCAT(label || ':' || unit_price, '|') FROM order_item WHERE order_id = {$orderId}")->fetchColumn() === 'Komplett:9900|Quelldatei:2000'
    && json_decode($order['data'], true) === ['tier' => 2, 'delivery_days' => 6, 'revisions' => 3]
    && $pdo->query("SELECT note FROM order_event WHERE order_id = {$orderId}")->fetchColumn() === 'Firma: Beispiel GmbH');
check('order: has a deadline for the provider to answer', $order['due_transition'] === 'expire' && $order['due_at'] > gmdate('Y-m-d H:i:s', time() + 2 * 86400));
check('order: the provider is told', lastMail($mailLog, 'plain@example.test')['subject'] === 'Bestellung ' . sprintf('%06d', $orderId) . ': Bestellt'
    && lastMail($mailLog, 'plain@example.test')['link'] === '/orders/' . $orderId);

check('order page: buyer and provider see it, nobody else', $get('/orders/' . $orderId, 2)['status'] === 200 && $get('/orders/' . $orderId, 1)['status'] === 200 && $get('/orders/' . $orderId, 3)['status'] === 404);
$r = $get('/orders/' . $orderId, 2);
check('order page (buyer): items, total, own actions only', str_contains($r['body'], '119,00 €') && str_contains($r['body'], 'Bestellung zurückziehen') && !str_contains($r['body'], 'Auftrag annehmen'));
check('order page (provider): own actions and the buyer\'s note', str_contains($get('/orders/' . $orderId, 1)['body'], 'Auftrag annehmen') && str_contains($get('/orders/' . $orderId, 1)['body'], 'Firma: Beispiel GmbH'));
check('lists: purchases and sales', str_contains($get('/account/orders', 2)['body'], '/orders/' . $orderId) && str_contains($get('/account/sales', 1)['body'], '/orders/' . $orderId)
    && !str_contains($get('/account/orders', 1)['body'], '/orders/' . $orderId));

$act($orderId, 'accept', 2);
check('a buyer cannot accept the order for the provider', $orderRow($orderId)['state'] === 'placed');
$act($orderId, 'accept', 3);
check('an outsider cannot touch the order', $orderRow($orderId)['state'] === 'placed');
$act($orderId, 'deliver', 1, 'zu früh');
check('a transition from the wrong state is refused', $orderRow($orderId)['state'] === 'placed');
$act($orderId, 'decline', 1, '');
check('declining needs a reason', $orderRow($orderId)['state'] === 'placed');
$act($orderId, 'accept', 1);
check('accept: in progress, deadline cleared, buyer told', $orderRow($orderId)['state'] === 'in_progress' && $orderRow($orderId)['due_at'] === null
    && lastMail($mailLog, 'editor@example.test')['subject'] === 'Bestellung ' . sprintf('%06d', $orderId) . ': Auftrag angenommen');
check('order page shows the delivery date', str_contains($get('/orders/' . $orderId, 2)['body'], 'zu liefern bis'));

$post('/orders/' . $orderId . '/message', ['body' => "Hier sind die Farben:\n<b>blau</b>"], 2);
$post('/orders/' . $orderId . '/message', ['body' => 'hallo'], 3);
check('messages: stored for a party, refused for an outsider, the other side is mailed', $pdo->query("SELECT COUNT(*) FROM order_message WHERE order_id = {$orderId}")->fetchColumn() == 1
    && lastMail($mailLog, 'plain@example.test')['subject'] === 'Neue Nachricht zur Bestellung ' . sprintf('%06d', $orderId));
check('messages: shown escaped', str_contains($get('/orders/' . $orderId, 1)['body'], '&lt;b&gt;blau&lt;/b&gt;'));

$post('/orders/' . $orderId . '/paid', [], 2);
check('only the provider confirms a payment', $orderRow($orderId)['payment_state'] === 'unpaid');
$post('/orders/' . $orderId . '/paid', [], 1);
check('provider marks the payment as received', $orderRow($orderId)['payment_state'] === 'paid' && $orderRow($orderId)['paid_at'] !== null);

$act($orderId, 'deliver', 1, 'Fertig: https://example.test/logo.zip');
check('deliver: waits for the buyer, with a deadline for automatic acceptance', $orderRow($orderId)['state'] === 'delivered' && $orderRow($orderId)['due_transition'] === 'auto_complete');
foreach ([1, 2, 3] as $round) {
    $act($orderId, 'request_revision', 2, 'Bitte heller, Runde ' . $round);
    $act($orderId, 'deliver', 1, 'Neu geliefert');
}
check('revisions: three are included and were used', $pdo->query("SELECT COUNT(*) FROM order_event WHERE order_id = {$orderId} AND transition = 'request_revision'")->fetchColumn() == 3 && $orderRow($orderId)['state'] === 'delivered');
$act($orderId, 'request_revision', 2, 'Noch eine');
check('revisions: a fourth is refused and no longer offered', $orderRow($orderId)['state'] === 'delivered' && !str_contains($get('/orders/' . $orderId, 2)['body'], 'value="request_revision"')
    && str_contains($get('/orders/' . $orderId, 2)['body'], 'value="accept_delivery"'));

$act($orderId, 'request_cancel', 2, 'Passt doch nicht');
check('cancellation requested by the buyer', $orderRow($orderId)['state'] === 'cancel_requested' && $orderRow($orderId)['previous_state'] === 'delivered');
$act($orderId, 'agree_cancel', 2);
check('the one who asked cannot agree to the own request', $orderRow($orderId)['state'] === 'cancel_requested');
$act($orderId, 'withdraw_cancel', 1);
check('only the one who asked can withdraw the request', $orderRow($orderId)['state'] === 'cancel_requested');
$act($orderId, 'refuse_cancel', 1, 'Die Arbeit ist getan');
check('refusing leads back to where the order was', $orderRow($orderId)['state'] === 'delivered' && $orderRow($orderId)['due_transition'] === 'auto_complete');
$act($orderId, 'accept_delivery', 2);
check('accepting the delivery completes the order', $orderRow($orderId)['state'] === 'completed' && $orderRow($orderId)['closed_at'] !== null);
$act($orderId, 'request_cancel', 2, 'zu spät');
check('a completed order cannot be changed', $orderRow($orderId)['state'] === 'completed' && !str_contains($get('/orders/' . $orderId, 2)['body'], 'name="transition"'));

// Deadlines, applied by the scheduler.
$post($orderPath, ['package' => '1'], 2);
$expiring = $lastOrder();
$pdo->exec("UPDATE orders SET due_at = '2020-01-01 00:00:00' WHERE id = {$expiring}");
$post($orderPath, ['package' => '1'], 2);
$waiting = $lastOrder();
$deadlineApp = new Modulento\Core\App($config, $pdo);
$deadlineApp->translator->load($root . '/core/lang', 'core');
Modulento\Core\Kernel::registerCore($deadlineApp);
$deadlineApp->extensions->loadEnabled($deadlineApp);
check('deadline: only the overdue order is moved on', $deadlineApp->orders->runDeadlines($deadlineApp) === 1 && $orderRow($expiring)['state'] === 'declined' && $orderRow($waiting)['state'] === 'placed');
check('deadline: both sides are told', lastMail($mailLog, 'editor@example.test')['subject'] === 'Bestellung ' . sprintf('%06d', $expiring) . ': Nicht rechtzeitig angenommen');
check('deadline: recorded as done by the system', $pdo->query("SELECT actor_role FROM order_event WHERE order_id = {$expiring} ORDER BY id DESC")->fetchColumn() === 'system');

// Two requests at once: the second finds the order already changed.
$app2 = new Modulento\Core\App($config, $pdo);
Modulento\Core\Kernel::registerCore($app2);
$app2->extensions->loadEnabled($app2);
$first = $app2->orders->apply($waiting, 'accept', 'provider', 1, null, $app2);
$pdo->exec("UPDATE orders SET state = 'placed' WHERE id = {$waiting}");
$stale = $app2->orders->find($waiting);
$pdo->exec("UPDATE orders SET state = 'in_progress' WHERE id = {$waiting}");
check('engine: a transition only succeeds from the state it was checked against', $first === null && $stale['state'] === 'placed'
    && $app2->orders->apply($waiting, 'withdraw', 'buyer', 2, null, $app2) === 'core.order.error.not_possible');

check('order administration needs its permission', $get('/admin/orders', 1)['status'] === 403);
$r = $get('/admin/orders/' . $waiting, 3);
check('order administration shows the order and only the administrator\'s action', $r['status'] === 200 && str_contains($r['body'], 'Durch die Plattform stornieren') && !str_contains($r['body'], 'value="deliver"'));
$post('/admin/orders/' . $waiting . '/transition', ['transition' => 'admin_cancel', 'note' => ''], 3);
check('administrator cancelling needs a reason', $orderRow($waiting)['state'] === 'in_progress');
$post('/admin/orders/' . $waiting . '/transition', ['transition' => 'deliver', 'note' => 'x'], 3);
check('an administrator cannot play the provider', $orderRow($waiting)['state'] === 'in_progress');

$post('/account/delete', ['current_password' => 'correct horse battery'], 2);
check('an account with an open order cannot be deleted', $row('editor@example.test') !== false);
$post('/admin/orders/' . $waiting . '/transition', ['transition' => 'admin_cancel', 'note' => 'Verstoß'], 3);
check('administrator cancels; both sides are told', $orderRow($waiting)['state'] === 'cancelled' && lastMail($mailLog, 'editor@example.test')['subject'] === 'Bestellung ' . sprintf('%06d', $waiting) . ': Durch die Plattform storniert'
    && lastMail($mailLog, 'plain@example.test')['subject'] === 'Bestellung ' . sprintf('%06d', $waiting) . ': Durch die Plattform storniert');
$export = json_decode($get('/account/export', 2)['body'], true);
check('export lists the buyer\'s orders', count($export['orders'] ?? []) === 3 && ($export['orders'][0]['offer_title'] ?? '') === 'Ich gestalte dein Logo');

// --- Files attached to orders ---------------------------------------------------
use Modulento\Core\Order\OrderFiles;

check('file name: path and odd characters are removed', OrderFiles::safeName('../../etc/passwd') === 'passwd' && OrderFiles::safeName('C:\\Users\\x\\Logo "final" <v2>.pdf') === 'Logo _final_ _v2_.pdf'
    && OrderFiles::safeName("a\r\nb.zip") === 'a_b.zip' && OrderFiles::safeName('...') === 'file' && mb_strlen(OrderFiles::safeName(str_repeat('ä', 300) . '.pdf')) === 150);

/** $_FILES for a multi-file field, from name => content. */
$filesField = function (array $contents): array {
    $field = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    foreach ($contents as $fileName => $content) {
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, $content);
        $field['name'][] = $fileName;
        $field['tmp_name'][] = $tmp;
        $field['error'][] = UPLOAD_ERR_OK;
        $field['size'][] = strlen($content);
    }

    return ['files' => $field];
};
$withFiles = function (array $contents, callable $request) use ($filesField) {
    $_FILES = $filesField($contents);
    $result = $request();
    $_FILES = [];

    return $result;
};
check('uploads: an empty file field is no upload', OrderFiles::uploads(['name' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE], 'size' => [0]]) === [] && OrderFiles::uploads(null) === []);

$ordersBefore = $lastOrder();
$withFiles(['shell.php' => '<?php system($_GET["c"]);'], fn () => $post($orderPath, ['package' => '1'], 2));
check('order with a forbidden file type is not placed at all', $lastOrder() === $ordersBefore);
$withFiles(['empty.pdf' => ''], fn () => $post($orderPath, ['package' => '1'], 2));
check('order with an empty file is not placed', $lastOrder() === $ordersBefore);

$withFiles(['Briefing Café.txt' => 'Farben: blau'], fn () => $post($orderPath, ['package' => '1', 'note' => 'Siehe Anhang'], 2));
$fileOrder = $lastOrder();
$file = $pdo->query("SELECT * FROM order_file WHERE order_id = {$fileOrder}")->fetch();
$storedPath = $config['app']['uploads'] . '/orders/' . $fileOrder . '/' . ($file['stored_name'] ?? '');
check('order with a file: stored under a random name without extension, linked to the order\'s first entry', $fileOrder > $ordersBefore && $file !== false
    && preg_match('/^[0-9a-f]{32}$/', $file['stored_name']) === 1 && is_file($storedPath) && $file['original_name'] === 'Briefing Café.txt' && $file['event_id'] !== null && $file['author_role'] === 'buyer');
$r = $get('/orders/' . $fileOrder . '/files/' . $file['id'], 1);
check('download: the provider gets the content', $r['status'] === 200 && $r['body'] === 'Farben: blau');
check('download: the buyer too, an outsider not', $get('/orders/' . $fileOrder . '/files/' . $file['id'], 2)['body'] === 'Farben: blau' && $get('/orders/' . $fileOrder . '/files/' . $file['id'], 3)['status'] === 404);
check('download: a file of another order is not reachable through this one', $get('/orders/' . $orderId . '/files/' . $file['id'], 2)['status'] === 404);
check('download: an administrator through the administration only', $get('/admin/orders/' . $fileOrder . '/files/' . $file['id'], 3)['body'] === 'Farben: blau'
    && $get('/admin/orders/' . $fileOrder . '/files/' . $file['id'], 2)['status'] === 403);
check('order page lists the file where it belongs', str_contains($get('/orders/' . $fileOrder, 1)['body'], 'files/' . $file['id'] . '" download>Briefing Café.txt'));

$withFiles(['x.zip' => 'PK'], fn () => $act($fileOrder, 'accept', 1));
check('a step that takes no files ignores them', $orderRow($fileOrder)['state'] === 'in_progress' && $pdo->query("SELECT COUNT(*) FROM order_file WHERE order_id = {$fileOrder}")->fetchColumn() == 1);
$withFiles(['virus.exe' => 'MZ'], fn () => $act($fileOrder, 'deliver', 1, 'Fertig'));
check('delivery with a forbidden file: nothing happens, not even the delivery', $orderRow($fileOrder)['state'] === 'in_progress');
$withFiles(array_combine(array_map(fn ($i) => "f{$i}.pdf", range(1, 6)), array_fill(0, 6, '%PDF')), fn () => $act($fileOrder, 'deliver', 1, 'Fertig'));
check('delivery with too many files: nothing happens', $orderRow($fileOrder)['state'] === 'in_progress');
$withFiles(['logo.zip' => 'PK-archive', 'Vorschau.PNG' => 'png-bytes'], fn () => $act($fileOrder, 'deliver', 1, 'Fertig'));
check('delivery with files: delivered, both files attached to that step', $orderRow($fileOrder)['state'] === 'delivered'
    && $pdo->query("SELECT COUNT(*) FROM order_file f JOIN order_event e ON e.id = f.event_id WHERE f.order_id = {$fileOrder} AND e.transition = 'deliver'")->fetchColumn() == 2);
$delivered = $pdo->query("SELECT id FROM order_file WHERE original_name = 'logo.zip'")->fetchColumn();
check('the buyer downloads the delivery', $get('/orders/' . $fileOrder . '/files/' . $delivered, 2)['body'] === 'PK-archive');

$withFiles(['Rechnung.pdf' => '%PDF-1.4'], fn () => $post('/orders/' . $fileOrder . '/message', ['body' => ''], 1));
check('a message can consist of a file alone; the other side is told', $pdo->query("SELECT COUNT(*) FROM order_file WHERE message_id IS NOT NULL AND order_id = {$fileOrder}")->fetchColumn() == 1
    && lastMail($mailLog, 'editor@example.test')['subject'] === 'Neue Nachricht zur Bestellung ' . sprintf('%06d', $fileOrder));
$post('/orders/' . $fileOrder . '/message', ['body' => ''], 1);
check('a message with neither text nor file is refused', $pdo->query("SELECT COUNT(*) FROM order_message WHERE order_id = {$fileOrder}")->fetchColumn() == 1);
$act($fileOrder, 'accept_delivery', 2);

// --- Reviews -----------------------------------------------------------------------
$review = fn (int $orderId) => $pdo->query("SELECT * FROM review WHERE order_id = {$orderId}")->fetch();
$ratingOf = fn (string $table, int $id) => $pdo->query("SELECT rating_count || '/' || rating_sum FROM {$table} WHERE id = {$id}")->fetchColumn();
$pdo->exec("UPDATE account SET display_name = 'Erika M.' WHERE id = 2");

check('order page offers the review form to the buyer of a completed order only', str_contains($get('/orders/' . $orderId, 2)['body'], '/review"') && !str_contains($get('/orders/' . $orderId, 1)['body'], '/review"'));
$post('/orders/' . $orderId . '/review', ['rating' => '5', 'body' => 'x'], 1);
check('the provider cannot review the own order', $review($orderId) === false);
check('an outsider cannot review', $post('/orders/' . $orderId . '/review', ['rating' => '5'], 3)['status'] === 404 && $review($orderId) === false);
$post('/orders/' . $orderId . '/review', ['rating' => '6', 'body' => ''], 2);
$post('/orders/' . $orderId . '/review', ['rating' => '0', 'body' => ''], 2);
check('a rating outside 1 to 5 is refused', $review($orderId) === false);
$post('/orders/' . $waiting . '/review', ['rating' => '1', 'body' => 'storniert'], 2);
check('a cancelled order cannot be reviewed', $review($waiting) === false);

$post('/orders/' . $orderId . '/review', ['rating' => '5', 'body' => "Sehr gut!\n<script>alert(1)</script>"], 2);
check('review: stored with the author\'s display name, never the address', ($review($orderId)['rating'] ?? 0) == 5 && $review($orderId)['author_name'] === 'Erika M.' && $review($orderId)['locale'] === 'de');
check('review: counted for the offer and the provider', $ratingOf('offer', $offerId) === '1/5' && $ratingOf('provider', $providerId) === '1/5');
check('review: the provider is told', lastMail($mailLog, 'plain@example.test')['subject'] === 'Neue Bewertung zur Bestellung ' . sprintf('%06d', $orderId));
$post('/orders/' . $orderId . '/review', ['rating' => '1', 'body' => 'doch nicht'], 2);
check('an order is reviewed once', $review($orderId)['rating'] == 5 && $ratingOf('offer', $offerId) === '1/5');
$post('/orders/' . $fileOrder . '/review', ['rating' => '2', 'body' => ''], 2);
check('a second order adds a second review; a text is optional', $ratingOf('offer', $offerId) === '2/7' && $ratingOf('provider', $providerId) === '2/7');

$r = $get('/offers/ich-gestalte-dein-logo', null);
check('offer page: average, where reviews come from, text escaped', str_contains($r['body'], '3,5 von 5 Sternen aus 2 Bewertungen') && str_contains($r['body'], 'über diese Plattform bestellt')
    && str_contains($r['body'], '&lt;script&gt;alert(1)&lt;/script&gt;') && str_contains($r['body'], 'Erika M.') && !str_contains($r['body'], 'editor@example.test'));
check('offer list and provider page show the rating', str_contains($get('/offers', null)['body'], '3,5 (2)') && str_contains($get('/providers/mueller-design', null)['body'], '3,5 von 5 Sternen'));
check('english pages format the average with a point', str_contains($get('/en/offers?sort=rating', null)['body'], '3.5 (2)'));

$post('/orders/' . $orderId . '/review/reply', ['reply' => 'Selbstlob'], 2);
check('only the provider replies', $review($orderId)['reply'] === null);
$post('/orders/' . $orderId . '/review/reply', ['reply' => ''], 1);
check('an empty reply is refused', $review($orderId)['reply'] === null);
$post('/orders/' . $orderId . '/review/reply', ['reply' => 'Danke, gern wieder!'], 1);
check('provider replies; the author is told', $review($orderId)['reply'] === 'Danke, gern wieder!' && lastMail($mailLog, 'editor@example.test')['subject'] === 'Der Anbieter hat auf deine Bewertung geantwortet');
$post('/orders/' . $orderId . '/review/reply', ['reply' => 'Noch etwas'], 1);
check('the reply cannot be replaced', $review($orderId)['reply'] === 'Danke, gern wieder!' && str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], 'Danke, gern wieder!'));

check('review moderation needs its permission', $get('/admin/reviews', 1)['status'] === 403 && $get('/admin/reviews', 3)['status'] === 200);
$lowId = (int) $review($fileOrder)['id'];
$post('/admin/reviews/' . $lowId . '/hide', ['note' => ''], 3);
check('hiding a review needs a reason', $review($fileOrder)['status'] === 'published');
$post('/admin/reviews/' . $lowId . '/hide', ['note' => 'Beleidigung'], 3);
check('hidden review: gone from public pages and from the numbers, author told why', $review($fileOrder)['status'] === 'hidden' && $ratingOf('offer', $offerId) === '1/5' && $ratingOf('provider', $providerId) === '1/5'
    && str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], '5,0 von 5 Sternen aus 1 Bewertung"') && lastMail($mailLog, 'editor@example.test')['subject'] === 'Deine Bewertung wurde ausgeblendet');
check('hidden review: its author sees that it is hidden', str_contains($get('/orders/' . $fileOrder, 2)['body'], 'ausgeblendet'));
$post('/admin/reviews/' . $lowId . '/show', [], 3);
check('showing it again restores the numbers', $review($fileOrder)['status'] === 'published' && $ratingOf('offer', $offerId) === '2/7');

$export = json_decode($get('/account/export', 2)['body'], true);
check('export lists the reviews the account wrote', count($export['reviews'] ?? []) === 2);
(new Modulento\Core\Review\Reviews($pdo))->anonymise(2);
check('a deleted account\'s reviews stay without the name', $review($orderId)['author_name'] === '' && str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], 'Ein Käufer'));

$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'freelancer'");
check('order of a disabled extension: still readable, no actions', $get('/orders/' . $orderId, 2)['status'] === 200 && str_contains($get('/orders/' . $orderId, 2)['body'], 'Unbekannter Status'));
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'freelancer'");
$pdo->exec('DELETE FROM orders');

$accounts->create('seller@example.test', $pw, 'de', verified: true);
$sellerId = (int) $row('seller@example.test')['id'];
$post('/account/provider', $private, $sellerId);
$pdo->exec("UPDATE provider SET status = 'approved' WHERE account_id = {$sellerId}");
$post('/account/offers/new', ['category_id' => $catId, 'text' => ['de' => ['title' => 'Angebot des Verkäufers', 'summary' => '', 'description' => '']]] + $offerForm, $sellerId);
$sellerOffer = (int) $offerRow()['id'];
$_FILES = ['image' => ['tmp_name' => $makeImage(20, 20, 'jpeg'), 'error' => UPLOAD_ERR_OK]];
$post('/account/offers/' . $sellerOffer . '/images', [], $sellerId);
$_FILES = [];
$sellerDir = $config['app']['uploads'] . '/offers/' . $sellerOffer;
check('a JPEG upload works too', $sellerOffer !== $offerId && count(glob($sellerDir . '/*')) === 2);
$post('/account/delete', ['current_password' => $pw], $sellerId);
check('deleting an account removes its provider, offers and picture files', $row('seller@example.test') === false && $offerRow("id = {$sellerOffer}") === false && !is_dir($sellerDir));

$post('/account/offers/' . $offerId . '/images/' . $image['id'] . '/delete', [], 1);
check('image: deleting removes the row and both files', $pdo->query('SELECT COUNT(*) FROM offer_image')->fetchColumn() == 0 && count(glob($uploadDir . '/*')) === 0);
$pdo->exec('DELETE FROM offer');
$pdo->exec('DELETE FROM category');
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'freelancer'");
$pdo->exec("DELETE FROM setting WHERE name IN ('core.currency', 'core.offer_approval')");

// --- Accounts and roles in the administration --------------------------------
check('account administration needs its permission', $get('/admin/accounts', 2)['status'] === 403);
$r = $get('/admin/accounts?q=plain', 3);
check('account list finds by part of the address', str_contains($r['body'], 'plain@example.test') && !str_contains($r['body'], 'editor@example.test'));
check('account search takes % literally', !str_contains($get('/admin/accounts?q=%25', 3)['body'], 'plain@example.test'));
$r = $get('/admin/accounts/1', 3);
check('account page shows the account and never its password hash', str_contains($r['body'], 'plain@example.test') && !str_contains($r['body'], '$2y$'));

$post('/admin/accounts/1/block', ['note' => ''], 3);
check('block: needs a reason', $row('plain@example.test')['status'] === 'active');
$post('/admin/accounts/1/block', ['note' => 'Missbrauch'], 3);
check('block: account is blocked, told why, and logged out', $row('plain@example.test')['status'] === 'blocked' && lastMail($mailLog, 'plain@example.test')['subject'] === 'Dein Konto wurde gesperrt' && $get('/account', 1)['body'] === '');
$post('/admin/accounts/1/unblock', [], 3);
check('unblock', $row('plain@example.test')['status'] === 'active' && $row('plain@example.test')['status_note'] === null);
$post('/admin/accounts/3/block', ['note' => 'x'], 3);
check('block: not the own account', $row('admin@example.test')['status'] === 'active');
$post('/admin/accounts/3/delete', [], 3);
check('delete: not the own account', $row('admin@example.test') !== false);

$pdo->exec("UPDATE account SET email_verified_at = NULL WHERE id = 1");
$post('/admin/accounts/1/verify', [], 3);
check('verify by hand', $row('plain@example.test')['email_verified_at'] !== null);
$post('/admin/accounts/1/reset', [], 3);
check('reset link is sent to the owner', str_starts_with(lastMail($mailLog, 'plain@example.test')['link'], '/reset-password/'));

$known = ['core.pages.manage', 'core.accounts.manage'];
$post('/admin/roles/new', ['name' => 'Redaktion', 'permissions' => ['core.pages.manage', '*', 'made.up']], 3);
$roleId = (int) $pdo->query("SELECT id FROM role WHERE name = 'Redaktion'")->fetchColumn();
check('role: created with known permissions only - no wildcard, nothing made up', $roleId > 0
    && $pdo->query("SELECT permission FROM role_permission WHERE role_id = {$roleId}")->fetchAll(PDO::FETCH_COLUMN) === ['core.pages.manage']);
$post('/admin/roles/new', ['name' => 'Admin', 'permissions' => []], 3);
$post('/admin/roles/new', ['name' => 'redaktion', 'permissions' => []], 3);
check('role: the name admin and a taken name are refused', $pdo->query('SELECT COUNT(*) FROM role')->fetchColumn() == 3);
$adminRoleId = (int) $pdo->query("SELECT id FROM role WHERE name = 'admin'")->fetchColumn();
$post('/admin/roles/' . $adminRoleId, ['name' => 'admin', 'permissions' => []], 3);
$post('/admin/roles/' . $adminRoleId . '/delete', [], 3);
check('role: admin can be neither edited nor deleted', $pdo->query("SELECT permission FROM role_permission WHERE role_id = {$adminRoleId}")->fetchColumn() === '*');

$post('/admin/accounts/1/roles', ['roles' => [$roleId]], 3);
check('roles: assigned role gives its permission and nothing more', $get('/admin/pages', 1)['status'] === 200 && $get('/admin/settings', 1)['status'] === 403);
check('roles: the admin menu only shows what the account may do', str_contains($get('/admin/pages', 1)['body'], 'href="/admin/pages"') && !str_contains($get('/admin/pages', 1)['body'], 'href="/admin/settings"'));
$r = $get('/admin/pages/new', 3);
check('admin menu marks the entry of the current page', str_contains($r['body'], 'href="/admin/pages" aria-current="page"')
    && !str_contains($r['body'], 'href="/admin/settings" aria-current'));
$r = $get('/admin', 3);
$figure = fn (string $body, string $path): bool => str_contains($body, 'class="stat-label" href="' . $path . '"');
check('dashboard: an administrator gets a figure for every area', $r['status'] === 200 && $figure($r['body'], '/admin/providers') && $figure($r['body'], '/admin/offers')
    && $figure($r['body'], '/admin/orders') && $figure($r['body'], '/admin/accounts') && $figure($r['body'], '/admin/reviews'));
$pdo->exec("INSERT INTO role_permission VALUES ({$roleId}, 'core.admin.access'), ({$roleId}, 'core.orders.manage')");
$r = $get('/admin', 1);
check('dashboard: figures only for areas the account may manage', $r['status'] === 200 && $figure($r['body'], '/admin/orders')
    && !$figure($r['body'], '/admin/accounts') && !$figure($r['body'], '/admin/providers') && !$figure($r['body'], '/admin/reviews'));
$pdo->exec("DELETE FROM role_permission WHERE role_id = {$roleId} AND permission IN ('core.admin.access', 'core.orders.manage')");
$pdo->exec("INSERT INTO role_permission VALUES ({$roleId}, 'core.accounts.manage')");
$post('/admin/accounts/1/roles', ['roles' => [$roleId, $adminRoleId]], 1);
check('roles: managing accounts does not allow handing out roles', $get('/admin/settings', 1)['status'] === 403);
$post('/admin/accounts/3/delete', [], 1);
$post('/admin/accounts/3/block', ['note' => 'x'], 1);
check('accounts: an account manager cannot act against an administrator', $row('admin@example.test') !== false && $row('admin@example.test')['status'] === 'active');
$post('/admin/accounts/3/roles', ['roles' => []], 3);
check('roles: the last administrator keeps the admin role', $get('/admin/settings', 3)['status'] === 200);
$post('/admin/roles/' . $roleId . '/delete', [], 3);
check('role: deleting it takes the permission from its accounts', $get('/admin/pages', 1)['status'] === 403);

$accounts->create('gone@example.test', $pw, 'de', verified: true);
$goneId = (int) $row('gone@example.test')['id'];
$post('/admin/accounts/' . $goneId . '/delete', [], 3);
check('accounts: an administrator can delete another account', $row('gone@example.test') === false);
$pdo->exec('DELETE FROM provider');
@unlink($mailLog);

// --- Language keys -----------------------------------------------------------
// Every key written out in a template or in PHP must exist, or a visitor
// would read the raw key.
$knownKeys = [];
foreach ([$root . '/core/lang/de.php', ...glob($root . '/extensions/*/lang/de.php'), ...glob($root . '/themes/*/lang/de.php')] as $file) {
    $knownKeys += require $file;
}
$missingKeys = [];
$sources = [...glob($root . '/themes/*/templates/{,*/,*/*/}*.twig', GLOB_BRACE), ...glob($root . '/extensions/*/templates/{,*/}*.twig', GLOB_BRACE), ...glob($root . '/core/install/*.twig')];
foreach ($sources as $file) {
    preg_match_all("/trans\\('([a-z0-9_.]+[a-z0-9])'/", (string) file_get_contents($file), $found);
    foreach ($found[1] as $key) {
        if (!isset($knownKeys[$key])) {
            $missingKeys[] = $key . ' in ' . basename($file);
        }
    }
}
foreach (glob($root . '/{core/src,extensions/*/src}/{,*/}*.php', GLOB_BRACE) as $file) {
    preg_match_all("/(?:trans\\(|flash\\('[a-z]+', \\\$this->trans\\(|'key' => |back\\([^,]+, '[a-z]+', |Key\\) => |return |\\\$errors\\[\\] = |UpdateException\\(|result\\([a-z]+, '[a-z_]+', )'((?:core|example|freelancer)\\.[a-z0-9_.]+[a-z0-9])'/", (string) file_get_contents($file), $found);
    foreach ($found[1] as $key) {
        // Offer type ids look like keys but are not.
        if (!isset($knownKeys[$key]) && !in_array($key, ['freelancer.service', 'core.offline'], true)) {
            $missingKeys[] = $key . ' in ' . basename($file);
        }
    }
}
// The wording of every transition, as button and as history entry.
$flowApp = new Modulento\Core\App($config, $pdo);
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'freelancer'");
$flowApp->extensions->loadEnabled($flowApp);
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'freelancer'");
$serviceFlow = $flowApp->orders->flow('freelancer.service');
foreach ($serviceFlow->transitions() as $transitionName => $definition) {
    foreach (['label', 'done'] as $kind) {
        if (!isset($knownKeys[$definition[$kind] ?? ''])) {
            $missingKeys[] = "{$kind} of {$transitionName}";
        }
    }
    if (!isset($serviceFlow->states()[$definition['to']]) && $definition['to'] !== Modulento\Core\Order\Orders::PREVIOUS) {
        $missingKeys[] = "target state of {$transitionName}";
    }
}
foreach ($serviceFlow->states() as $stateName => $definition) {
    if (!isset($knownKeys[$definition['label']])) {
        $missingKeys[] = "label of state {$stateName}";
    }
}
check('every language key used exists: ' . implode(', ', array_unique($missingKeys)), $missingKeys === []);

// --- A second site theme ("indigo") --------------------------------------------
$r = $get('/', null);
check('default theme: no texts of another theme', $r['status'] === 200 && !str_contains($r['body'], 'theme.hero') && !str_contains($r['body'], 'brand-mark'));
$pdo->exec("INSERT INTO setting VALUES ('core.theme', 'indigo')");
$r = $get('/', null);
check('indigo: home page with its own texts, in German', $r['status'] === 200 && str_contains($r['body'], 'class="hero"') && str_contains($r['body'], 'Finde den passenden Freelancer')
    && str_contains($r['body'], 'In drei Schritten zum Ergebnis') && preg_match('/[> "]theme\.[a-z_.]+[<" ]/', $r['body']) === 0);
check('indigo: home page in English', str_contains($get('/en', null)['body'], 'Find the right freelancer') && str_contains($get('/en', null)['body'], 'action="/en/offers"'));
check('indigo: nothing inline and nothing from other hosts', preg_match('/\sstyle="|<style|<script|onclick=|https?:\/\/(?!example\.test)/', $r['body']) === 0);
$r = $get('/login', null);
check('indigo: a page it does not bring comes from default, inside its layout', $r['status'] === 200 && str_contains($r['body'], 'brand-mark') && str_contains($r['body'], 'name="password"'));
check('indigo: stylesheet and font are served from the theme', str_contains($get('/assets/theme/theme.css', null)['body'], 'Plus Jakarta Sans') && $get('/assets/theme/fonts/plus-jakarta-sans-latin.woff2', null)['status'] === 200);
$post('/admin/categories/new', ['text' => ['de' => ['name' => 'Texte', 'slug' => ''], 'en' => ['name' => '', 'slug' => '']]], 3);
$textId = (int) $pdo->query("SELECT category_id FROM category_translation WHERE slug = 'texte'")->fetchColumn();
$r = $get('/', null);
check('indigo: home lists categories with their number of offers', str_contains($r['body'], 'href="/categories/texte"') && str_contains($r['body'], '0 Angebote') && str_contains($r['body'], '<option value="' . $textId . '">Texte</option>'));
check('search with a category chosen leads to the category\'s own address', ($get('/offers?category=' . $textId . '&q=logo', null)['body'] ?? '') === '' && $get('/categories/texte?q=logo', null)['status'] === 200);
$pdo->exec('DELETE FROM category');
$pdo->exec("DELETE FROM setting WHERE name = 'core.theme'");

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
$pdo->exec("DELETE FROM setting WHERE name = 'core.theme'");

// Every template a controller renders must exist in the shipped themes.
foreach (['default' => ['layout/base.twig', 'home.twig', 'error.twig', 'page.twig', 'auth/login.twig', 'auth/register.twig', 'auth/forgot.twig', 'auth/reset.twig', 'account/index.twig', 'emails/verify_email.txt.twig', 'emails/reset_password.txt.twig', 'emails/already_registered.txt.twig', 'emails/change_email.txt.twig', 'emails/password_changed.txt.twig'], 'admin' => ['layout.twig', 'index.twig', 'settings.twig', 'pages.twig', 'page_edit.twig', 'orders.twig', 'order.twig', 'reviews.twig', 'offers.twig', 'offer.twig', 'categories.twig', 'category_edit.twig', 'providers.twig', 'provider.twig', 'accounts.twig', 'account.twig', 'roles.twig', 'role_edit.twig', 'extensions.twig', 'themes.twig', 'tasks.twig', 'updates.twig']] as $theme => $templates) {
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
