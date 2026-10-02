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

// The checks drive the core through two extensions that live in
// repositories of their own, so both have to be there.
foreach (['freelancer', 'auction'] as $needed) {
    if (!is_file($root . '/extensions/' . $needed . '/extension.json')) {
        fwrite(STDERR, "extensions/{$needed} is missing. The tests need the extensions \"freelancer\" and \"auction\":\n"
            . "clone or symlink the repositories alex01at/modulento-ext-freelancer and alex01at/modulento-ext-auction\n"
            . "to extensions/freelancer and extensions/auction, e.g.\n"
            . "  git clone https://github.com/alex01at/modulento-ext-{$needed}.git extensions/{$needed}\n");
        exit(1);
    }
}

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
$pdo->exec("CREATE TABLE withdrawal (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE SET NULL, order_number TEXT, name TEXT, email TEXT, statement TEXT,
    locale TEXT, account_id INTEGER REFERENCES account (id) ON DELETE SET NULL, matched INTEGER NOT NULL DEFAULT 0, created_at TEXT)");
$pdo->exec("ALTER TABLE offer ADD COLUMN rating_count INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE offer ADD COLUMN rating_sum INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE provider ADD COLUMN rating_count INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE provider ADD COLUMN rating_sum INTEGER NOT NULL DEFAULT 0");
$pdo->exec("CREATE TABLE package (kind TEXT, id TEXT, repo TEXT, version TEXT, installed_at TEXT, PRIMARY KEY (kind, id))");
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

$testThemes = sys_get_temp_dir() . '/modulento-test-themes-' . bin2hex(random_bytes(4));
mkdir($testThemes . '/sample/templates', 0777, true);
mkdir($testThemes . '/sample/assets');
mkdir($testThemes . '/sample/lang');
symlink($root . '/themes/default', $testThemes . '/default');
symlink($root . '/themes/admin', $testThemes . '/admin');
file_put_contents($testThemes . '/sample/theme.json', '{"id":"sample","name":"Sample","version":"1.0.0"}');
file_put_contents($testThemes . '/sample/assets/theme.css', '.sample-theme { color: red; }');
file_put_contents($testThemes . '/sample/lang/de.php', "<?php return ['theme.hello' => 'Hallo vom Beispiel-Theme'];");
file_put_contents($testThemes . '/sample/lang/en.php', "<?php return ['theme.hello' => 'Hello from the sample theme'];");
file_put_contents($testThemes . '/sample/templates/home.twig', <<<'TWIG'
{% extends 'layout/base.twig' %}
{% block content %}
<h1 class="sample-home">{{ trans('theme.hello') }}</h1>
<form method="get" action="{{ url('/offers') }}">
    <select name="category">{% for top in categories() %}<option value="{{ top.id }}">{{ top.name }}</option>{% endfor %}</select>
</form>
<ul>{% for top in categories() %}<li><a href="{{ url(top.path) }}">{{ top.name }}</a> {{ top.offer_count }}</li>{% endfor %}</ul>
<p>{{ top_providers(3)|length }} providers</p>
{% endblock %}
TWIG);
$mailLog = sys_get_temp_dir() . '/modulento-test-mail-' . bin2hex(random_bytes(4)) . '.log';
$config = [
    'app' => ['env' => 'dev', 'url' => 'https://example.test', 'name' => 'Testseite', 'root' => $root, 'cron_token' => 'secret-cron-token',
        'uploads' => sys_get_temp_dir() . '/modulento-test-uploads-' . bin2hex(random_bytes(4)), 'themes' => $testThemes,
        'work' => sys_get_temp_dir() . '/modulento-test-work-' . bin2hex(random_bytes(4))],
    'packages' => ['sources' => ['acme/*', 'other/exact']],
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
    // As if the registration form had been open for a while.
    $_SESSION['register_form_at'] ??= time() - 60;
    $_SESSION['withdrawal_form_at'] ??= time() - 60;
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
$r = request($pdo, $config, 'POST', '/save', 1, ['_csrf' => ['x']]);
check('POST with a token that is not text does not run and does not fail', !$r['called'] && $r['status'] !== 500);
$r = request($pdo, $config, 'POST', '/login', null, ['email' => ['a'], 'password' => ['b']]);
check('login with fields that are not text is just a failed login', $r['status'] !== 500 && ($_SESSION['account_id'] ?? null) === null);
check('a page number beyond every list is an empty page, not an error', request($pdo, $config, 'GET', '/admin/accounts?page=99999999999999999999999', 3)['status'] === 200);
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
$get('/register', null);
$r = $post('/register', ['email' => 'quick@example.test', 'password' => $pw, 'password_repeat' => $pw]);
check('register: a form sent back the moment it was shown creates nothing', $row('quick@example.test') === false && str_contains($r['body'], 'Das ging sehr schnell'));

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
$post($mail['link'], [], null);
check('verify: replaced link is refused', $row('new@example.test')['email_verified_at'] === null);
$r = $get($resent['link'], null);
check('verify: opening the link only shows a button, so a mail scanner confirms nothing', $row('new@example.test')['email_verified_at'] === null
    && str_contains($r['body'], 'action="' . $resent['link'] . '"') && str_contains($r['body'], 'Adresse bestätigen'));
$post($resent['link'], [], null);
check('verify: the button confirms the address', $row('new@example.test')['email_verified_at'] !== null);
$post($resent['link'], [], null);
check('verify: link works once', $pdo->query('SELECT COUNT(*) FROM account_token')->fetchColumn() == 0 && $get($resent['link'], null)['body'] === '');

$post('/register', ['email' => 'new@example.test', 'password' => $pw, 'password_repeat' => $pw], null);
check('register again: no second account, owner is told', $pdo->query("SELECT COUNT(*) FROM account WHERE email = 'new@example.test'")->fetchColumn() == 1
    && lastMail($mailLog, 'new@example.test')['subject'] === 'Du hast bereits ein Konto');

$post('/login', ['email' => 'new@example.test', 'password' => 'wrong password!'], null);
check('login: wrong password is refused', ($_SESSION['account_id'] ?? null) === null);
$post('/login', ['email' => 'new@example.test', 'password' => $pw], null);
check('login: works after confirmation', ($_SESSION['account_id'] ?? null) == $new['id']);
$r = $get('/account/settings');
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

$pdo->exec('DELETE FROM rate_limit_attempt');
for ($i = 0; $i < 6; $i++) {
    $post('/login', ['email' => 'plain@example.test', 'password' => 'guess number ' . $i], null);
}
$post('/login', ['email' => 'plain@example.test', 'password' => 'correct horse battery'], null);
check('login: locked after repeated wrong passwords, even with the right one', ($_SESSION['account_id'] ?? null) === null);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$post('/login', ['email' => 'plain@example.test', 'password' => 'correct horse battery'], null);
check('login: wrong passwords typed elsewhere do not lock the owner out', ($_SESSION['account_id'] ?? null) == 1);
$attempts = fn (string $action) => (int) $pdo->query("SELECT COUNT(*) FROM rate_limit_attempt WHERE action = '{$action}'")->fetchColumn();
check('login: a successful login leaves no counted attempt behind', $pdo->query("SELECT COUNT(*) FROM rate_limit_attempt WHERE identifier LIKE '%203.0.113.9'")->fetchColumn() == 0 && $attempts('login-email') === 5);
$r = $post('/login', ['email' => str_repeat('a', 300) . '@example.test', 'password' => 'x'], null);
check('login: an overlong address is counted like any other', $r['status'] !== 500 && $attempts('login') === 6);
unset($_SERVER['REMOTE_ADDR']);
$pdo->exec('DELETE FROM rate_limit_attempt');

$mailsTo = fn (string $to) => substr_count(is_file($mailLog) ? (string) file_get_contents($mailLog) : '', 'To: ' . $to . "\n");
$before = $mailsTo('plain@example.test');
for ($i = 0; $i < 5; $i++) {
    $post('/register', ['email' => 'plain@example.test', 'password' => $pw, 'password_repeat' => $pw], null);
}
check('register: someone else\'s address gets at most three mails an hour', $mailsTo('plain@example.test') - $before === 3);
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
check('locales: the redirect to the plain address never leads to another site', $locales()->split('/de//evil.example/x')['redirect'] === '/evil.example/x'
    && $locales()->split('/de///evil.example')['redirect'] === '/evil.example' && $locales()->split('/de/\\evil.example')['redirect'] === '/evil.example');
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

// The street as it was approved comes first: "+" keeps the left value.
$post('/account/provider', ['street' => 'Hauptstraße 1/4', 'text' => ['de' => ['headline' => 'Neu', 'description' => 'x'], 'en' => ['headline' => 'New', 'description' => 'y']]] + $business, 1);
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
check('a provider finds the own offers from every page, others are offered to become one', str_contains($get('/', 1)['body'], 'href="/account/offers"') && !str_contains($get('/', 3)['body'], 'href="/account/offers"')
    && str_contains($get('/account', 3)['body'], 'Anbieter werden') && str_contains($get('/account/offers', 1)['body'], 'class="account-nav"'));
$r = $get('/account', 3);
check('overview: greeting, numbers and the way to become a provider', $r['status'] === 200 && str_contains($r['body'], 'Hallo, admin!') && str_contains($r['body'], 'Offene Bestellungen') && str_contains($r['body'], 'Selbst anbieten')
    && !str_contains($r['body'], 'Offene Aufträge'));
$r = $get('/account', 1);
check('overview of a provider: sales, offers and a button per kind of offer', str_contains($r['body'], 'Offene Aufträge') && str_contains($r['body'], 'Öffentliche Angebote') && str_contains($r['body'], '/account/offers/new?type=freelancer.service'));
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
$pdo->exec("UPDATE provider SET status = 'pending' WHERE account_id = 1");
foreach (['Zwei', 'Drei', 'Vier'] as $title) {
    $post('/account/offers/new', ['text' => ['de' => ['title' => 'Angebot ' . $title, 'summary' => '', 'description' => '']]] + $offerForm, 1);
}
check('offers: a profile that is not approved yet gets three, no more', $pdo->query('SELECT COUNT(*) FROM offer')->fetchColumn() == 3 && str_contains($_SESSION['_flash']['error'] ?? '', 'Höchstzahl von 3'));
$pdo->exec("UPDATE provider SET status = 'approved' WHERE account_id = 1");
$pdo->exec("DELETE FROM offer WHERE id <> {$offerId}");
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
for ($i = 0; $i < 4; $i++) {
    $r = $post($orderPath, ['package' => '1'], 2);
}
check('no more than three open orders of one buyer for one offer', $lastOrder() === 3 && str_contains($r['body'], 'bereits 3 offene Bestellungen'));
$pdo->exec('DELETE FROM orders');

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

// --- Auctions: an extension whose orders come from bids, not the order form ------
$pdo->exec("CREATE TABLE x_auction_lot (offer_id INTEGER PRIMARY KEY REFERENCES offer (id) ON DELETE CASCADE, start_price INTEGER, step INTEGER, duration_days INTEGER,
    status TEXT DEFAULT 'pending', next_min INTEGER, current_price INTEGER, bid_count INTEGER NOT NULL DEFAULT 0, ends_at TEXT, closed_at TEXT, order_id INTEGER REFERENCES orders (id) ON DELETE SET NULL)");
$pdo->exec("CREATE TABLE x_auction_bid (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES x_auction_lot (offer_id) ON DELETE CASCADE, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    amount INTEGER, terms_accepted INTEGER, created_at TEXT)");
$pdo->exec("INSERT INTO extension VALUES ('auction', '0.1.0', 1)");
$pdo->exec("UPDATE provider SET status = 'approved' WHERE account_id IN (1, 2)");
$pdo->exec("DELETE FROM rate_limit_attempt");
$insertAccount->execute([7, 'bidder@example.test', $testHash, 'active']);
$post('/admin/pages/new', ['status' => 'published', 'role' => 'terms', 'text' => ['de' => $text('AGB')]], 3);
$lot = fn () => $pdo->query('SELECT * FROM x_auction_lot ORDER BY offer_id DESC')->fetch();
$lotForm = ['type' => 'auction.lot', 'text' => ['de' => ['title' => 'Alte Kamera', 'summary' => 'Analog', 'description' => 'Funktioniert'], 'en' => ['title' => '', 'summary' => '', 'description' => '']],
    'start_price' => '10', 'step' => '', 'duration_days' => '3'];
// What bin/cron.php does every minute.
$closeAuctions = function () use ($pdo, $config): int {
    $_SESSION = [];
    $app = new Modulento\Core\App($config, $pdo);
    $app->translator->load($config['app']['root'] . '/core/lang', 'core');
    Modulento\Core\Kernel::registerCore($app);
    $app->extensions->loadEnabled($app);
    Modulento\Core\Kernel::registerLast($app);
    Modulento\Core\Kernel::loadThemeTexts($app);
    Modulento\Core\Kernel::prepareRequest($app, '/', startSession: false);

    return (new Modulento\Auction\Auctions($pdo))->closeDue($app);
};

$r = $get('/account/offers/new?type=auction.lot', 1);
check('auction: the offer form shows the lot\'s fields', $r['status'] === 200 && str_contains($r['body'], 'name="start_price"') && str_contains($r['body'], 'name="duration_days"'));
$r = $post('/account/offers/new', ['start_price' => '0,50', 'duration_days' => '4'] + $lotForm, 1);
check('auction: starting price and duration are checked', $lot() === false && str_contains($r['body'], 'Startpreis zwischen') && str_contains($r['body'], 'Laufzeit'));
$post('/account/offers/new', $lotForm, 1);
$lotId = (int) ($lot()['offer_id'] ?? 0);
check('auction: a new lot waits, with the starting price as the offer\'s price', $lotId > 0 && $lot()['status'] === 'pending' && $lot()['ends_at'] === null && (int) $lot()['next_min'] === 1000
    && (int) $lot()['step'] === 100 && (int) $offerRow("id = {$lotId}")['price_from'] === 1000);
$bid = function (string $amount, ?int $as, array $more = ['accept_terms' => '1']) use (&$lotId, $post): array {
    return $post('/auction/' . $lotId . '/bid', ['amount' => $amount] + $more, $as);
};
check('auction: no bids before the offer is public', $bid('10', 2)['status'] === 404 && (int) $lot()['bid_count'] === 0);

$post('/account/offers/' . $lotId . '/submit', [], 1);
check('auction: the clock does not run while the offer waits for review', $offerRow("id = {$lotId}")['status'] === 'pending' && $lot()['status'] === 'pending');
$post('/admin/offers/' . $lotId . '/decide', ['decision' => 'approve'], 3);
check('auction: publication starts the clock', $lot()['status'] === 'open' && $lot()['ends_at'] > gmdate('Y-m-d H:i:s', time() + 3 * 86400 - 60) && $lot()['ends_at'] <= gmdate('Y-m-d H:i:s', time() + 3 * 86400));

$r = $get('/offers/alte-kamera', 2);
check('auction: the offer page shows price, time left and the bid form with a binding button', $r['status'] === 200 && str_contains($r['body'], 'Startpreis') && str_contains($r['body'], '10,00 €')
    && str_contains($r['body'], 'action="/auction/' . $lotId . '/bid"') && str_contains($r['body'], 'Verbindlich bieten') && str_contains($r['body'], 'name="accept_terms"') && preg_match('/>Noch [23] T\. \d+ Std\. \d+ Min\. \d+ Sek\.</', $r['body']) === 1
    && preg_match('#data-auction-ends="\d{4}-\d\d-\d\dT[\d:]{8}Z"#', $r['body']) === 1 && str_contains($r['body'], '/assets/ext/auction/countdown.js'));
check('auction: a visitor is sent to the login, the provider cannot bid', str_contains($get('/offers/alte-kamera', null)['body'], 'Melde dich an, um mitzubieten')
    && !str_contains($get('/offers/alte-kamera', 1)['body'], '/bid"') && $bid('10', null)['status'] === 403);
$ordersBefore = $lastOrder();
check('auction: a lot cannot be ordered through the order form', $get('/offers/alte-kamera/order', 2)['status'] === 404 && $post('/offers/alte-kamera/order', [], 2)['status'] === 404 && $lastOrder() === $ordersBefore);

$bid('10', 1);
check('auction: nobody bids on the own lot', (int) $lot()['bid_count'] === 0);
$bid('9,99', 2);
check('auction: a bid below the starting price is refused, with the minimum named', (int) $lot()['bid_count'] === 0 && str_contains($_SESSION['_flash']['error'] ?? '', '10,00 €'));
$bid('zehn', 2);
$bid(' ', 2);
check('auction: a bid has to be an amount', (int) $lot()['bid_count'] === 0);
$bid('10', 2, []);
check('auction: published terms have to be accepted with the bid', (int) $lot()['bid_count'] === 0);
$bid('1000000', 2);
check('auction: no bid beyond the platform\'s limit', (int) $lot()['bid_count'] === 0);

$bid('10', 2);
check('auction: the first bid at the starting price counts', (int) $lot()['bid_count'] === 1 && (int) $lot()['current_price'] === 1000 && (int) $lot()['next_min'] === 1100
    && (int) $offerRow("id = {$lotId}")['price_from'] === 1000 && str_contains($_SESSION['_flash']['success'] ?? '', '10,00 €'));
$bid('15', 2);
check('auction: the highest bidder cannot outbid themselves', (int) $lot()['bid_count'] === 1);
check('auction: the highest bidder is told so instead of seeing the form', str_contains($get('/offers/alte-kamera', 2)['body'], 'Du bist Höchstbietender') && !str_contains($get('/offers/alte-kamera', 2)['body'], '/bid"'));
$bid('10,50', 7);
check('auction: the next bid has to exceed the highest by the step', (int) $lot()['bid_count'] === 1 && str_contains($_SESSION['_flash']['error'] ?? '', '11,00 €'));
$bid('12,50', 7, ['accept_terms' => '1', 'next_min' => '1', 'account_id' => '2']);
check('auction: a higher bid leads, the catalogue price follows', (int) $lot()['bid_count'] === 2 && (int) $lot()['current_price'] === 1250 && (int) $lot()['next_min'] === 1350
    && (int) $offerRow("id = {$lotId}")['price_from'] === 1250 && $pdo->query('SELECT account_id FROM x_auction_bid ORDER BY id DESC')->fetchColumn() == 7);
$mail = lastMail($mailLog, 'editor@example.test');
check('auction: the outbid bidder gets a mail with the way back', $mail['subject'] === 'Du wurdest überboten: Alte Kamera' && $mail['link'] === '/offers/alte-kamera');
$r = $get('/offers/alte-kamera', 2);
check('auction: the history shows amounts but no names', str_contains($r['body'], 'Aktuelles Gebot') && str_contains($r['body'], '12,50 €') && str_contains($r['body'], 'Bieter 2') && str_contains($r['body'], '2 Gebote')
    && !str_contains($r['body'], 'bidder@example.test') && str_contains($r['body'], 'value="13,50"'));

// Two requests that both saw 13,50 as the minimum: only one UPDATE finds it still true.
$auctions = new Modulento\Auction\Auctions($pdo);
$stale = $auctions->lot($lotId);
check('auction: a bid is decided by the row as it is, not as it was read', $auctions->bid($lotId, 2, 1400, true) === null && $auctions->bid($lotId, 3, 1400, true) === 'auction.error.too_low'
    && (int) $lot()['bid_count'] === 3 && $stale['next_min'] === 1350);
$pdo->exec("UPDATE offer SET price_from = 1400 WHERE id = {$lotId}");

$post('/account/offers/' . $lotId, ['start_price' => '1', 'step' => '50', 'duration_days' => '14'] + $lotForm, 1);
check('auction: a running lot keeps its terms when the offer is edited', (int) $lot()['start_price'] === 1000 && (int) $lot()['step'] === 100 && (int) $lot()['duration_days'] === 3 && $lot()['status'] === 'open'
    && (int) $lot()['bid_count'] === 3 && (int) $offerRow("id = {$lotId}")['price_from'] === 1400);
check('auction: the form says why', str_contains($get('/account/offers/' . $lotId, 1)['body'], 'lassen sich jetzt nicht mehr ändern') && !str_contains($get('/account/offers/' . $lotId, 1)['body'], 'name="start_price"'));

// A bid in the last minutes keeps the lot open for an answer.
$pdo->exec("UPDATE x_auction_lot SET ends_at = '" . gmdate('Y-m-d H:i:s', time() + 30) . "'");
$bid('16', 7);
check('auction: a late bid extends the end', (int) $lot()['current_price'] === 1600 && $lot()['ends_at'] > gmdate('Y-m-d H:i:s', time() + 100));
check('auction: nothing closes before its time', $closeAuctions() === 0 && $lot()['status'] === 'open');

$pdo->exec("UPDATE x_auction_lot SET ends_at = '" . gmdate('Y-m-d H:i:s', time() - 5) . "'");
$bid('20', 2);
check('auction: no bid after the end, even before the lot is closed', (int) $lot()['bid_count'] === 4 && str_contains($get('/offers/alte-kamera', 2)['body'], 'wird ausgewertet'));
$ordersBefore = $lastOrder();
check('auction: the due lot is closed once', $closeAuctions() === 1 && $closeAuctions() === 0 && $lot()['status'] === 'sold' && $lot()['closed_at'] !== null);
$saleId = $lastOrder();
$sale = $orderRow($saleId);
check('auction: the highest bid becomes an order at its amount', $saleId > $ordersBefore && (int) $lot()['order_id'] === $saleId && (int) $sale['buyer_id'] === 7 && (int) $sale['total'] === 1600
    && $sale['flow'] === 'auction.sale' && $sale['state'] === 'sold' && $sale['offer_title'] === 'Alte Kamera' && $sale['terms_accepted_at'] !== null && $sale['payment_method'] === 'core.offline'
    && $pdo->query("SELECT label || ':' || quantity || ':' || unit_price FROM order_item WHERE order_id = {$saleId}")->fetchColumn() === 'Alte Kamera:1:1600');
check('auction: buyer and provider are both told about the sale', lastMail($mailLog, 'bidder@example.test')['subject'] === 'Bestellung ' . sprintf('%06d', $saleId) . ': Zuschlag erteilt'
    && lastMail($mailLog, 'plain@example.test')['subject'] === 'Bestellung ' . sprintf('%06d', $saleId) . ': Zuschlag erteilt');
check('auction: the ended lot leaves the catalogue', $offerRow("id = {$lotId}")['status'] === 'paused' && $get('/offers/alte-kamera', null)['status'] === 404);
$post('/account/offers/' . $lotId . '/resume', [], 1);
$post('/account/offers/' . $lotId, ['start_price' => '1'] + $lotForm, 1);
check('auction: a sold lot does not run again', $lot()['status'] === 'sold' && (int) $lot()['start_price'] === 1000 && str_contains($get('/offers/alte-kamera', 2)['body'], 'Beendet – verkauft')
    && !str_contains($get('/offers/alte-kamera', 2)['body'], '/bid"') && $bid('30', 2)['status'] !== 404 && (int) $lot()['bid_count'] === 4);

$r = $get('/orders/' . $saleId, 7);
check('auction: the order page tells the sale\'s story', $r['status'] === 200 && str_contains($r['body'], 'Zuschlag nach 4 Geboten') && str_contains($r['body'], 'Zuschlag erteilt') && str_contains($r['body'], '16,00 €')
    && !str_contains($r['body'], 'Als übergeben oder versandt markieren') && $get('/orders/' . $saleId, 2)['status'] === 404);
$act($saleId, 'confirm', 7);
$act($saleId, 'hand_over', 7);
check('auction: the buyer cannot confirm or hand over ahead of the provider', $orderRow($saleId)['state'] === 'sold');
$act($saleId, 'hand_over', 1, 'Paket ist unterwegs');
check('auction: handover starts the time to confirm', $orderRow($saleId)['state'] === 'handed_over' && $orderRow($saleId)['due_transition'] === 'auto_complete' && $orderRow($saleId)['due_at'] > gmdate('Y-m-d H:i:s', time() + 13 * 86400));
$act($saleId, 'request_cancel', 7, 'Nicht angekommen');
$act($saleId, 'agree_cancel', 7);
check('auction: a cancellation needs the other side', $orderRow($saleId)['state'] === 'cancel_requested');
$act($saleId, 'refuse_cancel', 1, 'Sendungsnummer folgt');
$act($saleId, 'confirm', 7);
check('auction: back where it was, then completed and open for a review', $orderRow($saleId)['state'] === 'completed' && $orderRow($saleId)['closed_at'] !== null && str_contains($get('/orders/' . $saleId, 7)['body'], 'name="rating"'));

// A second lot: no bids, set up again, then a leading bidder who disappears.
$post('/account/offers/new', ['text' => ['de' => ['title' => 'Stativ', 'summary' => '', 'description' => '']], 'start_price' => '5', 'step' => '0,50', 'duration_days' => '1'] + $lotForm, 1);
$lotId = (int) $lot()['offer_id'];
$post('/account/offers/' . $lotId . '/submit', [], 1);
$post('/admin/offers/' . $lotId . '/decide', ['decision' => 'approve'], 3);
$pdo->exec("UPDATE x_auction_lot SET ends_at = '" . gmdate('Y-m-d H:i:s', time() - 5) . "' WHERE offer_id = {$lotId}");
$ordersBefore = $lastOrder();
check('auction: a lot without bids ends unsold, without an order', $closeAuctions() === 1 && $lot()['status'] === 'unsold' && $lastOrder() === $ordersBefore && $offerRow("id = {$lotId}")['status'] === 'paused'
    && lastMail($mailLog, 'plain@example.test')['subject'] === 'Auktion ohne Gebot beendet: Stativ' && lastMail($mailLog, 'plain@example.test')['link'] === '/account/offers/' . $lotId);
check('auction: the form offers to run it again', str_contains($get('/account/offers/' . $lotId, 1)['body'], 'ohne Verkauf zu Ende gegangen') && str_contains($get('/account/offers/' . $lotId, 1)['body'], 'name="start_price"'));
$post('/account/offers/' . $lotId, ['text' => ['de' => ['title' => 'Stativ', 'summary' => '', 'description' => '']], 'start_price' => '4', 'step' => '0,50', 'duration_days' => '1'] + $lotForm, 1);
check('auction: saving sets the lot up again, it waits while the offer is paused', $lot()['status'] === 'pending' && (int) $lot()['start_price'] === 400 && $lot()['ends_at'] === null && $lot()['closed_at'] === null);
$post('/account/offers/' . $lotId . '/resume', [], 1);
check('auction: publishing again starts a new run', $lot()['status'] === 'open' && $lot()['ends_at'] > gmdate('Y-m-d H:i:s'));

$bid('4', 2);
$bid('4,50', 7);
check('auction: the step the provider chose applies', (int) $lot()['current_price'] === 450 && (int) $lot()['next_min'] === 500);
$export = json_decode($get('/account/export', 7)['body'], true);
check('auction: an account\'s bids are part of its data', array_column($export['auction']['bids'] ?? [], 'amount') === [1250, 1600, 450]);
$pdo->exec("UPDATE account SET status = 'blocked' WHERE id = 7");
check('auction: a blocked account does not lead', ($auctions->highBid($lotId)['account_id'] ?? null) === 2);
$pdo->exec("UPDATE account SET status = 'active' WHERE id = 7");
$post('/account/delete', ['current_password' => 'correct horse battery'], 2);
check('auction: an account with a running bid can be deleted; the lot follows its remaining bids', $row('editor@example.test') === false && (int) $lot()['bid_count'] === 1 && (int) $lot()['current_price'] === 450);
$pdo->exec("UPDATE provider SET status = 'suspended' WHERE account_id = 1");
$pdo->exec("UPDATE x_auction_lot SET ends_at = '" . gmdate('Y-m-d H:i:s', time() - 5) . "' WHERE offer_id = {$lotId}");
$ordersBefore = $lastOrder();
check('auction: a lot of a suspended provider is cancelled, not sold', $closeAuctions() === 1 && $lot()['status'] === 'cancelled' && $lastOrder() === $ordersBefore);
$pdo->exec("UPDATE provider SET status = 'approved' WHERE account_id = 1");
$post('/account/offers/' . $lotId . '/delete', [], 1);
check('auction: deleting the offer removes lot and bids', $pdo->query("SELECT COUNT(*) FROM x_auction_lot WHERE offer_id = {$lotId}")->fetchColumn() == 0 && $pdo->query("SELECT COUNT(*) FROM x_auction_bid WHERE offer_id = {$lotId}")->fetchColumn() == 0);

$pdo->exec('DELETE FROM offer');
$pdo->exec('DELETE FROM account WHERE id = 7');
$insertAccount->execute([2, 'editor@example.test', $testHash, 'active']);
$pdo->exec("INSERT INTO account_role VALUES (2, 1)");
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'auction'");
$pdo->exec('DELETE FROM page');
$pdo->exec("DELETE FROM rate_limit_attempt");

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

// --- Withdrawal form: declare, confirm, acknowledge, pass on -----------------------
$pdo->exec("INSERT INTO provider (id, account_id, type, status, name, slug, created_at, updated_at)
    VALUES (901, 1, 'business', 'approved', 'Testanbieter', 'testanbieter-widerruf', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO orders (id, buyer_id, provider_id, flow, state, state_actor, buyer_name, provider_name, offer_title, total, currency, locale, payment_method, data, created_at, updated_at)
    VALUES (9001, 2, 901, 'gone.flow', 'done', 'buyer', 'editor@example.test', 'Testanbieter', 'Ein Logo', 1000, 'EUR', 'de', 'core.offline', '{}', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
// The provider reads English: what is passed on has to arrive in that language.
$pdo->exec("UPDATE account SET locale = 'en' WHERE id = 1");
$pdo->exec('DELETE FROM rate_limit_attempt');
$withdrawals = fn (): array => $pdo->query('SELECT * FROM withdrawal ORDER BY id')->fetchAll();
$orderNotes = fn (): array => $pdo->query('SELECT * FROM order_message WHERE order_id = 9001 ORDER BY id')->fetchAll();
$mailText = function (string $to) use ($mailLog): string {
    $mails = is_file($mailLog) ? array_filter(explode("\n--\n", (string) file_get_contents($mailLog))) : [];
    foreach (array_reverse($mails) as $mail) {
        if (str_starts_with(ltrim($mail), 'To: ' . $to . "\n")) {
            return $mail;
        }
    }

    return '';
};
$declaration = ['name' => 'Erika Muster', 'email' => 'Editor@Example.test ', 'order_number' => '009001', 'statement' => "Nur das Extra.\n<b>fett</b>"];
// Both steps as a visitor would take them; returns the answer of the second.
// Unless told otherwise, earlier declarations do not count against the limits.
$declare = function (array $fields, int|null $as = null, bool $counted = false) use ($post, $pdo): array {
    if (!$counted) {
        $pdo->exec('DELETE FROM rate_limit_attempt');
    }
    $post('/withdrawal', $fields, $as);

    return $post('/withdrawal/confirm', []);
};

check('withdrawal: the footer of every page links to the form', str_contains($get('/', null)['body'], '<a href="/withdrawal">Vertrag widerrufen</a>')
    && str_contains($get('/login', null)['body'], '<a href="/withdrawal">Vertrag widerrufen</a>'));
check('withdrawal: the link in another language', str_contains($get('/en', null)['body'], '<a href="/en/withdrawal">Withdraw from contract</a>'));
$r = $get('/withdrawal', null);
check('withdrawal: the form is public and says what it does not do', $r['status'] === 200 && str_contains($r['body'], 'name="order_number"') && str_contains($r['body'], 'name="website"')
    && str_contains($r['body'], '>Weiter</button>') && str_contains($r['body'], 'ändert sich dadurch nicht automatisch') && !str_contains($r['body'], '<select'));
$r = $get('/withdrawal?order=009001', 2);
check('withdrawal: a logged-in buyer finds address and own orders filled in', str_contains($r['body'], 'value="editor@example.test"') && str_contains($r['body'], '<option value="009001" selected>'));
check('withdrawal: an order of someone else is not offered', !str_contains($get('/withdrawal', 1)['body'], '009001'));
check('withdrawal: a query parameter that is not text is ignored', $get('/withdrawal?order[]=1', null)['status'] === 200);
check('withdrawal: the buyer\'s order page links to the form with its number, the provider\'s does not',
    str_contains($get('/orders/9001', 2)['body'], 'href="/withdrawal?order=009001"') && !str_contains($get('/orders/9001', 1)['body'], '/withdrawal?order='));

$r = $post('/withdrawal', ['name' => '', 'email' => 'editor@example.test', 'order_number' => ''], null);
check('withdrawal: name and order number are required', str_contains($r['body'], 'Bitte gib deinen Namen an') && str_contains($r['body'], 'Bitte gib die Bestellnummer an') && !isset($_SESSION['withdrawal_draft']));
$r = $post('/withdrawal', ['email' => 'not-an-address'] + $declaration, null);
check('withdrawal: an invalid address is refused', str_contains($r['body'], 'gültige E-Mail-Adresse') && !isset($_SESSION['withdrawal_draft']));
$r = $post('/withdrawal', ['statement' => str_repeat('ä', 5001)] + $declaration, null);
check('withdrawal: a text that is too long is refused', str_contains($r['body'], 'höchstens 5000 Zeichen') && !isset($_SESSION['withdrawal_draft']));
$r = $post('/withdrawal', ['name' => ['x'], 'email' => ['y'], 'order_number' => ['z'], 'statement' => ['s'], 'order_choice' => ['c']], null);
check('withdrawal: fields that are not text count as empty', $r['status'] === 200 && str_contains($r['body'], 'Bitte gib deinen Namen an') && !isset($_SESSION['withdrawal_draft']));
$get('/withdrawal', null);
$r = $post('/withdrawal', $declaration);
check('withdrawal: a form sent back the moment it was shown is refused', str_contains($r['body'], 'Das ging sehr schnell') && !isset($_SESSION['withdrawal_draft']));

$r = $post('/withdrawal', $declaration, null);
check('withdrawal: the first step shows the details and a clearly worded button', str_contains($r['body'], 'action="/withdrawal/confirm"') && str_contains($r['body'], '>Widerruf bestätigen</button>')
    && str_contains($r['body'], 'Erika Muster') && str_contains($r['body'], '&lt;b&gt;fett&lt;/b&gt;') && !str_contains($r['body'], '<b>fett'));
check('withdrawal: without the second step nothing is stored and nothing sent', $withdrawals() === [] && !is_file($mailLog) && $orderNotes() === []);
check('withdrawal: going back keeps what was entered', str_contains($get('/withdrawal')['body'], 'value="Erika Muster"'));
$post('/withdrawal/confirm', [], null);
check('withdrawal: confirming without the first step stores nothing', $withdrawals() === [] && str_contains($_SESSION['_flash']['error'] ?? '', 'liegen nicht mehr vor'));

$post('/withdrawal', $declaration, null);
$post('/withdrawal/confirm', ['name' => 'Jemand anders', 'email' => 'other@example.test', 'order_number' => '1', 'statement' => 'x']);
$stored = $withdrawals();
$first = $stored[0] ?? [];
check('withdrawal: the second step stores what the first one checked, nothing it is sent itself', count($stored) === 1 && $first['name'] === 'Erika Muster'
    && $first['email'] === 'editor@example.test' && $first['order_number'] === '009001' && $first['locale'] === 'de' && str_contains($first['statement'], 'Nur das Extra.'));
check('withdrawal: number and buyer\'s address together assign it to the order', (int) $first['matched'] === 1 && (int) $first['order_id'] === 9001 && (int) $first['account_id'] === 2);
$receipt = $mailText('editor@example.test');
check('withdrawal: acknowledgement with the declaration, date and time of receipt', str_contains($receipt, 'Subject: Eingangsbestätigung: dein Widerruf zur Bestellung 009001')
    && str_contains($receipt, 'Eingang: ' . $first['created_at'] . ' UTC') && str_contains($receipt, 'Name: Erika Muster') && str_contains($receipt, "Nur das Extra.\n<b>fett</b>")
    && str_contains($receipt, 'nicht die Anerkennung des Widerrufs') && abs(strtotime($first['created_at'] . ' UTC') - time()) < 5);
$forwarded = $mailText('plain@example.test');
check('withdrawal: passed on to the provider in their language, with the order', str_contains($forwarded, 'Subject: Withdrawal for order 009001') && str_contains($forwarded, 'Reply-To: editor@example.test')
    && str_contains($forwarded, 'Nur das Extra.') && lastMail($mailLog, 'plain@example.test')['link'] === '/en/orders/9001' && lastMail($mailLog, 'admin@example.test') === null);
check('withdrawal: both sides read it on the order page', count($orderNotes()) === 1 && $orderNotes()[0]['author_role'] === 'buyer' && (int) $orderNotes()[0]['account_id'] === 2
    && str_contains($get('/orders/9001', 1)['body'], 'Widerrufserklärung über das Widerrufsformular') && str_contains($get('/orders/9001', 2)['body'], 'Name: Erika Muster'));
check('withdrawal: the order itself is not changed', $orderRow(9001)['state'] === 'done' && $orderRow(9001)['closed_at'] === null);

// What the sender is told must not depend on whether the order was found.
$answer = function (array $fields, int|null $as = null) use ($declare, $get, $withdrawals): string {
    $declare($fields, $as);
    $body = $get('/withdrawal/done')['body'];
    $row = $withdrawals()[count($withdrawals()) - 1];

    return str_replace([$row['email'], $row['created_at']], ['ADDRESS', 'TIME'], $body);
};
$pdo->exec('DELETE FROM withdrawal');
$matchedAnswer = $answer($declaration);
check('withdrawal: the answer names the time of receipt and the address', str_contains($matchedAnswer, 'Widerruf eingegangen') && str_contains($matchedAnswer, 'am TIME UTC') && str_contains($matchedAnswer, 'an ADDRESS'));
$post('/withdrawal/confirm', []);
check('withdrawal: a second click on the button declares nothing twice', count($withdrawals()) === 1);

@unlink($mailLog);
$strangerAnswer = $answer(['email' => 'stranger@example.test'] + $declaration);
$second = $withdrawals()[1] ?? [];
check('withdrawal: a number with another address is stored but not assigned', count($withdrawals()) === 2 && (int) $second['matched'] === 0 && $second['order_id'] === null && $second['account_id'] === null
    && count($orderNotes()) === 2 && $mailText('plain@example.test') === '');
check('withdrawal: the sender gets the same answer and the same acknowledgement', $strangerAnswer === $matchedAnswer
    && str_contains($mailText('stranger@example.test'), 'Subject: Eingangsbestätigung: dein Widerruf zur Bestellung 009001'));
check('withdrawal: what cannot be assigned goes to the active administrators', str_contains($mailText('admin@example.test'), 'Subject: Widerruf ohne zugeordnete Bestellung (009001)')
    && str_contains($mailText('admin@example.test'), 'Reply-To: stranger@example.test') && lastMail($mailLog, 'admin@example.test')['link'] === '/admin/withdrawals' && $mailText('blocked@example.test') === '');
check('withdrawal: a number that does not exist gets the same answer', $answer(['order_number' => '999999'] + $declaration) === $matchedAnswer && (int) $withdrawals()[2]['matched'] === 0);
check('withdrawal: something that is not a number gets the same answer', $answer(['order_number' => '9001 OR 1=1'] + $declaration) === $matchedAnswer && (int) $withdrawals()[3]['matched'] === 0);

$declare(['email' => 'private@example.test', 'order_number' => '#9001'] + $declaration, 2);
$own = $withdrawals()[4] ?? [];
check('withdrawal: the logged-in buyer is assigned whatever address the acknowledgement goes to', (int) ($own['matched'] ?? 0) === 1 && (int) $own['account_id'] === 2
    && str_contains($mailText('private@example.test'), 'Eingangsbestätigung') && $own['order_number'] === '#9001');
$declare(['order_number' => '9001'] + $declaration, 3);
check('withdrawal: another logged-in account with the buyer\'s address is assigned by the address only', (int) $withdrawals()[5]['matched'] === 1 && (int) $withdrawals()[5]['account_id'] === 3);
$pdo->exec("UPDATE account SET status = 'blocked' WHERE id = 1");
@unlink($mailLog);
$declare($declaration);
check('withdrawal: a provider who cannot be reached leaves it to the platform', (int) $withdrawals()[6]['matched'] === 1 && $mailText('plain@example.test') === '' && $mailText('admin@example.test') !== '');
$pdo->exec("UPDATE account SET status = 'active' WHERE id = 1");

$before = count($withdrawals());
@unlink($mailLog);
$r = $declare(['email' => 'bot@example.test', 'website' => 'http://spam'] + $declaration);
check('withdrawal: a filled bot trap gets the usual answer, and nothing is stored or sent', count($withdrawals()) === $before && !is_file($mailLog) && str_contains($get('/withdrawal/done')['body'], 'Widerruf eingegangen'));

$pdo->exec('DELETE FROM rate_limit_attempt');
for ($i = 0; $i < 3; $i++) {
    $declare(['email' => 'flood@example.test'] + $declaration, null, true);
}
$before = count($withdrawals());
$r = $declare(['email' => 'flood@example.test'] + $declaration, null, true);
check('withdrawal: no more than three an hour to one address, and the sender is told', count($withdrawals()) === $before && str_contains($r['body'], 'NICHT übermittelt') && str_contains($r['body'], 'Widerruf bestätigen'));
$declare(['email' => 'calm@example.test'] + $declaration, null, true);
$r = $declare(['email' => 'late@example.test'] + $declaration, null, true);
check('withdrawal: no more than five an hour from one address of the network', count($withdrawals()) === $before + 1 && str_contains($r['body'], 'NICHT übermittelt') && $mailText('late@example.test') === '');

check('withdrawal: the list in the administration needs its permission', $get('/admin/withdrawals', 2)['status'] === 403 && $get('/admin/withdrawals', null)['body'] === '');
$r = $get('/admin/withdrawals', 3);
check('withdrawal: the administration lists every declaration with its order', $r['status'] === 200 && str_contains($r['body'], 'Erika Muster') && str_contains($r['body'], 'href="/admin/orders/9001"')
    && str_contains($r['body'], 'nicht zugeordnet') && str_contains($r['body'], 'stranger@example.test') && str_contains($r['body'], $first['created_at'] . ' UTC')
    && str_contains($r['body'], '&lt;b&gt;fett&lt;/b&gt;') && !str_contains($r['body'], '<b>fett') && str_contains($r['body'], 'href="/admin/withdrawals" aria-current="page"'));
$export = json_decode($get('/account/export', 2)['body'], true);
check('withdrawal: an account\'s declarations are part of its data', count($export['withdrawals'] ?? []) >= 2 && ($export['withdrawals'][0]['order_number'] ?? '') === '009001'
    && ($export['withdrawals'][0]['matched'] ?? null) === true && !isset(json_decode($get('/account/export', 1)['body'], true)['withdrawals']));
$pdo->exec('DELETE FROM orders WHERE id = 9001');
check('withdrawal: a declaration outlives its order', count($withdrawals()) === $before + 1 && $withdrawals()[0]['order_id'] === null && (int) $withdrawals()[0]['matched'] === 1
    && str_contains($get('/admin/withdrawals', 3)['body'], 'Bestellung gelöscht'));

$pdo->exec('DELETE FROM withdrawal');
$pdo->exec('DELETE FROM provider');
$pdo->exec("UPDATE account SET locale = 'de' WHERE id = 1");
$pdo->exec('DELETE FROM rate_limit_attempt');
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
    preg_match_all("/(?:trans\\(|flash\\('[a-z]+', \\\$this->trans\\(|'key' => |back\\([^,]+, '[a-z]+', |Key\\) => |return |\\\$errors\\[\\] = |UpdateException\\(|result\\([a-z]+, '[a-z_]+', )'((?:core|example|freelancer|auction)\\.[a-z0-9_.]+[a-z0-9])'/", (string) file_get_contents($file), $found);
    foreach ($found[1] as $key) {
        // Offer type ids look like keys but are not.
        if (!isset($knownKeys[$key]) && !in_array($key, ['freelancer.service', 'auction.lot', 'auction.sale', 'core.offline'], true)) {
            $missingKeys[] = $key . ' in ' . basename($file);
        }
    }
}
// The wording of every transition, as button and as history entry.
$flowApp = new Modulento\Core\App($config, $pdo);
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id IN ('freelancer', 'auction')");
$flowApp->extensions->loadEnabled($flowApp);
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id IN ('freelancer', 'auction')");
foreach (['freelancer.service', 'auction.sale'] as $flowId) {
    $flow = $flowApp->orders->flow($flowId);
    foreach ($flow->transitions() as $transitionName => $definition) {
        foreach (['label', 'done'] as $kind) {
            if (!isset($knownKeys[$definition[$kind] ?? ''])) {
                $missingKeys[] = "{$kind} of {$flowId} {$transitionName}";
            }
        }
        if (!isset($flow->states()[$definition['to']]) && $definition['to'] !== Modulento\Core\Order\Orders::PREVIOUS) {
            $missingKeys[] = "target state of {$flowId} {$transitionName}";
        }
    }
    foreach ($flow->states() as $stateName => $definition) {
        if (!isset($knownKeys[$definition['label']]) || !isset($knownKeys[$definition['entered'] ?? $definition['label']])) {
            $missingKeys[] = "label of state {$flowId} {$stateName}";
        }
    }
}
check('every language key used exists: ' . implode(', ', array_unique($missingKeys)), $missingKeys === []);

// --- A second site theme --------------------------------------------------------
$r = $get('/', null);
check('default theme: no texts of another theme', $r['status'] === 200 && !str_contains($r['body'], 'sample-home'));
$pdo->exec("INSERT INTO setting VALUES ('core.theme', 'sample')");
$r = $get('/', null);
check('theme: its home page with its own texts, in German', $r['status'] === 200 && str_contains($r['body'], '<h1 class="sample-home">Hallo vom Beispiel-Theme</h1>'));
check('theme: its texts in English', str_contains($get('/en', null)['body'], 'Hello from the sample theme') && str_contains($get('/en', null)['body'], 'action="/en/offers"'));
$r = $get('/login', null);
check('theme: a page it does not bring comes from default', $r['status'] === 200 && str_contains($r['body'], 'name="password"') && str_contains($r['body'], 'site-header'));
preg_match('#/assets/theme/theme\.css\?v=[0-9a-f]+#', $r['body'], $sampleCss);
$pdo->exec("DELETE FROM setting WHERE name = 'core.theme'");
$defaultFile = $root . '/themes/default/assets/theme.css';
$defaultTime = filemtime($defaultFile);
// As after an update: both stylesheets carry the same change time.
touch($defaultFile, filemtime($testThemes . '/sample/assets/theme.css'));
clearstatcache();
preg_match('#/assets/theme/theme\.css\?v=[0-9a-f]+#', $get('/login', null)['body'], $defaultCss);
touch($defaultFile, $defaultTime);
clearstatcache();
$pdo->exec("INSERT INTO setting VALUES ('core.theme', 'sample')");
check('the stylesheets of two themes never share an address, even with the same change time', ($sampleCss[0] ?? '') !== '' && ($defaultCss[0] ?? '') !== '' && $sampleCss[0] !== $defaultCss[0]);
check('theme: its stylesheet is the one served', str_contains($get('/assets/theme/theme.css', null)['body'], '.sample-theme'));
$post('/admin/categories/new', ['text' => ['de' => ['name' => 'Texte', 'slug' => ''], 'en' => ['name' => '', 'slug' => '']]], 3);
$textId = (int) $pdo->query("SELECT category_id FROM category_translation WHERE slug = 'texte'")->fetchColumn();
$r = $get('/', null);
check('templates get the category tree with offer counts and the provider showcase', str_contains($r['body'], '<a href="/categories/texte">Texte</a> 0')
    && str_contains($r['body'], '<option value="' . $textId . '">Texte</option>') && str_contains($r['body'], 'providers</p>'));
check('search with a category chosen leads to the category\'s own address', ($get('/offers?category=' . $textId . '&q=logo', null)['body'] ?? '') === '' && $get('/categories/texte?q=logo', null)['status'] === 200);
$pdo->exec('DELETE FROM category');
$pdo->exec("DELETE FROM setting WHERE name = 'core.theme'");

// --- Packages: extensions and themes from their own repositories ---------------------
use Modulento\Core\Package\Packages;
use Modulento\Core\Support\UpdateException;

$packageRoot = sys_get_temp_dir() . '/modulento-test-packages-' . bin2hex(random_bytes(4));
mkdir($packageRoot . '/extensions', 0777, true);
mkdir($packageRoot . '/themes');
$packages = new Packages($pdo, new Modulento\Core\Support\ReleaseClient(''), $packageRoot . '/extensions', $packageRoot . '/themes', $packageRoot . '/work', ['acme/*', 'other/exact']);
check('package sources: patterns decide, case does not matter', $packages->isAllowed('acme/modulento-theme-x') && $packages->isAllowed('ACME/anything') && $packages->isAllowed('other/exact')
    && !$packages->isAllowed('other/else') && !$packages->isAllowed('evil/acme') && !$packages->isAllowed('acme/../x') && !$packages->isAllowed('not-a-repo'));

/** A package zip from name => content; returns [path, sha256]. */
$makePackage = function (array $files) use ($packageRoot): array {
    $path = $packageRoot . '/' . bin2hex(random_bytes(4)) . '.zip';
    return [$path, buildPackage($path, $files)];
};
$themeFiles = fn (string $version, string $css = 'a') => ['theme.json' => json_encode(['id' => 'ocean', 'name' => 'Ocean', 'version' => $version]), 'assets/theme.css' => $css, 'templates/home.twig' => 'x'];
$packageError = function (callable $install): string {
    try {
        $install();
    } catch (UpdateException $e) {
        return $e->messageKey;
    }

    return '';
};

[$zip, $sha] = $makePackage($themeFiles('1.0.0'));
$result = $packages->installArchive($zip, $sha, 'acme/modulento-theme-ocean', '1.0.0');
check('package: a theme is unpacked into themes/<id> and remembered with its source', $result === ['kind' => 'theme', 'id' => 'ocean', 'version' => '1.0.0', 'updated' => false]
    && is_file($packageRoot . '/themes/ocean/theme.json') && $packages->find('theme', 'ocean')['repo'] === 'acme/modulento-theme-ocean' && !is_file($zip));
file_put_contents($packageRoot . '/themes/ocean/local-change.txt', 'x');
[$zip, $sha] = $makePackage($themeFiles('1.1.0', 'b'));
$result = $packages->installArchive($zip, $sha, 'acme/modulento-theme-ocean', '1.1.0');
check('package: an update replaces the folder and keeps the old one as a backup', $result['updated'] && file_get_contents($packageRoot . '/themes/ocean/assets/theme.css') === 'b'
    && !is_file($packageRoot . '/themes/ocean/local-change.txt') && count(glob($packageRoot . '/work/backups/packages/theme-ocean-*/local-change.txt')) === 1 && $packages->find('theme', 'ocean')['version'] === '1.1.0');
[$zip, $sha] = $makePackage($themeFiles('2.0.0', 'evil'));
check('package: a name stays with the repository it came from', $packageError(fn () => $packages->installArchive($zip, $sha, 'acme/other-repo', '2.0.0')) === 'core.package.error.other_source'
    && file_get_contents($packageRoot . '/themes/ocean/assets/theme.css') === 'b');
[$zip, $sha] = $makePackage($themeFiles('1.2.0'));
check('package: a wrong checksum is refused', $packageError(fn () => $packages->installArchive($zip, str_repeat('0', 64), 'acme/modulento-theme-ocean', '1.2.0')) === 'core.update.error.checksum');
[$zip, $sha] = $makePackage($themeFiles('1.2.0'));
check('package: its version has to match the release', $packageError(fn () => $packages->installArchive($zip, $sha, 'acme/modulento-theme-ocean', '1.3.0')) === 'core.update.error.version_mismatch'
    && $packages->find('theme', 'ocean')['version'] === '1.1.0');
foreach (['default', 'admin'] as $shipped) {
    [$zip, $sha] = $makePackage(['theme.json' => json_encode(['id' => $shipped, 'name' => 'x', 'version' => '9.0.0'])]);
    check("package: cannot replace the shipped theme {$shipped}", $packageError(fn () => $packages->installArchive($zip, $sha, 'acme/x', '9.0.0')) === 'core.package.error.shipped');
}
[$zip, $sha] = $makePackage(['readme.txt' => 'nothing here']);
check('package: a zip without a manifest is refused', $packageError(fn () => $packages->installArchive($zip, $sha, 'acme/x', '1.0.0')) === 'core.package.error.manifest');
[$zip, $sha] = $makePackage(['theme.json' => json_encode(['id' => '../../evil', 'version' => '1.0.0'])]);
check('package: an id that is a path is refused', $packageError(fn () => $packages->installArchive($zip, $sha, 'acme/x', '1.0.0')) === 'core.package.error.manifest');
[$zip, $sha] = $makePackage(['theme.json' => json_encode(['id' => 'ocean', 'version' => '1.0.0']), '../outside.txt' => 'x']);
check('package: an entry leaving the folder is refused', $packageError(fn () => $packages->installArchive($zip, $sha, 'acme/modulento-theme-ocean', '1.0.0')) === 'core.update.error.unsafe_entry');

$extensionFiles = fn (int $api) => ['extension.json' => json_encode(['id' => 'shop', 'name' => 'Shop', 'version' => '0.1.0', 'api' => $api, 'namespace' => 'Acme\\Shop']), 'src/Extension.php' => '<?php'];
[$zip, $sha] = $makePackage($extensionFiles(99));
check('package: an extension for another interface version is refused', $packageError(fn () => $packages->installArchive($zip, $sha, 'acme/modulento-shop', '0.1.0')) === 'core.package.error.api' && !is_dir($packageRoot . '/extensions/shop'));
[$zip, $sha] = $makePackage($extensionFiles(Modulento\Core\App::API_VERSION));
check('package: an extension is unpacked into extensions/<id>', $packages->installArchive($zip, $sha, 'acme/modulento-shop', '0.1.0')['kind'] === 'extension' && is_file($packageRoot . '/extensions/shop/src/Extension.php'));
foreach (['example'] as $shipped) {
    [$zip, $sha] = $makePackage(['extension.json' => json_encode(['id' => $shipped, 'version' => '9.0.0', 'api' => 1, 'namespace' => 'X']), 'src/Extension.php' => '<?php']);
    check("package: cannot replace the shipped extension {$shipped}", $packageError(fn () => $packages->installArchive($zip, $sha, 'acme/x', '9.0.0')) === 'core.package.error.shipped');
}
// An installation from the time when the core brought "freelancer" along:
// the folder is there and enabled, but no package.
mkdir($packageRoot . '/extensions/freelancer/src', 0777, true);
file_put_contents($packageRoot . '/extensions/freelancer/extension.json', json_encode(['id' => 'freelancer', 'name' => 'Old', 'version' => '0.1.0', 'api' => 1, 'namespace' => 'X']));
file_put_contents($packageRoot . '/extensions/freelancer/src/Extension.php', '<?php // old');
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'freelancer'");
[$zip, $sha] = $makePackage(['extension.json' => json_encode(['id' => 'freelancer', 'name' => 'New', 'version' => '0.2.0', 'api' => 1, 'namespace' => 'X']), 'src/Extension.php' => '<?php // new', 'migrations/002_more.sql' => 'SELECT 1;']);
$result = $packages->installArchive($zip, $sha, 'acme/modulento-ext-freelancer', '0.2.0');
check('package: an extension the core used to ship is accepted and takes over the existing folder', $result === ['kind' => 'extension', 'id' => 'freelancer', 'version' => '0.2.0', 'updated' => false]
    && file_get_contents($packageRoot . '/extensions/freelancer/src/Extension.php') === '<?php // new' && is_file($packageRoot . '/extensions/freelancer/migrations/002_more.sql')
    && $packages->find('extension', 'freelancer')['repo'] === 'acme/modulento-ext-freelancer');
check('package: the folder that was there is kept as a backup', array_map('file_get_contents', glob($packageRoot . '/work/backups/packages/extension-freelancer-*/src/Extension.php')) === ['<?php // old']);
check('package: a taken over extension stays enabled, with its new version', $pdo->query("SELECT enabled, version FROM extension WHERE id = 'freelancer'")->fetch() == ['enabled' => 1, 'version' => '0.2.0']
    && (new Modulento\Core\Extension\ExtensionManager($pdo, $packageRoot . '/extensions'))->discover()['freelancer']->version === '0.2.0');
$packages->remove('extension', 'freelancer');
$pdo->exec("UPDATE extension SET enabled = 0, version = '0.1.0' WHERE id = 'freelancer'");
check('package: install refuses a repository that is not allowed, before asking it anything', $packageError(fn () => $packages->install('evil/modulento-theme')) === 'core.package.error.source');
$packages->remove('theme', 'ocean');
check('package: removed from disk and from the list', !is_dir($packageRoot . '/themes/ocean') && $packages->find('theme', 'ocean') === null && count($packages->installed()) === 1);

check('package administration needs its permission', $get('/admin/packages', 1)['status'] === 403);
$r = $get('/admin/packages', 3);
check('package administration lists packages and the allowed sources', $r['status'] === 200 && str_contains($r['body'], 'acme/modulento-shop') && str_contains($r['body'], 'acme/*, other/exact'));
check('package administration: every package can be updated from its row; a theme that is no package can be taken over', str_contains($r['body'], '/admin/packages/extension/shop/update')
    && str_contains($r['body'], 'Als Paket übernehmen') && str_contains($r['body'], '/modulento-theme-sample"'));
check('package administration: extensions that moved out of the core can be taken over', str_contains($r['body'], '/modulento-ext-freelancer"') && str_contains($r['body'], '/modulento-ext-auction"') && !str_contains($r['body'], '/modulento-ext-example"'));
check('package administration: no official packages without knowing whose they are', !str_contains($r['body'], 'Offizielle Pakete'));
$r = request($pdo, ['update' => ['repo' => 'acme/modulento']] + $config, 'GET', '/admin/packages', 3);
check('package administration offers the official packages that are not here yet', str_contains($r['body'], 'Offizielle Pakete') && str_contains($r['body'], 'name="repo" value="acme/modulento-theme-indigo"')
    && !str_contains($r['body'], 'type="hidden" name="repo" value="acme/modulento-ext-'));
$r = request($pdo, ['update' => ['repo' => 'elsewhere/modulento']] + $config, 'GET', '/admin/packages', 3);
check('package administration: official packages only from an allowed source', $r['status'] === 200 && !str_contains($r['body'], 'Offizielle Pakete'));
$post('/admin/packages/install', ['repo' => 'evil/thing'], 3);
check('package administration refuses a source that is not allowed', str_contains($_SESSION['_flash']['error'] ?? '', 'evil/thing'));
$pdo->exec("INSERT INTO package VALUES ('theme', 'sample', 'acme/modulento-theme-sample', '1.0.0', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO setting VALUES ('core.theme', 'sample')");
$post('/admin/packages/theme/sample/remove', [], 3);
check('package administration does not remove the active theme', $pdo->query("SELECT COUNT(*) FROM package WHERE id = 'sample'")->fetchColumn() == 1 && is_dir($testThemes . '/sample'));
$pdo->exec("DELETE FROM setting WHERE name = 'core.theme'");
$pdo->exec('DELETE FROM package');

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
