<?php

declare(strict_types=1);

// Plain-PHP checks for everything that needs no database:
//   php tests/run.php
// Exit code 0 means every check passed.

use Modulento\Core\Extension\ExtensionManager;
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

// --- The visitor's address behind a proxy -----------------------------------
$from = fn (string $remote, ?string $forwarded, array $trusted) => Modulento\Core\Support\ClientIp::resolve(['REMOTE_ADDR' => $remote] + ($forwarded !== null ? ['HTTP_X_FORWARDED_FOR' => $forwarded] : []), $trusted);
check('client address: the header is ignored without trusted proxies', $from('198.51.100.7', '203.0.113.5', []) === '198.51.100.7');
check('client address: the header is ignored from an address that is no proxy', $from('198.51.100.7', '203.0.113.5', ['10.0.0.0/8']) === '198.51.100.7');
check('client address: a trusted proxy names the visitor', $from('10.1.2.3', '203.0.113.5', ['10.0.0.0/8']) === '203.0.113.5');
check('client address: what the visitor claims further left does not count', $from('10.1.2.3', '1.2.3.4, 203.0.113.5, 10.9.9.9', ['10.0.0.0/8']) === '203.0.113.5');
check('client address: nonsense in the header falls back to the connection', $from('10.1.2.3', 'not-an-address', ['10.0.0.0/8']) === '10.1.2.3' && $from('10.1.2.3', '10.2.2.2', ['10.0.0.0/8']) === '10.1.2.3');
check('client address: networks are matched bit by bit, also IPv6', Modulento\Core\Support\ClientIp::isTrusted('192.168.5.130', ['192.168.5.128/25']) && !Modulento\Core\Support\ClientIp::isTrusted('192.168.5.127', ['192.168.5.128/25'])
    && Modulento\Core\Support\ClientIp::isTrusted('2001:db8:1::5', ['2001:db8::/32']) && !Modulento\Core\Support\ClientIp::isTrusted('2001:db9::1', ['2001:db8::/32']) && !Modulento\Core\Support\ClientIp::isTrusted('10.0.0.1', ['2001:db8::/32', 'junk']));
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:aaaa:bbbb:cccc:dddd';
check('client address: an IPv6 visitor is counted by network', Modulento\Core\Support\ClientIp::key() === '2001:db8:1:2::/64');
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
check('client address: an IPv4 visitor is counted by address', Modulento\Core\Support\ClientIp::key() === '203.0.113.5');
unset($_SERVER['REMOTE_ADDR']);

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
check('manifest without "type" is an app, as every extension was before the field existed', $manifest->type === 'app');
check('manifest reads an addon type', Manifest::fromDir(manifestDir('shop', str_replace('"api":1', '"api":1,"type":"addon"', $valid)))->type === 'addon');
check('manifest rejects an unknown type', throws(InvalidArgumentException::class, fn () => Manifest::fromDir(manifestDir('shop', str_replace('"api":1', '"api":1,"type":"plugin"', $valid)))));
check('manifest without "description" is empty, not an error', $manifest->description === '');
check('manifest reads a description', Manifest::fromDir(manifestDir('shop', str_replace('"api":1', '"api":1,"description":"Sells things."', $valid)))->description === 'Sells things.');

// --- ExtensionManager: which apps an "enable" switches off, apps vs. addons ---
$appA = Manifest::fromDir(manifestDir('app_a', str_replace('"shop"', '"app_a"', $valid)));
$appB = Manifest::fromDir(manifestDir('app_b', str_replace('"shop"', '"app_b"', $valid)));
$addonA = Manifest::fromDir(manifestDir('addon_a', str_replace('"shop"', '"addon_a"', str_replace('"api":1', '"api":1,"type":"addon"', $valid))));
$discovered = ['app_a' => $appA, 'app_b' => $appB, 'addon_a' => $addonA];
check('extension manager: enabling an app switches off every other enabled app, never an addon',
    ExtensionManager::appsToSwitchOff($discovered, ['app_a', 'addon_a'], 'app_b') === ['app_a']);
// enable() never calls appsToSwitchOff() for an addon at all (it returns [] on the manifest's own type first) - see ExtensionManager::enable().
check('extension manager: an addon is never asked what to switch off', $addonA->type === 'addon');
check('extension manager: an enabled addon is not among what an app enabling switches off',
    ExtensionManager::appsToSwitchOff($discovered, ['addon_a'], 'app_a') === []);
check('extension manager: an enabled id whose folder vanished is still treated as an app',
    ExtensionManager::appsToSwitchOff(['app_b' => $appB], ['gone', 'app_b'], 'app_b') === ['gone']);

// --- Router access control and extension loading -------------------------
// Runs against an in-memory SQLite database with just the tables Auth and
// ExtensionManager read. Everything that needs MariaDB itself (migrations,
// login, scheduler runs) is not covered here.
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE account (id INTEGER PRIMARY KEY, email TEXT UNIQUE, display_name TEXT, password_hash TEXT, status TEXT,
    status_note TEXT, identity_status TEXT NOT NULL DEFAULT 'none', identity_note TEXT, identity_decided_at TEXT, identity_decided_by INTEGER,
    email_verified_at TEXT, terms_accepted_at TEXT, locale TEXT, created_at TEXT, last_login_at TEXT)");
$pdo->exec("CREATE TABLE account_identity_document (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    original_name TEXT, stored_name TEXT, size INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE account_billing_profile (account_id INTEGER PRIMARY KEY REFERENCES account (id) ON DELETE CASCADE,
    company_name TEXT, website TEXT, vat_id TEXT, tax_id TEXT, street TEXT, postal_code TEXT, city TEXT, country TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE notification (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    type TEXT, message_key TEXT, params TEXT, link TEXT, read_at TEXT, created_at TEXT)");
// Created this early (not just where the rest of migration 022 is mirrored,
// further down) because OfferController now consults a plan's limits on
// every offer wizard request, not only on the subscriptions tests' own pages.
$pdo->exec("CREATE TABLE subscription_plan (id INTEGER PRIMARY KEY, slug TEXT UNIQUE, name TEXT, price_cents INTEGER, currency TEXT, period_months INTEGER, features TEXT NOT NULL DEFAULT '', offer_limits TEXT NOT NULL DEFAULT '{}', max_images_per_offer INTEGER NULL, per_seat INTEGER NOT NULL DEFAULT 0, min_quantity INTEGER NOT NULL DEFAULT 1, active INTEGER NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE subscription (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, plan_id INTEGER REFERENCES subscription_plan (id), status TEXT, period_end TEXT, provider_ref TEXT NULL, quantity INTEGER NOT NULL DEFAULT 1, stripe_item_ref TEXT NULL, reminded_for TEXT NULL, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE provider (id INTEGER PRIMARY KEY, account_id INTEGER UNIQUE REFERENCES account (id) ON DELETE CASCADE, type TEXT, status TEXT,
    status_note TEXT, name TEXT, slug TEXT UNIQUE, legal_name TEXT, street TEXT, postal_code TEXT, city TEXT, country TEXT, contact_email TEXT, phone TEXT,
    vat_id TEXT, tax_id TEXT, company_register TEXT, self_certified_at TEXT, details_changed_at TEXT, decided_at TEXT, decided_by INTEGER, created_at TEXT, updated_at TEXT,
    avg_response_minutes INTEGER, response_sample_count INTEGER NOT NULL DEFAULT 0)");
$pdo->exec("CREATE TABLE provider_translation (provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, locale TEXT, headline TEXT, description TEXT, PRIMARY KEY (provider_id, locale))");
$pdo->exec("CREATE TABLE page (id INTEGER PRIMARY KEY, status TEXT, role TEXT UNIQUE, in_header INTEGER, in_footer INTEGER, position INTEGER, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE page_translation (page_id INTEGER REFERENCES page (id) ON DELETE CASCADE, locale TEXT, title TEXT, slug TEXT,
    meta_description TEXT, body TEXT, PRIMARY KEY (page_id, locale), UNIQUE (locale, slug))");
$pdo->exec("CREATE TABLE account_identity (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    provider TEXT, subject TEXT, created_at TEXT, UNIQUE (provider, subject))");
$pdo->exec("CREATE TABLE account_token (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    purpose TEXT, token_hash TEXT UNIQUE, payload TEXT, expires_at TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE account_login_token (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    selector TEXT UNIQUE, token_hash TEXT, previous_hash TEXT, rotated_at TEXT, auth_stamp TEXT, user_agent TEXT, created_at TEXT, last_used_at TEXT, expires_at TEXT)");
$pdo->exec("CREATE TABLE rate_limit_attempt (id INTEGER PRIMARY KEY, action TEXT, identifier TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE task_run (name TEXT PRIMARY KEY, last_started_at TEXT, last_finished_at TEXT, last_status TEXT, last_error TEXT)");
$pdo->exec("CREATE TABLE x_example_login (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, logged_in_at TEXT)");
$pdo->exec("CREATE TABLE category (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES category (id), position INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE category_translation (category_id INTEGER REFERENCES category (id) ON DELETE CASCADE, locale TEXT, name TEXT, slug TEXT, PRIMARY KEY (category_id, locale), UNIQUE (locale, slug))");
$pdo->exec("CREATE TABLE offer (id INTEGER PRIMARY KEY, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, type TEXT, category_id INTEGER REFERENCES category (id) ON DELETE SET NULL,
    status TEXT, status_note TEXT, price_from INTEGER, currency TEXT, decided_at TEXT, decided_by INTEGER, published_at TEXT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE offer_translation (offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, locale TEXT, title TEXT, slug TEXT, summary TEXT, description TEXT, PRIMARY KEY (offer_id, locale), UNIQUE (locale, slug))");
$pdo->exec("CREATE TABLE offer_image (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, name TEXT, extension TEXT, width INTEGER, height INTEGER, position INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE custom_field (id INTEGER PRIMARY KEY, entity TEXT, field_key TEXT, type TEXT, required INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, type_ids TEXT, created_at TEXT, UNIQUE (entity, field_key))");
$pdo->exec("CREATE TABLE custom_field_translation (field_id INTEGER REFERENCES custom_field (id) ON DELETE CASCADE, locale TEXT, label TEXT, option_labels TEXT, PRIMARY KEY (field_id, locale))");
$pdo->exec("CREATE TABLE custom_field_offer_value (field_id INTEGER REFERENCES custom_field (id) ON DELETE CASCADE, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, locale TEXT NOT NULL DEFAULT '', value TEXT, PRIMARY KEY (field_id, offer_id, locale))");
$pdo->exec("CREATE TABLE custom_field_provider_value (field_id INTEGER REFERENCES custom_field (id) ON DELETE CASCADE, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, locale TEXT NOT NULL DEFAULT '', value TEXT, PRIMARY KEY (field_id, provider_id, locale))");
$pdo->exec("CREATE TABLE x_freelancer_package (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, tier INTEGER, price INTEGER, delivery_days INTEGER, revisions INTEGER, UNIQUE (offer_id, tier))");
$pdo->exec("CREATE TABLE x_freelancer_package_translation (package_id INTEGER REFERENCES x_freelancer_package (id) ON DELETE CASCADE, locale TEXT, name TEXT, description TEXT, PRIMARY KEY (package_id, locale))");
$pdo->exec("CREATE TABLE x_freelancer_extra (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, position INTEGER, price INTEGER, extra_days INTEGER)");
$pdo->exec("CREATE TABLE x_freelancer_extra_translation (extra_id INTEGER REFERENCES x_freelancer_extra (id) ON DELETE CASCADE, locale TEXT, title TEXT, PRIMARY KEY (extra_id, locale))");
$pdo->exec("CREATE TABLE x_freelancer_requirement (offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, locale TEXT, text TEXT, PRIMARY KEY (offer_id, locale))");
$pdo->exec("CREATE TABLE x_freelancer_profile (provider_id INTEGER PRIMARY KEY REFERENCES provider (id) ON DELETE CASCADE, hourly_rate INTEGER, availability TEXT NOT NULL DEFAULT 'available', updated_at TEXT)");
$pdo->exec("CREATE TABLE x_freelancer_profile_translation (provider_id INTEGER REFERENCES x_freelancer_profile (provider_id) ON DELETE CASCADE, locale TEXT, bio TEXT, PRIMARY KEY (provider_id, locale))");
$pdo->exec("CREATE TABLE x_freelancer_skill (id INTEGER PRIMARY KEY, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, name TEXT, position INTEGER, UNIQUE (provider_id, name))");
$pdo->exec("CREATE TABLE x_freelancer_portfolio_item (id INTEGER PRIMARY KEY, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, position INTEGER, image_name TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE x_freelancer_portfolio_translation (item_id INTEGER REFERENCES x_freelancer_portfolio_item (id) ON DELETE CASCADE, locale TEXT, title TEXT, description TEXT, PRIMARY KEY (item_id, locale))");
$pdo->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY, buyer_id INTEGER REFERENCES account (id) ON DELETE SET NULL, provider_id INTEGER REFERENCES provider (id) ON DELETE SET NULL,
    offer_id INTEGER REFERENCES offer (id) ON DELETE SET NULL, flow TEXT, state TEXT, previous_state TEXT, state_actor TEXT, buyer_name TEXT, provider_name TEXT, offer_title TEXT,
    total INTEGER, currency TEXT, locale TEXT, payment_method TEXT, payment_state TEXT DEFAULT 'unpaid', paid_at TEXT, data TEXT, due_at TEXT, due_transition TEXT,
    terms_accepted_at TEXT, created_at TEXT, updated_at TEXT, closed_at TEXT)");
$pdo->exec("CREATE TABLE order_item (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, position INTEGER, label TEXT, quantity INTEGER, unit_price INTEGER)");
$pdo->exec("CREATE TABLE order_event (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, transition TEXT, from_state TEXT, to_state TEXT,
    actor_id INTEGER REFERENCES account (id) ON DELETE SET NULL, actor_role TEXT, note TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE order_message (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, account_id INTEGER REFERENCES account (id) ON DELETE SET NULL,
    author_role TEXT, body TEXT, flagged_word TEXT, status TEXT NOT NULL DEFAULT 'visible', decided_at TEXT, decided_by INTEGER REFERENCES account (id) ON DELETE SET NULL, created_at TEXT)");
$pdo->exec("CREATE TABLE order_file (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, event_id INTEGER REFERENCES order_event (id) ON DELETE CASCADE,
    message_id INTEGER REFERENCES order_message (id) ON DELETE CASCADE, account_id INTEGER REFERENCES account (id) ON DELETE SET NULL, author_role TEXT, original_name TEXT, stored_name TEXT, size INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE request (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, category_id INTEGER REFERENCES category (id) ON DELETE SET NULL,
    title TEXT, slug TEXT UNIQUE, description TEXT, budget_min INTEGER, budget_max INTEGER, currency TEXT, needed_by TEXT,
    status TEXT NOT NULL DEFAULT 'pending', status_note TEXT, decided_at TEXT, decided_by INTEGER, accepted_application_id INTEGER,
    order_id INTEGER REFERENCES orders (id) ON DELETE SET NULL, published_at TEXT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE request_application (id INTEGER PRIMARY KEY, request_id INTEGER REFERENCES request (id) ON DELETE CASCADE, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE,
    price INTEGER, delivery_days INTEGER, message TEXT, status TEXT NOT NULL DEFAULT 'submitted', created_at TEXT, updated_at TEXT, UNIQUE (request_id, provider_id))");
$pdo->exec("CREATE TABLE review (id INTEGER PRIMARY KEY, order_id INTEGER UNIQUE REFERENCES orders (id) ON DELETE CASCADE, offer_id INTEGER REFERENCES offer (id) ON DELETE SET NULL,
    provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, author_id INTEGER REFERENCES account (id) ON DELETE SET NULL, author_name TEXT, rating INTEGER, body TEXT, locale TEXT,
    status TEXT DEFAULT 'published', status_note TEXT, reply TEXT, replied_at TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE withdrawal (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE SET NULL, order_number TEXT, name TEXT, email TEXT, statement TEXT,
    locale TEXT, account_id INTEGER REFERENCES account (id) ON DELETE SET NULL, matched INTEGER NOT NULL DEFAULT 0, created_at TEXT)");
$pdo->exec("CREATE TABLE report (id INTEGER PRIMARY KEY, url TEXT, category TEXT, explanation TEXT, name TEXT, email TEXT, locale TEXT,
    account_id INTEGER REFERENCES account (id) ON DELETE SET NULL, status TEXT DEFAULT 'open', decision_note TEXT, decided_at TEXT, decided_by INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE provider_payment (provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, method TEXT, data TEXT, status TEXT, updated_at TEXT, PRIMARY KEY (provider_id, method))");
$pdo->exec("CREATE TABLE order_payment (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, method TEXT, provider_reference TEXT, status TEXT,
    amount INTEGER, currency TEXT, created_at TEXT, updated_at TEXT, UNIQUE (method, provider_reference))");
$pdo->exec("ALTER TABLE report ADD COLUMN offer_id INTEGER");
$pdo->exec("ALTER TABLE report ADD COLUMN provider_id INTEGER");
$pdo->exec("CREATE TABLE account_avatar (account_id INTEGER PRIMARY KEY REFERENCES account (id) ON DELETE CASCADE, name TEXT, extension TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE offer_message (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, asker_id INTEGER REFERENCES account (id) ON DELETE CASCADE, author_id INTEGER REFERENCES account (id) ON DELETE SET NULL, body TEXT,
    flagged_word TEXT, status TEXT NOT NULL DEFAULT 'visible', decided_at TEXT, decided_by INTEGER REFERENCES account (id) ON DELETE SET NULL, created_at TEXT)");
$pdo->exec("CREATE TABLE message_seen (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, scope TEXT, ref_id INTEGER, sub_id INTEGER DEFAULT 0, seen_id INTEGER, PRIMARY KEY (account_id, scope, ref_id, sub_id))");
$pdo->exec("CREATE TABLE admin_log (id INTEGER PRIMARY KEY, created_at TEXT, actor_id INTEGER REFERENCES account (id) ON DELETE SET NULL, action TEXT, target_id INTEGER REFERENCES account (id) ON DELETE SET NULL, detail TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE media (id INTEGER PRIMARY KEY, file TEXT UNIQUE, title TEXT, width INTEGER, height INTEGER, bytes INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE account_preference (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, name TEXT, value TEXT, PRIMARY KEY (account_id, name))");
$pdo->exec("ALTER TABLE withdrawal ADD COLUMN handled_at TEXT");
$pdo->exec("ALTER TABLE withdrawal ADD COLUMN handled_by INTEGER");
$pdo->exec("ALTER TABLE offer ADD COLUMN rating_count INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE offer ADD COLUMN rating_sum INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE provider ADD COLUMN rating_count INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE provider ADD COLUMN rating_sum INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE account ADD COLUMN rating_count INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE account ADD COLUMN rating_sum INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE account ADD COLUMN is_demo INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE provider ADD COLUMN is_demo INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE offer ADD COLUMN is_demo INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE request ADD COLUMN is_demo INTEGER NOT NULL DEFAULT 0");
$pdo->exec("CREATE TABLE account_rating (id INTEGER PRIMARY KEY, rater_id INTEGER REFERENCES account (id) ON DELETE SET NULL, rater_name TEXT,
    rated_id INTEGER REFERENCES account (id) ON DELETE CASCADE, rating INTEGER, body TEXT, locale TEXT, status TEXT DEFAULT 'published',
    status_note TEXT, reply TEXT, replied_at TEXT, created_at TEXT, UNIQUE (rater_id, rated_id))");
$pdo->exec("CREATE TABLE package (kind TEXT, id TEXT, repo TEXT, version TEXT, installed_at TEXT, PRIMARY KEY (kind, id))");
$pdo->exec("CREATE TABLE license_key (product_id TEXT PRIMARY KEY, key_encrypted TEXT, added_at TEXT)");
$pdo->exec("PRAGMA foreign_keys = ON");
$pdo->exec("CREATE TABLE role (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE role_permission (role_id INTEGER REFERENCES role (id) ON DELETE CASCADE, permission TEXT)");
$pdo->exec("CREATE TABLE account_role (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, role_id INTEGER REFERENCES role (id) ON DELETE CASCADE)");
$pdo->exec("CREATE TABLE extension (id TEXT PRIMARY KEY, version TEXT, enabled INTEGER)");
$pdo->exec("CREATE TABLE setting (name TEXT PRIMARY KEY, value TEXT)");
// Mirrors what a fresh install's own installer now does: every module on
// except subscriptions, so a plain site keeps making offers without plans.
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
    $_SESSION['report_form_at'] ??= time() - 60;
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
// A request that declares it wants JSON back (fetch(), not jQuery's ajax()) must not get
// a browser redirect it cannot follow as data: see Router::isAjax().
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$r = request($pdo, $config, 'POST', '/save', 1, ['_csrf' => 'wrong']);
check('a JSON request with a bad CSRF token gets a plain failure, not a redirect', !$r['called'] && $r['status'] === 403 && $r['body'] !== '');
unset($_SERVER['HTTP_ACCEPT']);
$r = request($pdo, $config, 'POST', '/save', 1, ['_csrf' => 'wrong']);
check('the same request without declaring JSON gets the browser redirect instead', !$r['called'] && $r['status'] === 302 && $r['body'] === '');
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
$home = request($pdo, $config, 'GET', '/', null)['body'];
check('enabled extension: its entry in the main menu and its section on the home page', str_contains($home, '<a href="/example">Beispiel</a>') && str_contains($home, 'Aus der Beispiel-Erweiterung'));
check('enabled extension: its footer link too', str_contains($home, '<a href="/example">Beispiel-Footer-Link</a>'));
check('without an extension that adds offers the site shows no catalogue links', !str_contains($home, 'href="/offers"') && !str_contains($home, 'href="/providers"')
    && !str_contains(request($pdo, $config, 'GET', '/account', 1)['body'], 'Anbieter werden'));
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

// --- Registrar::registrationGuard() / registrationSection() / cookie consent -----------------
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'example'");
$r = $get('/register', null);
check('registrationSection(): the extension\'s block is rendered on the registration form', str_contains($r['body'], 'Vom Beispiel-Registrierungsblock'));
check('cookieBanner(): shown on every page, consent not granted without a provider that says so', str_contains($r['body'], 'Beispiel-Cookie-Banner') && str_contains($r['body'], '– nicht zugestimmt'));

Modulento\Example\Extension::$blockRegistration = true;
$r = $post('/register', ['email' => 'antispam-blocked@example.test', 'password' => $pw, 'password_repeat' => $pw], null);
check('registrationGuard(): the extension refuses with its own message, no account created', $row('antispam-blocked@example.test') === false && str_contains($r['body'], 'Die Registrierung wurde vom Beispiel blockiert.'));
Modulento\Example\Extension::$blockRegistration = false;
$post('/register', ['email' => 'antispam-allowed@example.test', 'password' => $pw, 'password_repeat' => $pw], null);
check('registrationGuard(): not blocked, the account is created normally', $row('antispam-allowed@example.test') !== false);
$pdo->exec("DELETE FROM account WHERE email = 'antispam-allowed@example.test'");

Modulento\Example\Extension::$consentProvider = new class implements Modulento\Core\Cookie\ConsentProvider {
    public function granted(string $category): bool { return $category === 'example.demo_cookie'; }
};
check('consentProvider(): a registered provider is asked, the banner reflects its answer', str_contains($get('/register', null)['body'], '– zugestimmt'));
Modulento\Example\Extension::$consentProvider = null;

$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'example'");
check('registrationSection(): disabled again, the block and the banner are gone', !str_contains($get('/register', null)['body'], 'Vom Beispiel-Registrierungsblock') && !str_contains($get('/register', null)['body'], 'Beispiel-Cookie-Banner'));

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

// --- Stay logged in --------------------------------------------------------
$pdo->exec('DELETE FROM rate_limit_attempt');
unset($_COOKIE['remember'], $_SERVER['HTTPS'], $_SERVER['HTTP_USER_AGENT']);
$stayPw = 'a password to stay with';
$accounts->create('stay@example.test', $stayPw, 'de', verified: true);
$stayId = (int) $row('stay@example.test')['id'];
$tokenCount = fn () => (int) $pdo->query('SELECT COUNT(*) FROM account_login_token')->fetchColumn();
$tokenRow = fn (string $cookie) => $pdo->query('SELECT * FROM account_login_token WHERE selector = ' . $pdo->quote(explode(':', $cookie)[0]))->fetch();
$secretOf = fn (string $cookie) => explode(':', $cookie)[1];
$stays = fn () => (int) ($_SESSION['account_id'] ?? 0) === $stayId;
// A browser without cookies logs in; what it holds afterwards.
$device = function (bool $remember = true, string $email = 'stay@example.test', ?string $password = null) use ($post, &$stayPw): ?string {
    unset($_COOKIE['remember']);
    $post('/login', ['email' => $email, 'password' => $password ?? $stayPw] + ($remember ? ['remember' => '1'] : []), null);

    return $_COOKIE['remember'] ?? null;
};
// A browser that was closed and opened again: the session is gone, the cookie is not.
$reopen = function (?string $cookie, string $path = '/account') use ($get): array {
    unset($_COOKIE['remember']);
    if ($cookie !== null) {
        $_COOKIE['remember'] = $cookie;
    }

    return $get($path, null);
};
$sent = fn () => Modulento\Core\Support\RememberCookie::lastSent();

check('stay logged in: the login form offers the box', str_contains($get('/login', null)['body'], 'name="remember"'));
check('stay logged in: without the box no token and no cookie', $device(false) === null && $stays() && $tokenCount() === 0);
$reopen(null);
check('stay logged in: without the cookie a closed browser is logged out', !$stays());

$_SERVER['HTTP_USER_AGENT'] = "<script>alert(1)</script>\x07 " . str_repeat('x', 400);
$first = $device();
$stored = $tokenRow((string) $first);
check('stay logged in: the box creates a token and a cookie "selector:secret"', $stays() && $tokenCount() === 1
    && preg_match('/\A[a-f0-9]{24}:[a-f0-9]{64}\z/', (string) $first) === 1 && (int) $stored['account_id'] === $stayId);
check('stay logged in: only the hash of the secret is stored', $stored['token_hash'] === hash('sha256', $secretOf($first))
    && !str_contains(implode('|', array_map('strval', $stored)), $secretOf($first)));
check('stay logged in: the cookie is HttpOnly, SameSite=Lax, for the whole site and lasts as long as the token', $sent()['value'] === $first
    && $sent()['options']['httponly'] === true && $sent()['options']['samesite'] === 'Lax' && $sent()['options']['path'] === '/'
    && $sent()['options']['secure'] === false && abs($sent()['options']['expires'] - time() - 30 * 86400) < 5
    && abs(strtotime($stored['expires_at'] . ' UTC') - time() - 30 * 86400) < 5);
check('stay logged in: the browser name is shortened and cleaned', mb_strlen($stored['user_agent']) === 255 && !str_contains($stored['user_agent'], "\x07"));
$r = $get('/account/settings');
check('stay logged in: the settings list the device, its name escaped and cut', str_contains($r['body'], '&lt;script&gt;alert(1)') && !str_contains($r['body'], '<script>alert(1)')
    && !str_contains($r['body'], str_repeat('x', 100)) && str_contains($r['body'], 'dieses Gerät') && str_contains($r['body'], '/account/sessions/revoke'));
check('stay logged in: a request with a session leaves the token alone', $_COOKIE['remember'] === $first && $tokenRow($first)['token_hash'] === $stored['token_hash']);
unset($_SERVER['HTTP_USER_AGENT']);

$reopen($first, '/assets/theme/nothing.css');
check('stay logged in: an asset request neither logs in nor rotates', !$stays() && $tokenRow($first)['token_hash'] === $stored['token_hash'] && $_COOKIE['remember'] === $first);

$r = $reopen($first);
$second = $_COOKIE['remember'] ?? '';
check('stay logged in: a closed browser is logged in again from the cookie', $stays() && $r['status'] === 200 && $r['body'] !== ''
    && $_SESSION['auth_stamp'] === Modulento\Core\Support\Auth::stamp($row('stay@example.test')['password_hash']));
check('stay logged in: the secret is replaced, the token stays the same one', $second !== $first && explode(':', $second)[0] === explode(':', $first)[0]
    && $tokenCount() === 1 && $tokenRow($second)['token_hash'] === hash('sha256', $secretOf($second)) && $sent()['value'] === $second);

$reopen($first);
check('stay logged in: the secret from before still works for a moment, for a second tab', $stays() && $tokenCount() === 1);
check('stay logged in: ... without rotating again', $tokenRow($second)['token_hash'] === hash('sha256', $secretOf($second)) && $_COOKIE['remember'] === $first);
$reopen($second);
$third = $_COOKIE['remember'];
check('stay logged in: the current secret keeps working after that', $stays() && $third !== $second);

$other = $device();
check('stay logged in: a second device has a token of its own', $tokenCount() === 2 && explode(':', $other)[0] !== explode(':', $third)[0]);
$pdo->exec("UPDATE account_login_token SET rotated_at = '2020-01-01 00:00:00' WHERE rotated_at IS NOT NULL");
$reopen($second);
check('stay logged in: a replaced secret after that moment logs nobody in and ends every token of the account', !$stays() && $tokenCount() === 0
    && !isset($_COOKIE['remember']) && $sent()['value'] === '' && $sent()['options']['expires'] < time());
$reopen($third);
check('stay logged in: ... the device holding the current secret included', !$stays());

$one = $device();
$device();
$accounts->create('bystander@example.test', $stayPw, 'de', verified: true);
$bystander = $device(true, 'bystander@example.test');
$reopen(explode(':', $one)[0] . ':' . str_repeat('0', 64));
check('stay logged in: a known selector with a wrong secret ends the tokens of that account only', !$stays() && $tokenCount() === 1 && $tokenRow($bystander) !== false);
$pdo->exec("DELETE FROM account WHERE email = 'bystander@example.test'");
check('stay logged in: tokens go with their account', $tokenCount() === 0);

foreach ([['x' => 'y'], 'nonsense', '', ':', str_repeat('a', 5000), str_repeat('a', 24) . ':' . str_repeat('b', 64), "abc:def\n", ['a' => ['b' => ['c']]]] as $i => $junk) {
    $keep = $device();
    $_COOKIE['remember'] = $junk;
    $r = $get('/open', null);
    check("stay logged in: a cookie with nonsense ({$i}) is no error, logs nobody in and is dropped", $r['called'] && $r['status'] === 200 && !$stays()
        && !isset($_COOKIE['remember']) && $tokenRow($keep) !== false);
    $pdo->exec('DELETE FROM account_login_token');
}

$cookie = $device();
$pdo->exec("UPDATE account_login_token SET expires_at = '2020-01-01 00:00:00'");
check('stay logged in: an expired token is not listed', !str_contains($get('/account/settings', $stayId)['body'], '/account/sessions/revoke'));
$reopen($cookie);
check('stay logged in: an expired token logs nobody in and is removed with its cookie', !$stays() && $tokenCount() === 0 && !isset($_COOKIE['remember']));
$device();
$valid = $device();
$pdo->exec("UPDATE account_login_token SET expires_at = '2020-01-01 00:00:00' WHERE selector <> " . $pdo->quote(explode(':', $valid)[0]));
(new Modulento\Core\Account\LoginTokens($pdo))->purgeExpired();
check('stay logged in: the cleanup removes expired tokens only', $tokenCount() === 1 && $tokenRow($valid) !== false);

$pdo->exec("UPDATE account SET status = 'blocked' WHERE id = {$stayId}");
$reopen($valid);
check('stay logged in: a blocked account is not logged in again', !$stays() && $tokenCount() === 0 && !isset($_COOKIE['remember']));
$pdo->exec("UPDATE account SET status = 'active', email_verified_at = NULL WHERE id = {$stayId}");
$pdo->exec("INSERT INTO account_login_token (account_id, selector, token_hash, auth_stamp, created_at, last_used_at, expires_at) VALUES ({$stayId}, '" . str_repeat('c', 24) . "', '"
    . hash('sha256', str_repeat('d', 64)) . "', '" . Modulento\Core\Support\Auth::stamp($row('stay@example.test')['password_hash']) . "', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2099-01-01 00:00:00')");
$reopen(str_repeat('c', 24) . ':' . str_repeat('d', 64));
check('stay logged in: nor an account whose address is not confirmed', !$stays() && $tokenCount() === 0);
$pdo->exec("UPDATE account SET email_verified_at = '2026-01-01 00:00:00' WHERE id = {$stayId}");
$device();
unset($_COOKIE['remember']);
$post('/admin/accounts/' . $stayId . '/block', ['note' => 'Testsperre'], 3);
check('stay logged in: blocking an account removes its tokens', $row('stay@example.test')['status'] === 'blocked' && $tokenCount() === 0);
$pdo->exec("UPDATE account SET status = 'active', status_note = NULL WHERE id = {$stayId}");

$away = $device();
$here = $device();
$post('/account/password', ['current_password' => 'wrong', 'password' => 'a password to stay with 2', 'password_repeat' => 'a password to stay with 2']);
check('stay logged in: a remembered device still needs the current password to change it', password_verify($stayPw, $row('stay@example.test')['password_hash']) && $tokenCount() === 2);
$post('/account/password', ['current_password' => $stayPw, 'password' => 'a password to stay with 2', 'password_repeat' => 'a password to stay with 2']);
$stayPw = 'a password to stay with 2';
$renewed = $_COOKIE['remember'] ?? '';
check('stay logged in: a password change ends every token, the device that made it gets a new one', $tokenCount() === 1 && $tokenRow($here) === false
    && $tokenRow($away) === false && $renewed !== $here && $tokenRow($renewed) !== false);
$reopen($away);
check('stay logged in: ... so another device is logged out', !$stays() && !isset($_COOKIE['remember']) && $tokenCount() === 1);
$reopen($renewed);
check('stay logged in: ... and this one stays', $stays());
$pdo->exec('UPDATE account SET password_hash = ' . $pdo->quote(password_hash('changed behind its back', PASSWORD_BCRYPT, ['cost' => 4])) . " WHERE id = {$stayId}");
$reopen($_COOKIE['remember']);
check('stay logged in: a token from before a password change is worth nothing by itself', !$stays() && $tokenCount() === 0);
$pdo->exec('UPDATE account SET password_hash = ' . $pdo->quote(password_hash($stayPw, PASSWORD_BCRYPT, ['cost' => 4])) . " WHERE id = {$stayId}");

$cookie = $device();
$post('/forgot-password', ['email' => 'stay@example.test'], null);
$post(lastMail($mailLog, 'stay@example.test')['link'], ['password' => 'a password to stay with 3', 'password_repeat' => 'a password to stay with 3'], null);
$stayPw = 'a password to stay with 3';
check('stay logged in: a password reset ends every token', password_verify($stayPw, $row('stay@example.test')['password_hash']) && $tokenCount() === 0);
$reopen($cookie);
check('stay logged in: ... and the cookie no longer logs in', !$stays());
$pdo->exec('DELETE FROM rate_limit_attempt');

$away = $device();
$here = $device();
$post('/account/email', ['email' => 'stayed@example.test', 'current_password' => $stayPw]);
$get(lastMail($mailLog, 'stayed@example.test')['link']);
check('stay logged in: a changed address ends every token, the device that confirmed it gets a new one', $row('stayed@example.test') !== false
    && $tokenCount() === 1 && $tokenRow($away) === false && $tokenRow($here) === false && $tokenRow($_COOKIE['remember']) !== false);
$pdo->exec("UPDATE account SET email = 'stay@example.test' WHERE id = {$stayId}");
$pdo->exec('DELETE FROM account_login_token');
$pdo->exec('DELETE FROM rate_limit_attempt');

$away = $device();
$here = $device();
$post('/logout', []);
check('stay logged in: logging out ends this device\'s token and cookie only', !$stays() && !isset($_COOKIE['remember']) && $sent()['value'] === ''
    && $tokenCount() === 1 && $tokenRow($here) === false && $tokenRow($away) !== false);
$reopen($here);
check('stay logged in: ... which stays logged out', !$stays() && $tokenRow($away) !== false);
$reopen($away);
$away = $_COOKIE['remember'];
check('stay logged in: ... while the other device is still remembered', $stays());

$here = $device();
$export = json_decode($get('/account/export')['body'], true);
check('export: the remembered devices, without selector and hash', count($export['remembered_devices'] ?? []) === 2
    && array_keys($export['remembered_devices'][0]) === ['created_at', 'last_used_at', 'expires_at', 'user_agent']);
$post('/account/sessions/revoke', ['_csrf' => 'wrong']);
check('log out everywhere: not without the CSRF token', $tokenCount() === 2);
unset($_COOKIE['remember']);
$post('/account/sessions/revoke', [], null);
check('log out everywhere: not for a visitor', $tokenCount() === 2);
$_COOKIE['remember'] = $here;
$r = $post('/account/sessions/revoke', [], $stayId);
check('log out everywhere: every token of the account and this device\'s cookie are gone', $tokenCount() === 0 && !isset($_COOKIE['remember'])
    && str_contains($_SESSION['_flash']['success'] ?? '', 'kein Gerät'));
$reopen($away);
check('log out everywhere: no device is logged in again', !$stays());

$_SERVER['HTTPS'] = 'on';
$here = $device();
check('stay logged in: over HTTPS the cookie is Secure', $sent()['value'] === $here && $sent()['options']['secure'] === true);
unset($_SERVER['HTTPS']);
check('stay logged in: logging in without the box forgets the device', $device(false) === null && $tokenCount() === 1);
$_COOKIE['remember'] = $here;
$post('/login', ['email' => 'stay@example.test', 'password' => $stayPw], null);
check('stay logged in: ... also when its cookie is still there', $stays() && $tokenCount() === 0 && !isset($_COOKIE['remember']));

$device(true, 'stay@example.test', 'not the password');
check('stay logged in: a wrong password creates no token', !$stays() && $tokenCount() === 0 && !isset($_COOKIE['remember']));
for ($i = 0; $i < 5; $i++) {
    $device(true, 'stay@example.test', 'guess number ' . $i);
}
check('stay logged in: the login limits hold with the box ticked', $device() === null && !$stays() && $tokenCount() === 0);
$pdo->exec('DELETE FROM rate_limit_attempt');

$adminCookie = $device(true, 'admin@example.test', 'correct horse battery');
$r = $reopen($adminCookie, '/admin');
check('stay logged in: an administrator is remembered like everyone else', ($_SESSION['account_id'] ?? null) == 3 && $r['status'] === 200 && $r['body'] !== '');
$post('/logout', []);
check('stay logged in: ... and logs out like everyone else', $tokenCount() === 0);

$device();
$post('/account/delete', ['current_password' => 'wrong']);
check('stay logged in: a remembered device still needs the password to delete the account', $row('stay@example.test') !== false);
$post('/account/delete', ['current_password' => $stayPw]);
check('stay logged in: deleting the account removes its tokens and the cookie', $row('stay@example.test') === false && $tokenCount() === 0 && !isset($_COOKIE['remember']));
$pdo->exec('DELETE FROM rate_limit_attempt');
unset($_COOKIE['remember']);

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

// --- Handbook --------------------------------------------------------------
$slugs = array_keys(Modulento\Core\Support\Handbook::CHAPTERS);
check('handbook: thirteen chapters, in the order they are meant to be read', count($slugs) === 13 && $slugs[0] === 'intro' && end($slugs) === 'glossary');
$r = $get('/handbook', null);
check('handbook: the overview lists every chapter, in German by default', $r['status'] === 200 && substr_count($r['body'], '<a href="/handbook/') === 13 && str_contains($r['body'], 'Zahlungsarten für Bestellungen'));
$r = $get('/en/handbook', null);
check('handbook: the same overview in English', $r['status'] === 200 && str_contains($r['body'], '<h1>Handbook</h1>') && str_contains($r['body'], 'Payment methods for orders'));
$r = $get('/handbook/intro', null);
check('handbook: the first chapter has no "previous", only a "next"', $r['status'] === 200 && str_contains($r['body'], 'Dieses Handbuch richtet sich an den Betreiber') && !str_contains($r['body'], '&larr;') && str_contains($r['body'], 'href="/handbook/installation"'));
$r = $get('/en/handbook/intro', null);
check('handbook: the same chapter in English', $r['status'] === 200 && str_contains($r['body'], 'This handbook is written for the operator'));
$r = $get('/handbook/glossary', null);
check('handbook: the last chapter has a "previous", no "next"', $r['status'] === 200 && str_contains($r['body'], '&larr;') && !str_contains($r['body'], '&rarr;'));
check('handbook: an unknown chapter is a 404, not an error', $get('/handbook/nope', null)['status'] === 404);
check('handbook: not linked from the public footer any more - it is operator documentation, reachable from the admin menu, not a visitor-facing page', !str_contains($get('/', null)['body'], 'href="/handbook"'));

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
check('sanitizer: a span keeps an allowed class and a valid colour', HtmlSanitizer::clean('<p><span class="text-font-serif text-size-lg" style="color: #112233">x</span></p>') === '<p><span class="text-font-serif text-size-lg" style="color: #112233">x</span></p>');
check('sanitizer: an unknown class and any other style property are dropped, the valid parts kept', HtmlSanitizer::clean('<span class="text-font-serif evil" style="color: #112233; position: fixed">x</span>') === '<span class="text-font-serif" style="color: #112233">x</span>');
check('sanitizer: a span left with neither a known class nor a colour is unwrapped, its text stays', HtmlSanitizer::clean('<p><span class="nope" style="background: red">x</span></p>') === '<p>x</p>');
check('sanitizer: an invalid colour value is refused', HtmlSanitizer::clean('<span style="color: red">x</span>') === 'x');

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

// A form for a new page marks no status option as chosen, so a browser that
// submits it unchanged sends none at all - the exact way an operator who never
// touched the dropdown saves a page. It must land as a draft, said as such.
$post('/admin/pages/new', ['role' => 'privacy', 'in_footer' => '1', 'text' => ['de' => $text('Datenschutz')]], 3);
check('pages: a new page with no status field chosen is a draft, not silently published', $pdo->query("SELECT status FROM page WHERE role = 'privacy'")->fetchColumn() === 'draft'
    && ($_SESSION['_flash']['success'] ?? '') === 'Die Seite wurde als Entwurf gespeichert und ist noch nicht veröffentlicht.'
    && !str_contains($get('/', null)['body'], 'Datenschutz'));
$privacyId = $pdo->query("SELECT id FROM page WHERE role = 'privacy'")->fetchColumn();
$post('/admin/pages/' . $privacyId, ['status' => 'published', 'role' => 'privacy', 'in_footer' => '1', 'text' => ['de' => $text('Datenschutz')]], 3);
check('pages: published, the flash says so and the page shows in the footer', ($_SESSION['_flash']['success'] ?? '') === 'Die Seite wurde gespeichert und ist veröffentlicht.'
    && str_contains($get('/', null)['body'], '<a href="/datenschutz">Datenschutz</a>'));
$pdo->exec("DELETE FROM page WHERE role = 'privacy'");

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

// --- Identity verification -----------------------------------------------------
use Modulento\Core\Account\IdentityVerification;

// The Providers section above ends with "mueller-design" suspended; the
// checks below need its public page back.
$post('/admin/providers/' . $p['id'] . '/decide', ['decision' => 'approve'], 3);
$lastNotification = fn () => $pdo->query('SELECT * FROM notification ORDER BY id DESC LIMIT 1')->fetch();
check('notification: a provider decision notifies the provider, not the admin', $lastNotification()['account_id'] == 1 && $lastNotification()['type'] === 'provider_status'
    && $lastNotification()['message_key'] === 'core.provider.status.approved'
    && str_contains($get('/account/notifications', 1)['body'], 'Dein Profil ist freigegeben und öffentlich.'));
check('notification: saving the own provider profile (no admin decision) does not notify', (function () use ($pdo, $post, $business) {
    $before = (int) $pdo->query('SELECT COUNT(*) FROM notification')->fetchColumn();
    $post('/account/provider', $business, 1);
    return $pdo->query('SELECT COUNT(*) FROM notification')->fetchColumn() == $before;
})());

$accountRow = fn (int $id) => $pdo->query("SELECT * FROM account WHERE id = {$id}")->fetch();
$identityDoc = fn (int $accountId) => $pdo->query("SELECT * FROM account_identity_document WHERE account_id = {$accountId}")->fetch();
/** $_FILES for the "documents[]" field, from file name => content. */
$docField = function (array $contents): array {
    $field = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    foreach ($contents as $fileName => $content) {
        $tmp = tempnam(sys_get_temp_dir(), 'id');
        file_put_contents($tmp, $content);
        $field['name'][] = $fileName;
        $field['tmp_name'][] = $tmp;
        $field['error'][] = UPLOAD_ERR_OK;
        $field['size'][] = strlen($content);
    }

    return ['documents' => $field];
};
$withDocs = function (array $contents, callable $request) use ($docField) {
    $_FILES = $docField($contents);
    $result = $request();
    $_FILES = [];

    return $result;
};

check('identity: nothing submitted yet', $accountRow(1)['identity_status'] === 'none' && str_contains($get('/account/settings?tab=security', 1)['body'], 'Noch nicht eingereicht'));

$withDocs(['virus.exe' => 'MZ'], fn () => $post('/account/identity', [], 1));
check('identity: a forbidden file type is refused', $accountRow(1)['identity_status'] === 'none' && $identityDoc(1) === false);

$withDocs(array_combine(array_map(fn ($i) => "a{$i}.pdf", range(1, 4)), array_fill(0, 4, '%PDF')), fn () => $post('/account/identity', [], 1));
check('identity: more than ' . IdentityVerification::MAX_FILES . ' files at once is refused', $identityDoc(1) === false);

$withDocs(['Ausweis.pdf' => '%PDF-1.4 Ausweisdaten'], fn () => $post('/account/identity', [], 1));
$doc = $identityDoc(1);
check('identity: stored under a random name, original name kept, status pending', $doc !== false && preg_match('/^[a-f0-9]{32}$/', $doc['stored_name']) === 1
    && $doc['original_name'] === 'Ausweis.pdf' && $accountRow(1)['identity_status'] === 'pending'
    && is_file($config['app']['uploads'] . '/identity/1/' . $doc['stored_name']));

check('identity administration needs its permission', $get('/admin/accounts/1/identity/files/' . $doc['id'], 2)['status'] === 403);
check('identity document: only an administrator downloads it, content matches', $get('/admin/accounts/1/identity/files/' . $doc['id'], 3)['body'] === '%PDF-1.4 Ausweisdaten'
    && $get('/admin/accounts/1/identity/files/' . $doc['id'], 1)['status'] === 403);
check('identity document: a wrong account or id is not reachable', $get('/admin/accounts/2/identity/files/' . $doc['id'], 3)['status'] === 404
    && $get('/admin/accounts/1/identity/files/999999', 3)['status'] === 404);

$post('/admin/accounts/1/identity/decide', ['decision' => 'reject', 'note' => ''], 3);
check('identity: a rejection needs a note', $accountRow(1)['identity_status'] === 'pending');
$post('/admin/accounts/1/identity/decide', ['decision' => 'reject', 'note' => 'Bild zu unscharf'], 3);
check('identity: rejected with a note, owner is told', $accountRow(1)['identity_status'] === 'rejected' && $accountRow(1)['identity_note'] === 'Bild zu unscharf'
    && lastMail($mailLog, 'plain@example.test')['subject'] === 'Dein Identitätsnachweis wurde abgelehnt');

$withDocs(['Ausweis2.pdf' => '%PDF-1.4 neu'], fn () => $post('/account/identity', [], 1));
check('identity: resubmitting after a rejection asks for review again', $accountRow(1)['identity_status'] === 'pending' && $accountRow(1)['identity_note'] === null);

$post('/admin/accounts/1/identity/decide', ['decision' => 'verify'], 3);
check('identity: verified, owner is told', $accountRow(1)['identity_status'] === 'verified'
    && lastMail($mailLog, 'plain@example.test')['subject'] === 'Deine Identität wurde bestätigt');
check('identity: a verified account shows the blue checkmark on its public provider profile', str_contains($get('/providers/mueller-design', null)['body'], 'badge-verified'));

$identityExport = json_decode($get('/account/export', 1)['body'], true);
check('identity: export carries only the status, never the documents', ($identityExport['identity_verification']['status'] ?? '') === 'verified' && !isset($identityExport['identity_verification']['documents']));

// --- Billing profile -----------------------------------------------------------
$billing = fn (int $accountId) => $pdo->query("SELECT * FROM account_billing_profile WHERE account_id = {$accountId}")->fetch();

check('billing profile: nothing by default', $billing(2) === false);
$post('/account/billing', ['company_name' => 'Design Werk', 'website' => 'not a url', 'vat_id' => '123', 'country' => 'AT'], 2);
check('billing profile: an invalid website or VAT ID is refused', $billing(2) === false);
$post('/account/billing', ['company_name' => 'Design Werk GmbH', 'website' => 'https://design-werk.test', 'vat_id' => 'atu 1234-5678', 'tax_id' => '12/3456',
    'street' => 'Weg 2', 'postal_code' => '80331', 'city' => 'München', 'country' => 'de'], 2);
$b = $billing(2);
check('billing profile: saved, VAT ID normalised, independent from the provider profile', $b !== false && $b['company_name'] === 'Design Werk GmbH' && $b['vat_id'] === 'ATU12345678' && $b['country'] === 'DE');
$post('/account/billing', ['company_name' => 'Design Werk GmbH 2', 'vat_id' => 'ATU12345678', 'country' => 'DE'], 2);
check('billing profile: can be updated', $billing(2)['company_name'] === 'Design Werk GmbH 2');

$billingExport = json_decode($get('/account/export', 2)['body'], true);
check('billing profile: included in the export, an account without one has no section', ($billingExport['billing_profile']['company_name'] ?? '') === 'Design Werk GmbH 2' && !isset($identityExport['billing_profile']));

// --- Profile badges -------------------------------------------------------------
$settings = ['site_name' => 'Testseite', 'mail_from' => 'noreply@example.test', 'registration' => 'open', 'default_locale' => 'de', 'locales' => ['de', 'en']];
check('badge thresholds: default values shown', str_contains($get('/admin/settings?tab=catalogue', 3)['body'], 'value="4.5"') && str_contains($get('/admin/settings?tab=catalogue', 3)['body'], 'value="5"'));
$post('/admin/settings', $settings + ['badge_top_rated_min_average' => '3', 'badge_top_rated_min_count' => '2', 'badge_fast_responder_max_minutes' => '60', 'badge_fast_responder_min_sample' => '1'], 3);
check('badge thresholds: saved', $pdo->query("SELECT value FROM setting WHERE name = 'core.badge.top_rated.min_average'")->fetchColumn() === '3'
    && $pdo->query("SELECT value FROM setting WHERE name = 'core.badge.fast_responder.min_sample'")->fetchColumn() === '1');

$pdo->exec('UPDATE provider SET rating_count = 2, rating_sum = 10 WHERE account_id = 1');
check('badge: "top rated" appears once the thresholds are crossed', str_contains($get('/providers/mueller-design', null)['body'], 'Top bewertet'));
$pdo->exec('UPDATE provider SET rating_count = 2, rating_sum = 4 WHERE account_id = 1');
check('badge: disappears below the threshold', !str_contains($get('/providers/mueller-design', null)['body'], 'Top bewertet'));

// A synthetic offer-question thread: the provider answers 45 minutes after being asked.
$providerId = (int) $pdo->query('SELECT id FROM provider WHERE account_id = 1')->fetchColumn();
$pdo->exec("INSERT INTO offer (provider_id, type, status, currency, created_at, updated_at) VALUES ({$providerId}, 'freelancer.service', 'published', 'EUR', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$badgeOfferId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO offer_message (offer_id, asker_id, author_id, body, created_at) VALUES ({$badgeOfferId}, 2, 2, 'Frage', '2026-01-02 10:00:00')");
$pdo->exec("INSERT INTO offer_message (offer_id, asker_id, author_id, body, created_at) VALUES ({$badgeOfferId}, 2, 1, 'Antwort', '2026-01-02 10:45:00')");
$testApp = new Modulento\Core\App($config, $pdo);
$testApp->badges->recomputeResponseTimes($testApp);
check('badges: recomputeResponseTimes() reads the gap between a question and the provider\'s first reply', $pdo->query("SELECT avg_response_minutes, response_sample_count FROM provider WHERE id = {$providerId}")->fetch() == ['avg_response_minutes' => 45, 'response_sample_count' => 1]);
check('badge: "fast responder" appears once the figure is computed and within the threshold', str_contains($get('/providers/mueller-design', null)['body'], 'Schnelle Antwortzeit'));
$pdo->exec("DELETE FROM offer WHERE id = {$badgeOfferId}");
$pdo->exec("UPDATE provider SET avg_response_minutes = NULL, response_sample_count = 0, rating_count = 0, rating_sum = 0 WHERE id = {$providerId}");
$post('/admin/settings', $settings + ['badge_top_rated_min_average' => '4.5', 'badge_top_rated_min_count' => '5', 'badge_fast_responder_max_minutes' => '120', 'badge_fast_responder_min_sample' => '5'], 3);

// --- Settings: Google client id/secret, and the maintenance toggle ---------------
check('settings: the login tab has no secret yet', !str_contains($get('/admin/settings?tab=login', 3)['body'], 'Gespeichert, endet auf'));
$post('/admin/settings', $settings + ['google_client_id' => 'abc.apps.googleusercontent.com', 'google_client_secret' => 'super-secret-value'], 3);
check('settings: the id is stored in the open, the secret only encrypted', $pdo->query("SELECT value FROM setting WHERE name = 'core.social_login.google_client_id'")->fetchColumn() === 'abc.apps.googleusercontent.com'
    && !str_contains((string) $pdo->query("SELECT value FROM setting WHERE name = 'core.social_login.google_client_secret'")->fetchColumn(), 'super-secret-value'));
check('settings: the form shows a hint of the secret, never the value itself', str_contains($get('/admin/settings?tab=login', 3)['body'], 'Gespeichert, endet auf „alue“')
    && !str_contains($get('/admin/settings?tab=login', 3)['body'], 'super-secret-value'));
$post('/admin/settings', $settings + ['google_client_id' => 'abc.apps.googleusercontent.com'], 3);
check('settings: an empty secret field on save keeps the one already stored, so the id can be edited alone', str_contains($get('/admin/settings?tab=login', 3)['body'], 'Gespeichert, endet auf „alue“'));
$post('/admin/settings', $settings + ['google_client_id' => ''], 3);
check('settings: clearing the client id clears the secret with it', $pdo->query("SELECT value FROM setting WHERE name = 'core.social_login.google_client_id'")->fetchColumn() === ''
    && $pdo->query("SELECT value FROM setting WHERE name = 'core.social_login.google_client_secret'")->fetchColumn() === '');

check('settings: maintenance mode is off by default, the message field is empty', !str_contains($get('/admin/settings?tab=maintenance', 3)['body'], 'name="maintenance_enabled" value="1" checked')
    && str_contains($get('/admin/settings?tab=maintenance', 3)['body'], '<textarea id="maintenance_message" name="maintenance_message" rows="3" maxlength="2000"></textarea>'));
$post('/admin/settings', $settings + ['maintenance_enabled' => '1', 'maintenance_message' => 'Gleich geht es weiter.'], 3);
check('settings: maintenance mode saved, on with its message', $pdo->query("SELECT value FROM setting WHERE name = 'core.maintenance_enabled'")->fetchColumn() === '1'
    && $pdo->query("SELECT value FROM setting WHERE name = 'core.maintenance_message'")->fetchColumn() === 'Gleich geht es weiter.');

// --- Maintenance mode: everyone but an administrator sees the maintenance page -----
check('maintenance: a visitor sees the maintenance page, not the site', $get('/', null)['status'] === 503 && str_contains($get('/', null)['body'], 'Gleich geht es weiter.'));
check('maintenance: a logged-in account without admin access sees it too', $get('/account', 1)['status'] === 503);
check('maintenance: an administrator keeps using the site and the administration as usual', $get('/', 3)['status'] === 200 && $get('/admin', 3)['status'] === 200);
check('maintenance: logging in is still possible, for everyone', $get('/login', null)['status'] === 200);
preg_match('#href="(/design/[a-f0-9]+\.css)"#', $get('/', 3)['body'], $cssMatch);
check('maintenance: the design stylesheet an administrator\'s page needs still loads for a visitor too', $get($cssMatch[1], null)['status'] === 200);
$post('/admin/settings', $settings, 3);
check('maintenance: switching it off restores the site for everyone', $get('/', null)['status'] === 200 && $get('/', 1)['status'] === 200);

// --- The two generic extension hooks: a block on the provider page, a link in the account nav ---
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'example'");
check('providerSection(): the extension\'s block is rendered on the public provider page', str_contains($get('/providers/mueller-design', null)['body'], 'Vom Beispiel-Profilblock') && str_contains($get('/providers/mueller-design', null)['body'], 'Müller Design'));
check('accountLink(): the extension\'s link is in the account navigation', str_contains($get('/account/settings', 1)['body'], 'Beispiel-Konto-Link'));
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'example'");
check('disabled again: neither shows up', !str_contains($get('/providers/mueller-design', null)['body'], 'Vom Beispiel-Profilblock') && !str_contains($get('/account/settings', 1)['body'], 'Beispiel-Konto-Link'));

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
// The offer is made in steps: languages, category, texts, description, the type's own fields, review.
// The session keeps the answers from one step to the next (false: same session as before).
$wizardSteps = function (string $type, array $answers) use ($get, $post, $pdo): array {
    $category = (int) $pdo->query('SELECT id FROM category ORDER BY id LIMIT 1')->fetchColumn();
    $get('/account/offers/new?type=' . $type, 1);
    $post('/account/offers/wizard', ['type' => $type, 'wizard_step' => 1, 'locales' => ['en']], false);
    $post('/account/offers/wizard', ['type' => $type, 'wizard_step' => 2, 'category_id' => $category], false);
    $post('/account/offers/wizard', ['type' => $type, 'wizard_step' => 3, 'text' => ['de' => ['title' => 'Assistent-Titel', 'summary' => 'Kurz'], 'en' => ['title' => 'Wizard title', 'summary' => '']]], false);
    $post('/account/offers/wizard', ['type' => $type, 'wizard_step' => 4, 'text' => ['de' => ['description' => 'Beschreibung'], 'en' => ['description' => 'Description']]], false);
    $post('/account/offers/wizard', ['type' => $type, 'wizard_step' => 5] + $answers, false);
    $post('/account/offers/wizard', ['type' => $type, 'wizard_step' => 6], false);
    return $get('/account/offers/new?type=' . $type . '&step=7', false);
};
$r = $get('/account/offers/new?type=freelancer.service', 1);
check('offer wizard: the first step asks for the languages, and the type\'s fields come at step five', $r['status'] === 200 && str_contains($r['body'], 'name="locales[]"') && str_contains($r['body'], 'Schritt 1 von 7'));
$r = $get('/account/offers/new?type=freelancer.service&step=5', false);
check('offer wizard: the type\'s own fields are there at step five', $r['status'] === 200 && str_contains($r['body'], 'name="package[1][price]"') && str_contains($r['body'], 'Paket „Basis“'));
check('offer wizard: step five only asks for the languages chosen at the start, not every site language', str_contains($r['body'], 'name="package[1][text][de][name]"') && !str_contains($r['body'], 'name="package[1][text][en][name]"'));
$review = $wizardSteps('freelancer.service', ['package' => [1 => ['price' => '20', 'delivery_days' => '3']]]);
check('offer wizard: the review shows what was entered, and its hidden fields carry the answers to the save', $review['status'] === 200 && str_contains($review['body'], 'Assistent-Titel') && str_contains($review['body'], 'name="text[de][title]"') && str_contains($review['body'], 'name="package[1][price]" value="20"'));
$r = $get('/account/offers/new?type=freelancer.service&step=3', false);
check('offer wizard: a language chosen at the start gets its own fields', str_contains($r['body'], 'name="text[en][title]"') && !str_contains($r['body'], 'name="text[fr]'));
// Both posted outside the wizard (a fresh login resets its session), so
// neither finds a draft to update - the count must stay exactly as it was,
// whatever rows the wizard checks above already left behind.
$offerCount = fn () => (int) $pdo->query('SELECT COUNT(*) FROM offer')->fetchColumn();
$countBefore = $offerCount();
$r = $post('/account/offers/new', ['package' => [1 => ['price' => 'viel', 'delivery_days' => '0']]] + $offerForm, 1);
check('offer: the type\'s validation refuses, typed values stay', $offerCount() === $countBefore && str_contains($r['body'], 'Preis zwischen') && str_contains($r['body'], 'value="Ich gestalte dein Logo"') && str_contains($r['body'], 'value="viel"'));
$countBefore = $offerCount();
$r = $post('/account/offers/new', ['text' => ['de' => ['title' => '', 'summary' => 'x', 'description' => '']]] + $offerForm, 1);
check('offer: needs a title', $offerCount() === $countBefore);

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
$get('/account/offers/new?type=freelancer.service', 1);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 1, 'locales' => []], false);
check('offer wizard: the quota is already checked at step one, not just at the final save', $pdo->query('SELECT COUNT(*) FROM offer')->fetchColumn() == 3 && str_contains($_SESSION['_flash']['error'] ?? '', 'Höchstzahl von 3'));
$pdo->exec("UPDATE provider SET status = 'approved' WHERE account_id = 1");
$pdo->exec("DELETE FROM offer WHERE id <> {$offerId}");
check('offer: a draft is not public', $get('/offers/ich-gestalte-dein-logo', null)['status'] === 404 && !str_contains($get('/offers', null)['body'], 'Ich gestalte'));
check('offer: someone else cannot open or change it', $get('/account/offers/' . $offerId, 2)['status'] === 404 && $post('/account/offers/' . $offerId . '/delete', [], 2)['status'] === 404 && $offerRow() !== false);

// --- Custom fields: the operator's own fields on offers and the provider profile, no extension needed ---
check('custom fields need their permission', $post('/admin/custom-fields/new', ['entity' => 'offer', 'type' => 'text', 'text' => ['de' => ['label' => 'x']]], 1)['status'] === 403);

$post('/admin/custom-fields/new', ['entity' => 'offer', 'type' => 'select', 'required' => '1',
    'text' => ['de' => ['label' => 'Liefergebiet', 'options' => ['Berlin', 'Hamburg']], 'en' => ['label' => 'Delivery area', 'options' => ['Berlin', 'Hamburg']]],
    'type_ids' => ['freelancer.service']], 3);
$deliveryFieldId = (int) $pdo->query("SELECT id FROM custom_field WHERE field_key = 'liefergebiet'")->fetchColumn();
check('custom fields: a select field is created with its options and its type restriction', $deliveryFieldId > 0
    && $pdo->query("SELECT type FROM custom_field WHERE id = {$deliveryFieldId}")->fetchColumn() === 'select'
    && json_decode($pdo->query("SELECT type_ids FROM custom_field WHERE id = {$deliveryFieldId}")->fetchColumn(), true) === ['freelancer.service']
    && json_decode($pdo->query("SELECT option_labels FROM custom_field_translation WHERE field_id = {$deliveryFieldId} AND locale = 'de'")->fetchColumn(), true) === ['berlin' => 'Berlin', 'hamburg' => 'Hamburg']);

$post('/admin/custom-fields/new', ['entity' => 'offer', 'type' => 'checkbox', 'text' => ['de' => ['label' => 'Express'], 'en' => ['label' => 'Express']]], 3);
$expressFieldId = (int) $pdo->query("SELECT id FROM custom_field WHERE field_key = 'express'")->fetchColumn();
check('custom fields: a field with no type restriction applies to every type', $expressFieldId > 0 && json_decode($pdo->query("SELECT type_ids FROM custom_field WHERE id = {$expressFieldId}")->fetchColumn(), true) === []);

$post('/admin/custom-fields/new', ['entity' => 'provider', 'type' => 'text', 'required' => '1', 'text' => ['de' => ['label' => 'Slogan'], 'en' => ['label' => 'Slogan']]], 3);
$sloganFieldId = (int) $pdo->query("SELECT id FROM custom_field WHERE field_key = 'slogan'")->fetchColumn();
check('custom fields: a provider field lives separately from the offer fields', $sloganFieldId > 0 && $pdo->query("SELECT entity FROM custom_field WHERE id = {$sloganFieldId}")->fetchColumn() === 'provider');

check('custom fields: a field needs a label in the default language', str_contains($post('/admin/custom-fields/new', ['entity' => 'offer', 'type' => 'text', 'text' => ['de' => ['label' => '']]], 3)['body'], 'braucht in der Standardsprache eine Beschriftung'));

check('custom fields: the offer tab lists both offer fields', str_contains($get('/admin/custom-fields?entity=offer', 3)['body'], 'Liefergebiet') && str_contains($get('/admin/custom-fields?entity=offer', 3)['body'], 'Express'));
check('custom fields: the provider tab shows only the provider field', str_contains($get('/admin/custom-fields?entity=provider', 3)['body'], 'Slogan') && !str_contains($get('/admin/custom-fields?entity=provider', 3)['body'], 'Liefergebiet'));

$cfApp = new Modulento\Core\App($config, $pdo);
$cfApp->translator->load($config['app']['root'] . '/core/lang', 'core');
$cfApp->translator->setLocale('de');
$offerDefs = $cfApp->customFields->definitions('offer');
check('custom fields: applicable() keeps a type-restricted field only for its own type', !in_array($deliveryFieldId, array_column($cfApp->customFields->applicable($offerDefs, 'shop.product'), 'id'), true)
    && in_array($deliveryFieldId, array_column($cfApp->customFields->applicable($offerDefs, 'freelancer.service'), 'id'), true));

// A fresh account/provider/offer, kept isolated from the fixtures used everywhere else in this
// suite - "Liefergebiet" is required on freelancer.service from here on, and every other save
// of such an offer in this file must stay unaffected by that.
$pdo->exec("INSERT INTO account (email, password_hash, status, created_at, email_verified_at, terms_accepted_at) VALUES ('cf-test@example.test', 'x', 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$cfAccountId = (int) $pdo->lastInsertId();
$cfProviderForm = ['type' => 'private', 'name' => 'CF Test', 'street' => 'Teststr. 1', 'postal_code' => '12345', 'city' => 'Teststadt', 'country' => 'DE'];
check('custom fields: a required provider field is enforced, even on first creation', str_contains($post('/account/provider', $cfProviderForm, $cfAccountId)['body'], 'ist ein Pflichtfeld') && $pdo->query("SELECT COUNT(*) FROM provider WHERE account_id = {$cfAccountId}")->fetchColumn() == 0);
$post('/account/provider', $cfProviderForm + ['custom_fields' => ['slogan' => ['de' => 'Toller Service']]], $cfAccountId);
$cfProviderId = (int) $pdo->query("SELECT id FROM provider WHERE account_id = {$cfAccountId}")->fetchColumn();
$pdo->exec("UPDATE provider SET status = 'approved' WHERE id = {$cfProviderId}");
check('custom fields: a valid provider save stores the value, filled in only the default language', $cfProviderId > 0 && $pdo->query("SELECT value FROM custom_field_provider_value WHERE field_id = {$sloganFieldId} AND provider_id = {$cfProviderId} AND locale = 'de'")->fetchColumn() === 'Toller Service');
check('custom fields: the stored text value is preselected when editing the profile again', str_contains($get('/account/provider', $cfAccountId)['body'], 'value="Toller Service"'));

$cfOfferForm = ['type' => 'freelancer.service', 'category_id' => $childId, 'text' => ['de' => ['title' => 'CF Testangebot', 'summary' => 's', 'description' => 'd']], 'package' => [1 => ['price' => '10', 'delivery_days' => '1']]];
check('custom fields: a required select is enforced on a new offer', str_contains($post('/account/offers/new', $cfOfferForm, $cfAccountId)['body'], 'ist ein Pflichtfeld') && (int) $pdo->query("SELECT COUNT(*) FROM offer WHERE provider_id = {$cfProviderId}")->fetchColumn() === 0);
$post('/account/offers/new', $cfOfferForm + ['custom_fields' => ['liefergebiet' => 'hamburg', 'express' => '1']], $cfAccountId);
$cfOfferId = (int) $pdo->query("SELECT id FROM offer WHERE provider_id = {$cfProviderId} ORDER BY id DESC LIMIT 1")->fetchColumn();
check('custom fields: a valid offer save stores a select value and a checked checkbox', $pdo->query("SELECT value FROM custom_field_offer_value WHERE field_id = {$deliveryFieldId} AND offer_id = {$cfOfferId} AND locale = ''")->fetchColumn() === 'hamburg'
    && $pdo->query("SELECT value FROM custom_field_offer_value WHERE field_id = {$expressFieldId} AND offer_id = {$cfOfferId} AND locale = ''")->fetchColumn() === '1');
$r = $get('/account/offers/' . $cfOfferId, $cfAccountId);
check('custom fields: the stored select value and checkbox are preselected when editing the offer again', str_contains($r['body'], 'value="hamburg" selected') && str_contains($r['body'], 'checked'));

$shown = $cfApp->customFields->display('offer', $cfOfferId, 'freelancer.service', 'de');
check('custom fields: display() shows the translated option label and a checked checkbox', in_array(['label' => 'Liefergebiet', 'value' => 'Hamburg'], $shown, true) && in_array(['label' => 'Express', 'value' => true], $shown, true));
$pdo->exec("UPDATE custom_field_offer_value SET value = '0' WHERE field_id = {$expressFieldId} AND offer_id = {$cfOfferId}");
check('custom fields: display() leaves out an unchecked checkbox entirely', array_column($cfApp->customFields->display('offer', $cfOfferId, 'freelancer.service', 'de'), 'label') === ['Liefergebiet']);

// Validation details that do not need a real save: a type without "Liefergebiet" restricted to
// it, so only the fields meant for this check are in play.
$trans = fn (string $key, array $params = []) => $cfApp->translator->trans($key, $params);
$invalid = fn (array $errors, string $needle) => array_filter($errors, fn (string $e) => str_contains($e, $needle)) !== [];
check('custom fields: an unknown select option is rejected', $invalid($cfApp->customFields->validate('offer', ['liefergebiet' => 'muenchen'], 'freelancer.service', ['de', 'en'], $trans)['errors'], 'ungültigen Wert'));
check('custom fields: a required text field checks only the default language', $invalid($cfApp->customFields->validate('provider', ['slogan' => ['en' => 'Great service']], null, ['de', 'en'], $trans)['errors'], 'Pflichtfeld'));
$filled = $cfApp->customFields->validate('provider', ['slogan' => ['de' => 'Toller Service']], null, ['de', 'en'], $trans);
check('custom fields: filled in the default language is enough, the other language stays unset', $filled['errors'] === [] && ($filled['values'][$sloganFieldId]['de'] ?? null) === 'Toller Service' && !array_key_exists('en', $filled['values'][$sloganFieldId]));

check('custom fields: values exist before the cascade check', (int) $pdo->query("SELECT COUNT(*) FROM custom_field_offer_value WHERE offer_id = {$cfOfferId}")->fetchColumn() > 0
    && (int) $pdo->query("SELECT COUNT(*) FROM custom_field_provider_value WHERE provider_id = {$cfProviderId}")->fetchColumn() > 0);
$pdo->exec("DELETE FROM account WHERE id = {$cfAccountId}");
check('custom fields: deleting the account cascades through the provider and the offer to their custom field values', (int) $pdo->query("SELECT COUNT(*) FROM custom_field_offer_value WHERE offer_id = {$cfOfferId}")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM custom_field_provider_value WHERE provider_id = {$cfProviderId}")->fetchColumn() === 0);

$post('/admin/custom-fields/' . $expressFieldId . '/delete', [], 3);
check('custom fields: the admin delete route removes the definition and its translations', (int) $pdo->query("SELECT COUNT(*) FROM custom_field WHERE id = {$expressFieldId}")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM custom_field_translation WHERE field_id = {$expressFieldId}")->fetchColumn() === 0);

// None of the remaining fixtures in this file expect a custom field, least of all a required
// one on freelancer.service - clear the rest so later offer/provider saves are unaffected.
$pdo->exec('DELETE FROM custom_field');

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

// The wizard's own picture step: the row exists from step one onward - an
// upload there goes through the same real route the edit page uses, lands
// back on the same step, and the final save updates this very row.
$get('/account/offers/new?type=freelancer.service', 1);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 1, 'locales' => []], false);
$draftId = (int) $offerRow()['id'];
check('offer wizard: a draft row exists from step one onward, with no title yet', $pdo->query("SELECT status FROM offer WHERE id = {$draftId}")->fetchColumn() === 'draft'
    && $pdo->query("SELECT COUNT(*) FROM offer_translation WHERE offer_id = {$draftId}")->fetchColumn() == 0);
$_FILES = ['image' => ['tmp_name' => $makeImage(600, 400), 'error' => UPLOAD_ERR_OK, 'size' => 1000, 'name' => 'x.png', 'type' => 'image/png']];
$post('/account/offers/' . $draftId . '/images', ['return' => '/account/offers/new?type=freelancer.service&step=6'], false);
$_FILES = [];
check('offer wizard: an image uploaded mid-wizard shows up back on the same step', $pdo->query("SELECT COUNT(*) FROM offer_image WHERE offer_id = {$draftId}")->fetchColumn() == 1
    && str_contains($get('/account/offers/new?type=freelancer.service&step=6', false)['body'], '/account/offers/' . $draftId . '/images/'));
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 2, 'category_id' => $childId], false);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 3, 'text' => ['de' => ['title' => 'Mit Bild', 'summary' => '']]], false);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 4, 'text' => ['de' => ['description' => 'x']]], false);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 5, 'package' => [1 => ['price' => '10', 'delivery_days' => '1']]], false);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 6], false);
$countBeforeFinalSave = $offerCount();
$post('/account/offers/new', ['type' => 'freelancer.service', 'category_id' => (string) $childId,
    'text' => ['de' => ['title' => 'Mit Bild', 'summary' => '']], 'package' => [1 => ['price' => '10', 'delivery_days' => '1']]], false);
check('offer wizard: the final save updates the draft from step one instead of inserting a second row', $offerCount() === $countBeforeFinalSave
    && $pdo->query("SELECT status FROM offer WHERE id = {$draftId}")->fetchColumn() === 'draft'
    && $pdo->query("SELECT title FROM offer_translation WHERE offer_id = {$draftId} AND locale = 'de'")->fetchColumn() === 'Mit Bild'
    && $pdo->query("SELECT COUNT(*) FROM offer_image WHERE offer_id = {$draftId}")->fetchColumn() == 1);

// A draft a wizard never finished - no title was ever saved for it - is
// cleaned up once it is old enough; one that was actually completed (even
// if left as a draft on purpose) is not touched, whatever its age.
$app = new Modulento\Core\App($config, $pdo);
$abandonedId = $app->offers->createDraft($providerId, 'freelancer.service');
$pdo->exec("UPDATE offer SET created_at = '2020-01-01 00:00:00' WHERE id = {$abandonedId}");
$pdo->exec("UPDATE offer SET created_at = '2020-01-01 00:00:00' WHERE id = {$draftId}");
check('offer wizard: an abandoned draft is found for cleanup, a completed one is not', $app->offers->abandonedDraftIds(7 * 86400) === [$abandonedId]);
$app->offerImages->deleteAll($abandonedId);
$app->offers->delete($abandonedId);
check('offer wizard: cleanup removes the row and its pictures', $pdo->query("SELECT COUNT(*) FROM offer WHERE id = {$abandonedId}")->fetchColumn() == 0
    && $pdo->query("SELECT COUNT(*) FROM offer WHERE id = {$draftId}")->fetchColumn() == 1);
// Removes the files too, not just the row - a raw SQL delete would leave
// the uploaded picture behind for a later offer to collide with.
$app->offerImages->deleteAll($draftId);
$app->offers->delete($draftId);

$_FILES = ['avatar' => ['tmp_name' => $makeImage(300, 120), 'error' => UPLOAD_ERR_OK]];
$post('/account/avatar', [], 2);
$avatar = $pdo->query('SELECT * FROM account_avatar WHERE account_id = 2')->fetch();
$avatarFile = $avatar ? $config['app']['uploads'] . '/avatars/' . $avatar['name'] . '.' . $avatar['extension'] : '';
check('avatar: stored re-encoded as a square under a random name', $avatar !== false && is_file($avatarFile) && getimagesize($avatarFile)[0] === 256 && getimagesize($avatarFile)[1] === 256 && preg_match('/^[a-f0-9]{32}$/', $avatar['name']) === 1);
check('avatar: shown in the overview and in the settings', str_contains($get('/account', 2)['body'], 'src="/media/avatars/' . $avatar['name']) && str_contains($get('/account/settings', 2)['body'], 'src="/media/avatars/' . $avatar['name']));
$app = new Modulento\Core\App($config, $pdo);
check('avatar: only files the upload created are served', $app->avatars->path($avatar['name'] . '.' . $avatar['extension']) === $avatarFile && $app->avatars->path('../offers/x.webp') === null && $app->avatars->path($avatar['name'] . '.php') === null);
file_put_contents($config['app']['uploads'] . '/not-a-picture.png', '<?php echo 1;');
$_FILES = ['avatar' => ['tmp_name' => $config['app']['uploads'] . '/not-a-picture.png', 'error' => UPLOAD_ERR_OK]];
$post('/account/avatar', [], 2);
check('avatar: a file that is no picture is refused, the old one stays', is_file($avatarFile) && $pdo->query('SELECT name FROM account_avatar WHERE account_id = 2')->fetchColumn() === $avatar['name']);
$_FILES = ['avatar' => ['tmp_name' => $makeImage(64, 64, 'jpeg'), 'error' => UPLOAD_ERR_OK]];
$post('/account/avatar', [], 2);
check('avatar: a new picture replaces the old file', !is_file($avatarFile) && $pdo->query('SELECT COUNT(*) FROM account_avatar')->fetchColumn() == 1);
$_FILES = [];
$post('/account/avatar/delete', [], 2);
check('avatar: removing deletes row and file', $pdo->query('SELECT COUNT(*) FROM account_avatar')->fetchColumn() == 0 && count(glob($config['app']['uploads'] . '/avatars/*')) === 0 && !str_contains($get('/account', 2)['body'], '/media/avatars/'));

// --- The freelancer extension's own profile: rate, availability, bio, skills, portfolio ---
use Modulento\Freelancer\PortfolioImages;

check('freelancer profile: without a provider, the page sends you to create one', $get('/freelancer/profile', 3)['body'] === '' && ($_SESSION['_flash']['error'] ?? '') !== '');
check('freelancer profile: the account nav carries the link', str_contains($get('/account/settings', 1)['body'], 'href="/freelancer/profile"'));
check('freelancer profile: an empty form at first', $get('/freelancer/profile', 1)['status'] === 200 && $pdo->query("SELECT COUNT(*) FROM x_freelancer_profile WHERE provider_id = {$providerId}")->fetchColumn() == 0);

$r = $post('/freelancer/profile', ['hourly_rate' => 'viel', 'availability' => 'available'], 1);
check('freelancer profile: an invalid hourly rate is refused', $pdo->query("SELECT COUNT(*) FROM x_freelancer_profile WHERE provider_id = {$providerId}")->fetchColumn() == 0);

$post('/freelancer/profile', [
    'hourly_rate' => '65,50', 'availability' => 'busy', 'skills' => "PHP\nTwig, CSS\nPHP",
    'bio' => ['de' => 'Ich baue seit zehn Jahren Websites.', 'en' => 'Building websites for ten years.'],
], 1);
$profileRow = fn () => $pdo->query("SELECT * FROM x_freelancer_profile WHERE provider_id = {$providerId}")->fetch();
check('freelancer profile: saved, rate in minor units, skills trimmed and deduplicated', $profileRow() !== false && (int) $profileRow()['hourly_rate'] === 6550 && $profileRow()['availability'] === 'busy'
    && $pdo->query("SELECT name FROM x_freelancer_skill WHERE provider_id = {$providerId} ORDER BY position")->fetchAll(PDO::FETCH_COLUMN) === ['PHP', 'Twig', 'CSS']);
check('freelancer profile: bio stored per language', $pdo->query("SELECT bio FROM x_freelancer_profile_translation WHERE provider_id = {$providerId} AND locale = 'de'")->fetchColumn() === 'Ich baue seit zehn Jahren Websites.');

$r = $get('/providers/mueller-design', null);
check('freelancer profile: shown as a block on the public provider page', str_contains($r['body'], 'Ausgelastet') && str_contains($r['body'], 'Ich baue seit zehn Jahren Websites.')
    && str_contains($r['body'], 'PHP') && str_contains($r['body'], '65,50'));

$portfolioDir = $config['app']['uploads'] . '/freelancer-portfolio';
$post('/freelancer/profile/portfolio', [], 1);
check('portfolio: a request with no file is just refused', $pdo->query('SELECT COUNT(*) FROM x_freelancer_portfolio_item')->fetchColumn() == 0);

$_FILES = ['image' => ['tmp_name' => $makeImage(1600, 800), 'error' => UPLOAD_ERR_OK]];
$post('/freelancer/profile/portfolio', ['text' => ['de' => ['title' => 'Referenzprojekt']]], 1);
$_FILES = [];
$item = $pdo->query('SELECT * FROM x_freelancer_portfolio_item')->fetch();
check('portfolio: stored re-encoded under the account\'s own folder, title kept', $item !== false && preg_match('/^[0-9a-f]{32}\.(webp|jpg)$/', $item['image_name']) === 1
    && is_file("{$portfolioDir}/1/{$item['image_name']}") && $pdo->query("SELECT title FROM x_freelancer_portfolio_translation WHERE item_id = {$item['id']} AND locale = 'de'")->fetchColumn() === 'Referenzprojekt');
$portfolioUrls = PortfolioImages::urls($item, 1);
check('portfolio: the picture is served through the extension\'s own public route', $get($portfolioUrls['thumb'], null)['status'] === 200 && strlen($get($portfolioUrls['thumb'], null)['body']) > 50);
check('portfolio: shown on the public provider page and in the account\'s own form', str_contains($get('/providers/mueller-design', null)['body'], 'Referenzprojekt') && str_contains($get('/freelancer/profile', 1)['body'], 'Referenzprojekt'));

for ($i = $pdo->query('SELECT COUNT(*) FROM x_freelancer_portfolio_item')->fetchColumn(); $i < PortfolioImages::MAX_PER_PROVIDER; $i++) {
    $pdo->exec("INSERT INTO x_freelancer_portfolio_item (provider_id, position, image_name, created_at) VALUES ({$providerId}, {$i}, '" . bin2hex(random_bytes(16)) . ".jpg', '2026-01-01 00:00:00')");
}
check('portfolio: filled up to the limit', $pdo->query('SELECT COUNT(*) FROM x_freelancer_portfolio_item')->fetchColumn() == PortfolioImages::MAX_PER_PROVIDER);
$_FILES = ['image' => ['tmp_name' => $makeImage(100, 100), 'error' => UPLOAD_ERR_OK]];
$countBefore = $pdo->query('SELECT COUNT(*) FROM x_freelancer_portfolio_item')->fetchColumn();
$post('/freelancer/profile/portfolio', [], 1);
$_FILES = [];
check('portfolio: beyond the limit, nothing more is stored', $pdo->query('SELECT COUNT(*) FROM x_freelancer_portfolio_item')->fetchColumn() == $countBefore);
$pdo->exec("DELETE FROM x_freelancer_portfolio_item WHERE id != {$item['id']}");

$post('/freelancer/profile/portfolio/' . $item['id'] . '/delete', [], 1);
check('portfolio: deleting removes the row and the files', $pdo->query('SELECT COUNT(*) FROM x_freelancer_portfolio_item')->fetchColumn() == 0 && !is_file("{$portfolioDir}/1/{$item['image_name']}"));

$_FILES = ['image' => ['tmp_name' => $makeImage(200, 200), 'error' => UPLOAD_ERR_OK]];
$post('/freelancer/profile/portfolio', [], 1);
$_FILES = [];
$lastItem = $pdo->query('SELECT * FROM x_freelancer_portfolio_item')->fetch();
(new PortfolioImages($pdo, $portfolioDir))->deleteAllForAccount(1);
check('portfolio: AccountDeleted cleanup removes every file of the account, even after its provider row is gone', !is_file("{$portfolioDir}/1/{$lastItem['image_name']}") && count(glob("{$portfolioDir}/1/*")) === 0);
$pdo->exec("DELETE FROM x_freelancer_portfolio_item WHERE id = {$lastItem['id']}");

// --- Branding: a logo and favicon of the site's own, and the default meta description ---
$brand = function (string $kind, $image, int $as = 3) use ($post) {
    $_FILES = ['file' => ['tmp_name' => $image, 'error' => UPLOAD_ERR_OK, 'size' => $image ? filesize($image) : 0, 'name' => 'x.png', 'type' => 'image/png']];
    $result = $post('/admin/branding/' . $kind, [], $as);
    $_FILES = [];

    return $result;
};
check('branding: before any upload, the header shows the name and no icon is linked', preg_match('#<a class="site-name" href="/">\\s*Testseite\\s*</a>#', $get('/')['body']) === 1
    && !str_contains($get('/')['body'], 'rel="icon"') && !str_contains($get('/')['body'], 'class="site-logo'));
check('branding: needs the themes permission', $brand('logo_light', $makeImage(200, 80), 1)['status'] === 403 && $pdo->query("SELECT COUNT(*) FROM setting WHERE name = 'core.logo_light'")->fetchColumn() == 0);
check('branding: an unknown kind changes nothing', $pdo->query("SELECT COUNT(*) FROM setting WHERE name LIKE 'core.evil%'")->fetchColumn() == 0);
$brand('evil', $makeImage(10, 10));
check('branding: a kind outside the known three is rejected', $pdo->query("SELECT COUNT(*) FROM setting WHERE name = 'core.evil'")->fetchColumn() == 0);
$fakeLogo = tempnam(sys_get_temp_dir(), 'img');
file_put_contents($fakeLogo, '<?php echo "not a picture";');
$brand('logo_light', $fakeLogo);
check('branding: a file that is no picture is refused', $pdo->query("SELECT value FROM setting WHERE name = 'core.logo_light'")->fetchColumn() === false);

$brand('logo_light', $makeImage(200, 80));
$logoLight = $pdo->query("SELECT value FROM setting WHERE name = 'core.logo_light'")->fetchColumn();
check('branding: the light logo is stored under a random name, re-encoded', $logoLight !== false && preg_match('/^[a-f0-9]{32}\.(webp|png)$/', $logoLight) === 1
    && is_file($config['app']['uploads'] . '/branding/' . $logoLight));
$r = $get('/');
check('branding: the header shows it, with the site name as alt text, and og:image follows', str_contains($r['body'], '<img class="site-logo site-logo-light" src="/media/branding/' . $logoLight . '" alt="Testseite">')
    && !str_contains($r['body'], 'site-logo-dark')
    && str_contains($r['body'], '<meta property="og:image" content="https://example.test/media/branding/' . $logoLight . '">')
    && str_contains($r['body'], '<meta name="twitter:image" content="https://example.test/media/branding/' . $logoLight . '">'));

$brand('logo_dark', $makeImage(220, 90));
$logoDark = $pdo->query("SELECT value FROM setting WHERE name = 'core.logo_dark'")->fetchColumn();
check('branding: a distinct dark logo is shown alongside the light one', $logoDark !== false && $logoDark !== $logoLight
    && str_contains($get('/')['body'], 'class="site-logo site-logo-dark" src="/media/branding/' . $logoDark . '"'));
$brand('logo_dark/delete', null);
check('branding: removing the dark logo falls back to the light one, shown only once', $pdo->query("SELECT value FROM setting WHERE name = 'core.logo_dark'")->fetchColumn() === ''
    && !str_contains($get('/')['body'], 'site-logo-dark'));

$brand('favicon', $makeImage(300, 300));
$favicon = $pdo->query("SELECT value FROM setting WHERE name = 'core.favicon'")->fetchColumn();
check('branding: the favicon is linked on site and administration pages', $favicon !== false
    && str_contains($get('/')['body'], '<link rel="icon" href="/media/branding/' . $favicon . '">')
    && str_contains($get('/admin', 3)['body'], '<link rel="icon" href="/media/branding/' . $favicon . '">'));
$app = new Modulento\Core\App($config, $pdo);
check('branding: only files the upload created are served', $app->branding->path($favicon) !== null && $app->branding->path('../../../.env') === null && $app->branding->path(substr($favicon, 0, -3) . 'php') === null);
$brand('favicon/delete', null);
check('branding: removing it leaves no icon link', !str_contains($get('/')['body'], 'rel="icon"') && $pdo->query("SELECT value FROM setting WHERE name = 'core.favicon'")->fetchColumn() === '');
$brand('logo_light/delete', null);
check('branding: without a logo the header falls back to the name again', !str_contains($get('/')['body'], 'class="site-logo'));

check('branding: the default description is empty, so no tag appears', !str_contains($get('/')['body'], 'name="description"') && !str_contains($get('/')['body'], 'property="og:description"'));
$post('/admin/settings', $settings + ['meta_description' => str_repeat('ü', 310)], 3);
check('branding: the default description is kept to 300 characters', mb_strlen($pdo->query("SELECT value FROM setting WHERE name = 'core.meta_description'")->fetchColumn()) === 300);
$post('/admin/settings', $settings + ['meta_description' => 'Ein Marktplatz für alles Mögliche.'], 3);
$r = $get('/');
check('branding: the default description appears on a page without its own, also as og:description', str_contains($r['body'], '<meta name="description" content="Ein Marktplatz für alles Mögliche.">')
    && str_contains($r['body'], '<meta property="og:description" content="Ein Marktplatz für alles Mögliche.">')
    && str_contains($r['body'], '<meta property="og:site_name" content="Testseite">') && str_contains($r['body'], '<meta property="og:type" content="website">')
    && str_contains($r['body'], '<meta property="og:url" content="https://example.test/">'));

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
$lastNotification = fn () => $pdo->query('SELECT * FROM notification ORDER BY id DESC LIMIT 1')->fetch();
check('notification: an admin decision about the offer notifies the provider, not the admin who decided', $lastNotification()['account_id'] == 1 && $lastNotification()['type'] === 'offer_status'
    && str_contains($lastNotification()['params'], 'Abgelehnt'));
$post('/account/offers/' . $offerId . '/submit', [], 1);
$post('/admin/offers/' . $offerId . '/decide', ['decision' => 'approve'], 3);
check('offer: resubmitted and approved, provider gets the public link', $offerRow()['status'] === 'published' && $offerRow()['published_at'] !== null
    && lastMail($mailLog, 'plain@example.test')['link'] === '/offers/ich-gestalte-dein-logo');
check('notification: approval notifies the provider too, with a working link', $lastNotification()['account_id'] == 1 && str_contains($lastNotification()['params'], 'Veröffentlicht')
    && str_contains($get('/account/notifications', 1)['body'], 'Ich gestalte dein Logo'));

$r = $get('/offers/ich-gestalte-dein-logo', null);
check('offer page: text escaped, packages, extra and requirements shown', $r['status'] === 200 && str_contains($r['body'], '&lt;script&gt;') && str_contains($r['body'], '<strong>Basis</strong>')
    && str_contains($r['body'], '<strong>Komplett</strong>') && str_contains($r['body'], '99,00 €') && str_contains($r['body'], 'Quelldatei: + 20,00 €') && str_contains($r['body'], 'Firmenname und Farben'));
check('offer page: picture and link to the provider', str_contains($r['body'], "/media/offers/{$offerId}/{$image['name']}") && str_contains($r['body'], 'href="/providers/mueller-design"'));
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'example'");
check('offerSection(): the extension\'s block is rendered on the public offer page', str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], 'Vom Beispiel-Angebotsblock') && str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], '#' . $offerId));
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'example'");
check('offerSection(): disabled again, the block is gone', !str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], 'Vom Beispiel-Angebotsblock'));
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
$post('/offers/ich-gestalte-dein-logo/contact', ['message' => '   '], 2);
$post('/offers/ich-gestalte-dein-logo/contact', ['message' => 'Hallo, ich hätte gern ein Logo für mein Café.'], 1);
check('contact: an empty message and writing to oneself send nothing', lastMail($mailLog, 'plain@example.test')['subject'] !== 'Anfrage zu deinem Angebot „Ich gestalte dein Logo“');
$post('/offers/ich-gestalte-dein-logo/contact', ['message' => 'ok'], 2);
check('contact: a short message like "ok" is accepted - no minimum length anymore', lastMail($mailLog, 'plain@example.test')['subject'] === 'Anfrage zu deinem Angebot „Ich gestalte dein Logo“');
$post('/offers/ich-gestalte-dein-logo/contact', ['message' => 'Hallo, ich hätte gern ein Logo für mein Café.'], 2);
$mails = (string) file_get_contents($mailLog);
check('contact: the provider gets the message with the sender as reply address', lastMail($mailLog, 'plain@example.test')['subject'] === 'Anfrage zu deinem Angebot „Ich gestalte dein Logo“'
    && str_contains($mails, "Reply-To: editor@example.test") && str_contains($mails, 'Logo für mein Café'));

// A fresh service for each check: the requests above save through their own.
$words = fn () => new Modulento\Core\Support\BadWords(new Modulento\Core\Support\Settings($pdo));
// Word filter and conversations about an offer. Account 1 provides "Ich gestalte dein Logo",
// account 2 asks about it.
check('badwords: listed words are found, in any case, with stretched letters and stars, but not inside short words', $words()->find('Das ist SCHEIßE') === 'scheisse'
    && $words()->find('du f*ck') === 'fuck' && $words()->find('fuuuuck off') === 'fuck'
    && $words()->find('Arschlochkerl') === 'arsch' && $words()->find('Das Classic-Auto ist schön, danke.') === null
    && $words()->find('class assist glass') === null);
$count = fn () => (int) $pdo->query('SELECT COUNT(*) FROM offer_message')->fetchColumn();
$lastOfferMessage = fn () => $pdo->query('SELECT * FROM offer_message ORDER BY id DESC LIMIT 1')->fetch();
$before = $count();
$post('/offers/ich-gestalte-dein-logo/contact', ['message' => 'Du bist ein verdammter Arschloch, das ist klar.'], 2);
check('badwords: the word filter no longer refuses a message - it is kept, flagged, delivered by e-mail as usual', $count() === $before + 1
    && $lastOfferMessage()['flagged_word'] === 'arschloch' && $lastOfferMessage()['status'] === 'visible'
    && lastMail($mailLog, 'plain@example.test')['subject'] === 'Anfrage zu deinem Angebot „Ich gestalte dein Logo“');
$post('/offers/ich-gestalte-dein-logo/contact', ['message' => 'Dazu hätte ich gern eine Visitenkarte im gleichen Stil.'], 2);
check('contact: the message is kept in the thread as well as sent by e-mail', $count() === $before + 2 && $lastOfferMessage()['flagged_word'] === null
    && lastMail($mailLog, 'plain@example.test')['subject'] === 'Anfrage zu deinem Angebot „Ich gestalte dein Logo“');
$rr = $get('/offers/ich-gestalte-dein-logo', 2);
check('conversations: the visitor sees their own thread', str_contains($rr['body'], 'Dazu hätte ich gern eine Visitenkarte') && str_contains($rr['body'], 'Du'));
$rr = $get('/offers/ich-gestalte-dein-logo', 1);
check('conversations: the provider sees every thread with its visitor and a reply form', str_contains($rr['body'], 'Gespräch mit') && str_contains($rr['body'], 'Dazu hätte ich gern eine Visitenkarte')
    && str_contains($rr['body'], 'action="/offers/ich-gestalte-dein-logo/contact/2"'));
check('conversations: a visitor without a login sees no thread', !str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], 'Dazu hätte ich gern eine Visitenkarte'));
$before = $count();
$post('/offers/ich-gestalte-dein-logo/contact/2', ['message' => 'Ich antworte mir selbst, das geht nicht.'], 2);
check('reply: the visitor cannot answer, nothing is kept', $count() === $before);
$post('/offers/ich-gestalte-dein-logo/contact/99', ['message' => 'Antwort an niemanden.'], 1);
check('reply: a visitor without a thread gets no reply', $count() === $before);
$post('/offers/ich-gestalte-dein-logo/contact/2', ['message' => 'So ein Idiot-Anbieter, du Hurensohn.'], 1);
check('reply: the word filter flags an answer instead of refusing it, still delivered', $count() === $before + 1
    && $lastOfferMessage()['flagged_word'] !== null && $lastOfferMessage()['status'] === 'visible'
    && lastMail($mailLog, 'editor@example.test')['subject'] === 'Antwort zu deinem Angebot „Ich gestalte dein Logo“');
$post('/offers/ich-gestalte-dein-logo/contact/2', ['message' => 'Gern, ich schicke dir Entwürfe für die Karte.'], 1);
check('reply: the provider\'s answer is kept and the visitor gets an e-mail', $count() === $before + 2
    && $pdo->query('SELECT COUNT(*) FROM offer_message WHERE author_id = 1 AND asker_id = 2')->fetchColumn() >= 1
    && lastMail($mailLog, 'editor@example.test')['subject'] === 'Antwort zu deinem Angebot „Ich gestalte dein Logo“');

// Polling for new messages: the interval is an administration setting; the badge counts what is unread.
$post('/admin/settings', ['site_name' => 'Testseite', 'mail_from' => 'noreply@example.test', 'registration' => 'open', 'default_locale' => 'de', 'locales' => ['de', 'en'], 'poll_seconds' => '30'], 3);
check('polling: the interval is saved, limited to an hour, and shown to logged-in accounts', $pdo->query("SELECT value FROM setting WHERE name = 'core.poll_seconds'")->fetchColumn() === '30'
    && str_contains($get('/account/settings', 1)['body'], '') && str_contains($get('/offers/ich-gestalte-dein-logo', 1)['body'], 'data-poll="30"'));
$post('/admin/settings', ['site_name' => 'Testseite', 'mail_from' => 'noreply@example.test', 'registration' => 'open', 'default_locale' => 'de', 'locales' => ['de', 'en'], 'poll_seconds' => '99999'], 3);
check('polling: a larger value is limited, a visitor gets no badge', $pdo->query("SELECT value FROM setting WHERE name = 'core.poll_seconds'")->fetchColumn() === '3600'
    && str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], 'data-unread') === false);
$unreadOf = function (int $account) use ($get): int {
    $r = $get('/account/unread', $account);

    return (int) (json_decode($r['body'], true)['count'] ?? -1);
};
// Counts include messages of orders from earlier checks, so only the change is compared.
// Opening the offer first brings both to a known state: nothing unread on the offer.
$get('/offers/ich-gestalte-dein-logo', 1);
$get('/offers/ich-gestalte-dein-logo', 2);
$u1 = $unreadOf(1);
$u2 = $unreadOf(2);
$post('/offers/ich-gestalte-dein-logo/contact', ['message' => 'Eine Frage: gibt es das Logo auch als Vektordatei?'], 2);
check('polling: a new question is unread for the provider, not for the visitor who asked', $unreadOf(1) === $u1 + 1 && $unreadOf(2) === $u2);
$get('/offers/ich-gestalte-dein-logo', 1);
check('polling: opening the offer marks its threads read for the provider', $unreadOf(1) === $u1);
$post('/offers/ich-gestalte-dein-logo/contact/2', ['message' => 'Ja, als SVG und als PDF.'], 1);
check('polling: an answer is unread for the visitor until they open the offer', $unreadOf(2) === $u2 + 1);
$get('/offers/ich-gestalte-dein-logo', 2);
check('polling: opening the offer as the visitor marks the answer read', $unreadOf(2) === $u2);
$post('/offers/ich-gestalte-dein-logo/contact', ['message' => 'Noch eine Frage: wie lange dauert das?'], 2);
$get('/offers/ich-gestalte-dein-logo', 1);
$post('/offers/ich-gestalte-dein-logo/contact/2', ['message' => 'Mein eigener Gedanke, nicht gelesen.'], 2);
check('polling: a message from oneself is never unread', $unreadOf(2) === $u2);
$post('/admin/settings', ['site_name' => 'Testseite', 'mail_from' => 'noreply@example.test', 'registration' => 'open', 'default_locale' => 'de', 'locales' => ['de', 'en'], 'poll_seconds' => '0'], 3);
check('polling: 0 switches the asking off', str_contains($get('/offers/ich-gestalte-dein-logo', 1)['body'], 'data-poll="0"'));

// The offer page: its parts in the order an administrator sets; a text block in the header's language.
$offerBlocks = fn () => json_decode((string) $pdo->query("SELECT value FROM setting WHERE name = 'core.offer_layout'")->fetchColumn(), true);
$offerPage = $get('/offers/ich-gestalte-dein-logo', null);
check('offer page: by default it shows the description and the questions, as before', $offerPage['status'] === 200 && str_contains($offerPage['body'], 'Ich gestalte dein Logo') && str_contains($offerPage['body'], 'offer-messages'));
check('offer page editor: only administrators with the settings permission', $get('/admin/offer-page', 3)['status'] === 200 && $get('/admin/offer-page', 1)['status'] === 403);
$post('/admin/offer-page', ['action' => 'add'], 3);
$offerTextId = array_values(array_filter($offerBlocks() ?? [], fn (array $b) => $b['type'] === 'text'))[0]['id'] ?? null;
$offerNow = $offerBlocks();
check('offer page editor: a text block can be added at the end', $offerTextId !== null && end($offerNow)['type'] === 'text');
$post('/admin/offer-page', ['action' => 'save', 'blocks' => [
    $offerTextId => ['enabled' => '1', 'texts' => ['de' => ['heading' => 'Garantie', 'body' => '<p>Zwei Korrekturschleifen.</p><script>x</script>']]],
    'contact' => ['enabled' => '1'],
]], 3);
check('offer page: a text block shows its cleaned text in the page\'s language, and a hidden part disappears', str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], 'Zwei Korrekturschleifen')
    && !str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], '<script>x') && str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], 'Garantie'));
$post('/admin/offer-page', ['action' => 'save', 'blocks' => ['contact' => ['texts' => []]]], 3);
check('offer page editor: a part that is not ticked is hidden, and the page still works', !str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], 'offer-messages')
    && str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], 'Garantie'));
$post('/admin/offer-page', ['action' => 'up:' . $offerTextId], 3);
check('offer page editor: parts move; the core parts stay, the text part can be removed', $offerBlocks()[count($offerBlocks()) - 2]['id'] === $offerTextId);
$post('/admin/offer-page', ['action' => 'delete:' . $offerTextId], 3);
check('offer page editor: a text block is removed, a core part is not', array_values(array_filter($offerBlocks(), fn (array $b) => $b['type'] === 'text')) === [] && count($offerBlocks()) === 5);
$post('/admin/offer-page', ['action' => 'reset'], 3);
check('offer page editor: a reset brings back the default page', !$pdo->query("SELECT COUNT(*) FROM setting WHERE name = 'core.offer_layout'")->fetchColumn());

check('in-place editing: the offer page offers its tools too, built-in parts without a text form', str_contains($get('/offers/ich-gestalte-dein-logo?edit=1', 3)['body'], 'inline-tools')
    && str_contains($get('/offers/ich-gestalte-dein-logo?edit=1', 3)['body'], 'name="action" value="toggle:') && !str_contains($get('/offers/ich-gestalte-dein-logo', 3)['body'], 'inline-tools'));
// The pencil: in the header of the home page and of an offer, for whoever may edit; nowhere else, and not to visitors.
check('edit link: an administrator finds the pencil on the home page and on an offer, a visitor does not', str_contains($get('/', 3)['body'], 'class="edit-page" href="/?edit=1"')
    && str_contains($get('/offers/ich-gestalte-dein-logo', 3)['body'], 'class="edit-page" href="/offers/ich-gestalte-dein-logo?edit=1"')
    && !str_contains($get('/', null)['body'], 'class="edit-page"') && !str_contains($get('/', 1)['body'], 'class="edit-page"'));
check('edit link: not on other pages, and the control rules are in the stylesheet the pages load', !str_contains($get('/offers', 3)['body'], 'class="edit-page"')
    && str_contains($get('/design/' . (new Modulento\Core\Support\Design(new Modulento\Core\Support\Settings($pdo)))->fileName(), null)['body'], '.edit-page'));

// Widgets: ready blocks inserted from the "+" menu; a block kept as a widget of one's own, used again, removed again.
$widgetBlocks = fn () => (new Modulento\Core\Content\HomeLayout(new Modulento\Core\Support\Settings($pdo)))->blocks();
$widgetCount = fn () => count($widgetBlocks());
$shipped = $widgetCount();
$post('/admin/home', ['action' => 'insert:default-hero', 'add_type' => 'widget:builtin:cta'], 3);
$withCta = $widgetBlocks();
$ctaBlock = $withCta[1] ?? [];
check('widgets: a shipped widget is inserted after the block it was chosen at, with its texts and button', count($withCta) === $shipped + 1 && ($ctaBlock['type'] ?? '') === 'text'
    && ($ctaBlock['texts']['de']['button_url'] ?? '') === '/register' && ($ctaBlock['texts']['en']['heading'] ?? '') === 'Ready to start?');
$post('/admin/home', ['action' => 'insert:default-hero', 'add_type' => 'widget:builtin:nonsense'], 3);
$post('/admin/home', ['action' => 'insert:default-hero', 'add_type' => 'widget:own:../../x'], 3);
check('widgets: an unknown widget inserts nothing', $widgetCount() === $shipped + 1);
$post('/admin/home', ['action' => 'savewidget:' . $ctaBlock['id'], 'widget_name' => '  Mein   Aufruf  '], 3);
$own = (new Modulento\Core\Content\Widgets(new Modulento\Core\Support\Settings($pdo)))->own();
check('widgets: a block saved as a widget keeps its name, kind and texts in every language', count($own) === 1 && $own[0]['name'] === 'Mein Aufruf' && $own[0]['type'] === 'text'
    && ($own[0]['texts']['en']['button_label'] ?? '') === 'Get started');
$post('/admin/home', ['action' => 'savewidget:' . $ctaBlock['id'], 'widget_name' => str_repeat('x', 61)], 3);
check('widgets: a name too long is refused', count((new Modulento\Core\Content\Widgets(new Modulento\Core\Support\Settings($pdo)))->own()) === 1 && str_contains($_SESSION['_flash']['error'] ?? '', 'Namen'));
$ownId = $own[0]['id'];
$post('/admin/home', ['action' => 'insert:start', 'add_type' => 'widget:own:' . $ownId], 3);
check('widgets: an own widget is inserted like a shipped one, as a copy with its own id', $widgetCount() === $shipped + 2 && $widgetBlocks()[0]['texts']['de']['heading'] === 'Bereit loslegen?'
    && $widgetBlocks()[0]['id'] !== $ctaBlock['id']);
check('widgets: the own widget is listed on the editor, and the offer page offers it too', str_contains($get('/admin/home', 3)['body'], 'Mein Aufruf')
    && str_contains($get('/offers/ich-gestalte-dein-logo?edit=1', 3)['body'], 'value="widget:own:' . $ownId . '"'));
$post('/admin/home', ['action' => 'removewidget:' . $ownId], 3);
check('widgets: an own widget can be removed; the blocks made from it stay', (new Modulento\Core\Content\Widgets(new Modulento\Core\Support\Settings($pdo)))->own() === [] && $widgetCount() === $shipped + 2);
$post('/admin/home', ['action' => 'reset'], 3);

// Blocks dragged into another order: listed ids first, unknown and repeated ids dropped, the rest kept behind.
$three = [['id' => 'a'], ['id' => 'b'], ['id' => 'c']];
$ids = array_map(fn (array $block) => $block['id'], Modulento\Core\Content\HomeLayout::ordered($three, ['c', 'x', 'a', 'c']));
check('order: the listed order wins, unknown and repeated ids are ignored', $ids === ['c', 'a', 'b']);
check('order: a list left out keeps every block', count(Modulento\Core\Content\HomeLayout::ordered($three, [])) === 3);

// --- Subscriptions: the thin facade core keeps once the feature itself moved to an addon ---
// (modulento-ext-abos, Registrar::subscriptionProvider()) - plan CRUD, checkout,
// Stripe billing and invoices are tested there, against the real provider. Core's
// own job is: behave openly with none installed, and faithfully ask whichever one
// is, at the right moment (OfferController's quotas, the catalogue's bonus,
// Router::FEATURE). A small provider of this suite's own, backed by the real
// subscription/subscription_plan tables, stands in for the addon - see
// TestSubscriptionProvider below and extensions/example's own use of the hook.
check('subscriptions: without a provider installed, every feature is open, nothing is granted, nothing is limited', $app->subscriptions->allows(1, 'anything')
    && !$app->subscriptions->grants(1, 'anything') && $app->subscriptions->offerLimit(1, 'freelancer.service') === null
    && $app->subscriptions->imageLimit(1) === null && $app->subscriptions->accountIdsWithFeature('anything') === []
    && $app->subscriptions->activePlans() === [] && $app->subscriptions->plans() === [] && $app->subscriptions->current(1) === null
    && !$app->subscriptions->hasProvider());

$subs = new Modulento\Core\Subscription\Subscriptions();
check('subscriptions: declareFeature()/declaredFeatures() work before any provider is registered - an extension declares what it sells regardless', (function () use ($subs): bool {
    $subs->declareFeature('abo.declared', 'core.module.subscriptions.name');
    if ($subs->declaredFeatures() !== ['abo.declared' => 'core.module.subscriptions.name']) {
        return false;
    }
    try {
        $subs->declareFeature('Bad Key', 'x');
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
})());
$dummyProvider = new class implements Modulento\Core\Subscription\SubscriptionProvider {
    public function allows(int $accountId, string $feature): bool { return true; }
    public function grants(int $accountId, string $feature): bool { return true; }
    public function offerLimit(int $accountId, string $offerType): ?int { return null; }
    public function imageLimit(int $accountId): ?int { return null; }
    public function accountIdsWithFeature(string $feature): array { return [1]; }
    public function activePlans(): array { return [['id' => 1]]; }
    public function plans(): array { return [['id' => 1]]; }
    public function current(int $accountId): ?array { return ['id' => 1]; }
    public function setQuantity(int $accountId, int $quantity): void { }
};
$subs->registerProvider($dummyProvider);
check('subscriptions: registerProvider()/hasProvider() wire a provider in, delegating every call', $subs->hasProvider() && $subs->allows(1, 'x') && $subs->grants(1, 'x')
    && $subs->offerLimit(1, 'x') === null && $subs->imageLimit(1) === null && $subs->accountIdsWithFeature('x') === [1]
    && $subs->activePlans() === [['id' => 1]] && $subs->plans() === [['id' => 1]] && $subs->current(1) === ['id' => 1]);

// A fuller provider for the integration tests below: backed by the real
// subscription/subscription_plan tables (just as modulento-ext-abos's own
// will be), registered through the example extension so that a real,
// freshly-dispatched request sees it exactly as it would see a real addon.
final class TestSubscriptionProvider implements Modulento\Core\Subscription\SubscriptionProvider
{
    public function __construct(private PDO $db)
    {
    }

    public function current(int $accountId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.id, s.quantity, s.stripe_item_ref, p.features, p.offer_limits, p.max_images_per_offer, p.per_seat, p.min_quantity
             FROM subscription s JOIN subscription_plan p ON p.id = s.plan_id
             WHERE s.account_id = :account AND s.status IN ('trialing', 'active', 'past_due') AND (s.period_end IS NULL OR s.period_end > :now)
             ORDER BY s.id DESC LIMIT 1"
        );
        $stmt->execute(['account' => $accountId, 'now' => Modulento\Core\Support\Clock::now()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'id' => (int) $row['id'], 'quantity' => (int) $row['quantity'], 'stripe_item_ref' => $row['stripe_item_ref'],
            'features' => $this->features($row['features']), 'offer_limits' => $this->offerLimits($row['offer_limits']),
            'max_images_per_offer' => $row['max_images_per_offer'] !== null ? (int) $row['max_images_per_offer'] : null,
            'per_seat' => (bool) $row['per_seat'], 'min_quantity' => (int) $row['min_quantity'],
        ];
    }

    public function allows(int $accountId, string $feature): bool
    {
        $current = $this->current($accountId);

        return $current !== null && in_array($feature, $current['features'], true);
    }

    public function grants(int $accountId, string $feature): bool
    {
        return $this->allows($accountId, $feature);
    }

    public function offerLimit(int $accountId, string $offerType): ?int
    {
        $current = $this->current($accountId);

        return $current === null ? 0 : ($current['offer_limits'][$offerType] ?? null);
    }

    public function imageLimit(int $accountId): ?int
    {
        $current = $this->current($accountId);

        return $current === null ? 0 : $current['max_images_per_offer'];
    }

    public function accountIdsWithFeature(string $feature): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.account_id, p.features FROM subscription s JOIN subscription_plan p ON p.id = s.plan_id
             WHERE s.status IN ('trialing', 'active', 'past_due') AND (s.period_end IS NULL OR s.period_end > :now)"
        );
        $stmt->execute(['now' => Modulento\Core\Support\Clock::now()]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (in_array($feature, $this->features($row['features']), true)) {
                $ids[] = (int) $row['account_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    public function plans(): array
    {
        $rows = $this->db->query('SELECT * FROM subscription_plan ORDER BY price_cents, id')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row) => [
            'id' => (int) $row['id'], 'slug' => $row['slug'], 'name' => $row['name'], 'price_cents' => (int) $row['price_cents'],
            'currency' => $row['currency'], 'period_months' => (int) $row['period_months'], 'features' => $this->features($row['features']),
            'offer_limits' => $this->offerLimits($row['offer_limits']),
            'max_images_per_offer' => $row['max_images_per_offer'] !== null ? (int) $row['max_images_per_offer'] : null,
            'per_seat' => (bool) $row['per_seat'], 'min_quantity' => (int) $row['min_quantity'], 'active' => (bool) $row['active'],
        ], $rows);
    }

    public function activePlans(): array
    {
        return array_values(array_filter($this->plans(), fn (array $plan) => $plan['active']));
    }

    public function setQuantity(int $accountId, int $quantity): void
    {
        $current = $this->current($accountId);
        if ($current === null) {
            throw new InvalidArgumentException('subscription: none active');
        }
        $quantity = max($quantity, $current['min_quantity']);
        if ($quantity === $current['quantity']) {
            return;
        }
        $this->db->prepare('UPDATE subscription SET quantity = :quantity, updated_at = :now WHERE id = :id')
            ->execute(['quantity' => $quantity, 'now' => Modulento\Core\Support\Clock::now(), 'id' => $current['id']]);
    }

    /** @return list<string> */
    private function features(string $list): array
    {
        return array_values(array_filter(explode(',', $list), fn (string $f) => $f !== ''));
    }

    /** @return array<string, int> */
    private function offerLimits(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }
}

Modulento\Example\Extension::$subscriptionProvider = new TestSubscriptionProvider($pdo);
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'example'");

// Offer and image quotas: OfferController asks Subscriptions, not a plan directly.
$quotaId = $accounts->create('abo-quota@example.test', $pw, 'de', verified: true);
$post('/account/provider', $private, $quotaId);
$pdo->exec("UPDATE provider SET status = 'approved' WHERE account_id = $quotaId");
$quotaPlan = (function () use ($pdo): int {
    $pdo->prepare("INSERT INTO subscription_plan (slug, name, price_cents, currency, period_months, features, offer_limits, max_images_per_offer, active)
        VALUES ('abo-quota', 'Quotenplan', 500, 'EUR', 1, '', :limits, 1, 1)")
        ->execute(['limits' => json_encode(['freelancer.service' => 1])]);

    return (int) $pdo->lastInsertId();
})();
$assignPlan = function (int $accountId, int $planId, ?string $periodEnd = null) use ($pdo): void {
    $pdo->prepare("UPDATE subscription SET status = 'canceled', updated_at = :now WHERE account_id = :account AND status <> 'canceled'")
        ->execute(['now' => Modulento\Core\Support\Clock::now(), 'account' => $accountId]);
    $pdo->prepare("INSERT INTO subscription (account_id, plan_id, status, period_end, quantity, created_at, updated_at) VALUES (:account, :plan, 'active', :end, 1, :now, :now2)")
        ->execute(['account' => $accountId, 'plan' => $planId, 'end' => $periodEnd, 'now' => Modulento\Core\Support\Clock::now(), 'now2' => Modulento\Core\Support\Clock::now()]);
};
$assignPlan($quotaId, $quotaPlan);
$get('/account/offers/new?type=freelancer.service', $quotaId);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 1, 'locales' => []], false);
$quotaOfferId = (int) $pdo->query("SELECT id FROM offer WHERE provider_id = (SELECT id FROM provider WHERE account_id = $quotaId)")->fetchColumn();
check('offer quota: a plan with freelancer.service = 1 lets the first draft through', $quotaOfferId > 0);
$get('/account/offers/new?type=freelancer.service', $quotaId);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 1, 'locales' => []], false);
check('offer quota: the second one is refused once the plan\'s number is reached', $pdo->query("SELECT COUNT(*) FROM offer WHERE provider_id = (SELECT id FROM provider WHERE account_id = $quotaId)")->fetchColumn() == 1);
$quotaImage = tempnam(sys_get_temp_dir(), 'img');
imagepng(imagecreatetruecolor(50, 50), $quotaImage);
$_FILES = ['image' => ['tmp_name' => $quotaImage, 'error' => UPLOAD_ERR_OK, 'size' => filesize($quotaImage), 'name' => 'x.png', 'type' => 'image/png']];
$post('/account/offers/' . $quotaOfferId . '/images', [], $quotaId);
check('image quota: the plan\'s max_images_per_offer = 1 lets the first picture through', $pdo->query("SELECT COUNT(*) FROM offer_image WHERE offer_id = $quotaOfferId")->fetchColumn() == 1);
$post('/account/offers/' . $quotaOfferId . '/images', [], $quotaId);
check('image quota: a second picture is refused at that same number', $pdo->query("SELECT COUNT(*) FROM offer_image WHERE offer_id = $quotaOfferId")->fetchColumn() == 1);
unset($_FILES);

// Three bonuses a plan can grant on top of the baseline: skip review, a badge, placed first.
$bonusPlan = (function () use ($pdo): int {
    $pdo->prepare("INSERT INTO subscription_plan (slug, name, price_cents, currency, period_months, features, offer_limits, max_images_per_offer, active)
        VALUES ('abo-bonus', 'Bonusplan', 500, 'EUR', 1, 'core.offer.auto_approve,core.provider.featured_badge,core.catalogue.priority_placement', '{}', NULL, 1)")
        ->execute();

    return (int) $pdo->lastInsertId();
})();
$assignPlan($quotaId, $bonusPlan);

$get('/account/offers/new?type=freelancer.service', $quotaId);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 1, 'locales' => []], false);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 2, 'category_id' => $childId], false);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 3, 'text' => ['de' => ['title' => 'Bonus-Angebot', 'summary' => '']]], false);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 4, 'text' => ['de' => ['description' => 'x']]], false);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 5, 'package' => [1 => ['price' => '10', 'delivery_days' => '1']]], false);
$post('/account/offers/wizard', ['type' => 'freelancer.service', 'wizard_step' => 6], false);
$post('/account/offers/new', ['type' => 'freelancer.service', 'category_id' => (string) $childId,
    'text' => ['de' => ['title' => 'Bonus-Angebot', 'summary' => '']], 'package' => [1 => ['price' => '10', 'delivery_days' => '1']]], false);
$bonusOfferId = (int) $pdo->query("SELECT id FROM offer WHERE provider_id = (SELECT id FROM provider WHERE account_id = $quotaId) ORDER BY id DESC LIMIT 1")->fetchColumn();
$post('/account/offers/' . $bonusOfferId . '/submit', [], false);
check('offer auto-approve: a plan granting core.offer.auto_approve publishes at once, no admin decision needed', $pdo->query("SELECT status FROM offer WHERE id = {$bonusOfferId}")->fetchColumn() === 'published');

$providerSlug = $pdo->query("SELECT slug FROM provider WHERE account_id = $quotaId")->fetchColumn();
check('provider: core.provider.featured_badge shows on the profile, kept apart from earned badges', str_contains($get('/providers/' . $providerSlug, null)['body'], 'Empfohlener Anbieter'));
$ratingSorted = $get('/offers?sort=rating', null)['body'];
check('catalogue: core.catalogue.priority_placement puts an unrated featured offer ahead of a rated one', strpos($ratingSorted, 'Bonus-Angebot') !== false
    && strpos($ratingSorted, 'Bonus-Angebot') < strpos($ratingSorted, 'Ich gestalte dein Logo'));

$pdo->exec("UPDATE subscription SET status = 'canceled' WHERE account_id = $quotaId");
$testApp->offerImages->deleteAll($quotaOfferId);
$testApp->offers->delete($quotaOfferId);
$testApp->offerImages->deleteAll($bonusOfferId);
$testApp->offers->delete($bonusOfferId);
check('catalogue: without a plan granting it, no more badge and no more boost', !str_contains($get('/providers/' . $providerSlug, null)['body'], 'Empfohlener Anbieter'));

// A route gated by "feature:<key>" - Router::FEATURE, baked into Router::allowed().
$featureRoute = function (?int $as) use ($pdo, $config): array {
    $_SESSION = [];
    if ($as !== null) {
        $hash = $pdo->query('SELECT password_hash FROM account WHERE id = ' . $as)->fetchColumn();
        $_SESSION = ['account_id' => $as, 'auth_stamp' => Modulento\Core\Support\Auth::stamp((string) $hash)];
    }
    $_SESSION['_csrf'] = 'test-token';
    $app = new Modulento\Core\App($config, $pdo);
    $app->translator->load($config['app']['root'] . '/core/lang', 'core');
    Modulento\Core\Kernel::registerCore($app);
    $app->extensions->loadEnabled($app);
    $called = false;
    $app->router->get('/abo-feature', function () use (&$called): void {
        $called = true;
    }, 'feature:core.offer.auto_approve');
    Modulento\Core\Kernel::registerLast($app);
    Modulento\Core\Kernel::loadThemeTexts($app);
    Modulento\Core\Kernel::prepareRequest($app, '/abo-feature', startSession: false);
    http_response_code(200);
    ob_start();
    $app->router->dispatch('GET', $app->path);
    ob_end_clean();

    return ['called' => $called, 'status' => (int) http_response_code()];
};
check('feature routes: an account without the feature gets a refusal, the route does not run', $featureRoute(3)['called'] === false && $featureRoute(3)['status'] === 403);
$assignPlan($quotaId, $bonusPlan);
$featureOk = $featureRoute($quotaId);
check('feature routes: an account whose plan lists the feature gets the route', $featureOk['called'] === true);
check('feature routes: a visitor gets no route either', $featureRoute(null)['called'] === false);

$pdo->exec("DELETE FROM subscription WHERE plan_id IN ($quotaPlan, $bonusPlan)");
$pdo->exec("DELETE FROM subscription_plan WHERE id IN ($quotaPlan, $bonusPlan)");
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'example'");
Modulento\Example\Extension::$subscriptionProvider = null;

// Pictures of the media library: only those, in pages.
$library = '/media/library/' . str_repeat('a', 32) . '.webp';
$clean = Modulento\Core\Support\HtmlSanitizer::clean('<p><img src="' . $library . '" alt="Logo"><img src="https://evil.example/x.png"><img src="/media/library/../x.png"><img src="/assets/x.png"></p>');
check('pictures: the sanitizer keeps library pictures with their text and removes every other picture', str_contains($clean, '<img src="' . $library . '" alt="Logo">')
    && substr_count($clean, '<img') === 1);
$pdo->exec("INSERT INTO media (file, title, width, height, bytes, created_at) VALUES ('" . str_repeat('b', 32) . ".png', 'Logo', 40, 30, 100, '2026-10-04 10:00:00')");
check('pictures: the page editor offers the library pictures for the text', str_contains($get('/admin/pages/new', 3)['body'], 'data-media-insert="body_de"')
    && str_contains($get('/admin/pages/new', 3)['body'], 'data-media-url="/media/library/' . str_repeat('b', 32) . '.png"') && !str_contains($get('/admin/pages/new', 2)['body'], 'data-media-insert'));
$pdo->exec("DELETE FROM media");

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
check('with an extension that adds offers the catalogue links are there', str_contains($r['body'], 'href="/offers"') && str_contains($r['body'], 'href="/providers"'));
check('offer and provider pages link to the report form with their own address', substr_count($r['body'], 'href="/report?url=https%3A%2F%2Fexample.test%2Foffers%2Fich-gestalte-dein-logo"') === 2
    && str_contains($get('/en/providers/mueller-design', null)['body'], 'href="/en/report?url=https%3A%2F%2Fexample.test%2Fen%2Fproviders%2Fmueller-design"'));
check('the report link in the footer names the page it is on, but not the account\'s own pages', str_contains($get('/offers', null)['body'], 'href="/report?url=https%3A%2F%2Fexample.test%2Foffers"')
    && str_contains($get('/account', 1)['body'], '<a href="/report">'));

// A notice about this offer: the administrator gets to it with a click,
// and the provider hears about action taken - not who reported.
$post('/report', ['url' => 'https://example.test/offers/ich-gestalte-dein-logo', 'category' => 'fraud', 'explanation' => 'Dieses Angebot ist nicht in Ordnung.', 'name' => 'Erika', 'email' => 'erika@example.test', 'good_faith' => '1'], null);
$post('/report', ['url' => 'https://elsewhere.test/offers/ich-gestalte-dein-logo', 'category' => 'fraud', 'explanation' => 'Dieses Angebot ist nicht in Ordnung.', 'name' => 'Erika', 'email' => 'erika@example.test', 'good_faith' => '1'], null);
$post('/report', ['url' => '/en/providers/mueller-design', 'category' => 'other', 'explanation' => 'Dieser Anbieter ist nicht in Ordnung.', 'name' => 'Erika', 'email' => 'erika@example.test', 'good_faith' => '1'], null);
$subjects = $pdo->query('SELECT offer_id, provider_id FROM report ORDER BY id')->fetchAll();
check('report: an address of this site is matched to its offer or provider, another site is not', (int) $subjects[0]['offer_id'] === $offerId && (int) $subjects[0]['provider_id'] === $providerId
    && $subjects[1]['offer_id'] === null && $subjects[1]['provider_id'] === null && $subjects[2]['offer_id'] === null && (int) $subjects[2]['provider_id'] === $providerId);
check('report: the administration links to the offer and the provider', str_contains($get('/admin/reports', 3)['body'], 'href="/admin/offers/' . $offerId . '"') && str_contains($get('/admin/reports', 3)['body'], 'href="/admin/providers/' . $providerId . '"'));
$firstReport = (int) $pdo->query('SELECT MIN(id) FROM report')->fetchColumn();
$post('/admin/reports/' . ($firstReport + 2) . '/decide', ['decision' => 'rejected', 'note' => 'Kein Verstoß.'], 3);
check('report: a provider is not told about a notice that led to nothing', lastMail($mailLog, 'plain@example.test')['subject'] !== 'Maßnahme zu einem deiner Inhalte');
$post('/admin/reports/' . $firstReport . '/decide', ['decision' => 'actioned', 'note' => 'Das Angebot wurde pausiert.'], 3);
$log = (string) file_get_contents($mailLog);
$providerMail = substr($log, (int) strrpos($log, 'To: plain@example.test'));
check('report: the provider is told about action and its reasons, not who reported', lastMail($mailLog, 'plain@example.test')['subject'] === 'Maßnahme zu einem deiner Inhalte' && str_contains($providerMail, 'Das Angebot wurde pausiert.')
    && !str_contains($providerMail, 'Erika') && !str_contains($providerMail, 'erika@example.test'));
$pdo->exec('DELETE FROM report');
$pdo->exec('DELETE FROM rate_limit_attempt');

$get('/offers/ich-gestalte-dein-logo', 2);
$r = $get('/account');
check('overview: what was looked at last is shown, from the visitor\'s own session', str_contains($r['body'], 'Zuletzt angesehen') && ($_SESSION['recent_offers'] ?? []) === [$offerId] && !str_contains($get('/account', 3)['body'], 'Zuletzt angesehen'));
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
check('notification: an order state change notifies the buyer (who did not act), not the provider who did', $pdo->query('SELECT account_id, type FROM notification ORDER BY id DESC LIMIT 1')->fetch() == ['account_id' => 2, 'type' => 'order_state']);
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

// --- Registrar::checkoutSection() / Registrar::orderDiscount() -------------
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'example'");
$r = $get($orderPath . '?package=1', 2);
check('checkoutSection(): the extension\'s block is rendered on the order form', str_contains($r['body'], 'Vom Beispiel-Checkout-Block') && str_contains($r['body'], 'name="example_discount_code"'));

$beforeDiscount = $lastOrder();
$r = $post($orderPath, ['package' => '1', 'example_discount_code' => 'nope'], 2);
check('orderDiscount(): an invalid code refuses the order, with the extension\'s own message', $lastOrder() === $beforeDiscount && str_contains($r['body'], 'Dieser Rabattcode ist ungültig.'));

$post($orderPath, ['package' => '1'], 2);
$withoutCode = $lastOrder();
check('orderDiscount(): no code, the price is untouched', (int) $orderRow($withoutCode)['total'] === 4990);

$post($orderPath, ['package' => '1', 'example_discount_code' => 'EXAMPLE10'], 2);
$withCode = $lastOrder();
check('orderDiscount(): a valid code reduces the line\'s unit price, prorated so it stays unsigned', (int) $orderRow($withCode)['total'] === 4491);

$pdo->exec("DELETE FROM orders WHERE id IN ({$withoutCode}, {$withCode})");
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'example'");
check('checkoutSection(): disabled again, the block is gone', !str_contains($get($orderPath . '?package=1', 2)['body'], 'Vom Beispiel-Checkout-Block'));

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

// --- Message moderation: every message visible to the administration, the word filter flags instead of blocking ---
$orderMessage = fn (int $id) => $pdo->query("SELECT * FROM order_message WHERE id = {$id}")->fetch();

check('messages administration needs its permission', $get('/admin/messages', 2)['status'] === 403);

$beforeOrderMessages = (int) $pdo->query('SELECT COUNT(*) FROM order_message')->fetchColumn();
$post('/orders/' . $fileOrder . '/message', ['body' => 'Du bist echt ein Arschloch, das muss mal gesagt werden.'], 2);
$flaggedOrderMsgId = (int) $pdo->query('SELECT id FROM order_message ORDER BY id DESC LIMIT 1')->fetchColumn();
check('order message: the word filter flags instead of refusing, the message is delivered as usual', $pdo->query('SELECT COUNT(*) FROM order_message')->fetchColumn() == $beforeOrderMessages + 1
    && $orderMessage($flaggedOrderMsgId)['flagged_word'] === 'arschloch' && $orderMessage($flaggedOrderMsgId)['status'] === 'visible'
    && lastMail($mailLog, 'plain@example.test')['subject'] === 'Neue Nachricht zur Bestellung ' . sprintf('%06d', $fileOrder));

$r = $get('/admin/messages', 3);
check('messages administration: order tab is the default, shows the flagged message with its order', $r['status'] === 200
    && str_contains($r['body'], 'Arschloch') && str_contains($r['body'], 'Markiert') && str_contains($r['body'], '/admin/orders/' . $fileOrder));
$r = $get('/admin/messages?status=pending', 3);
check('messages administration: "flagged only" filters down to it', str_contains($r['body'], 'Arschloch'));
$r = $get('/admin/messages?tab=offer', 3);
check('messages administration: the offer tab shows the earlier flagged offer question, not order messages', str_contains($r['body'], 'Arschloch') && !str_contains($r['body'], '/admin/orders/' . $fileOrder));

$post('/admin/messages/order/' . $flaggedOrderMsgId . '/dismiss', [], 2);
check('dismissing needs the permission', $orderMessage($flaggedOrderMsgId)['decided_at'] === null);
$post('/admin/messages/order/' . $flaggedOrderMsgId . '/dismiss', [], 3);
check('dismissing clears the flag, content and visibility stay as they are', $orderMessage($flaggedOrderMsgId)['decided_at'] !== null && $orderMessage($flaggedOrderMsgId)['status'] === 'visible'
    && !str_contains($get('/admin/messages?status=pending', 3)['body'], 'Arschloch')
    && str_contains($get('/orders/' . $fileOrder, 2)['body'], 'Arschloch'));

$post('/admin/messages/order/' . $flaggedOrderMsgId . '/hide', [], 3);
check('hiding removes the content from both parties, not from the administration', $orderMessage($flaggedOrderMsgId)['status'] === 'hidden'
    && !str_contains($get('/orders/' . $fileOrder, 1)['body'], 'Arschloch') && !str_contains($get('/orders/' . $fileOrder, 2)['body'], 'Arschloch')
    && str_contains($get('/orders/' . $fileOrder, 2)['body'], 'ausgeblendet')
    && str_contains($get('/admin/orders/' . $fileOrder, 3)['body'], 'Arschloch') && str_contains($get('/admin/messages', 3)['body'], 'Arschloch'));
$post('/admin/messages/order/' . $flaggedOrderMsgId . '/show', [], 3);
check('showing again brings the content back for both parties', $orderMessage($flaggedOrderMsgId)['status'] === 'visible' && str_contains($get('/orders/' . $fileOrder, 2)['body'], 'Arschloch'));

$offerMessage = fn (int $id) => $pdo->query("SELECT * FROM offer_message WHERE id = {$id}")->fetch();
$flaggedOfferMsgId = (int) $pdo->query("SELECT id FROM offer_message WHERE flagged_word IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn();
$post('/admin/messages/offer/' . $flaggedOfferMsgId . '/hide', [], 3);
check('hiding an offer question removes it from the thread for both the asker and the provider', $offerMessage($flaggedOfferMsgId)['status'] === 'hidden'
    && str_contains($get('/offers/ich-gestalte-dein-logo', 2)['body'], 'ausgeblendet') && !str_contains($get('/offers/ich-gestalte-dein-logo', 2)['body'], 'verdammter Arschloch')
    && str_contains($get('/offers/ich-gestalte-dein-logo', 1)['body'], 'ausgeblendet'));
$post('/admin/messages/offer/' . $flaggedOfferMsgId . '/dismiss', [], 3);
check('dismissing an already-hidden offer question just clears the flag, it stays hidden', $offerMessage($flaggedOfferMsgId)['status'] === 'hidden' && $offerMessage($flaggedOfferMsgId)['decided_at'] !== null);

$r = $get('/admin', 3);
check('dashboard: flagged messages show in the action queue, linking to the flagged filter', str_contains($r['body'], 'href="/admin/messages?status=pending"'));

// --- The inbox: every conversation of the account, mixed and sorted by latest activity ---
check('inbox: the header carries the envelope link and its unread badge', str_contains($get('/account', 1)['body'], 'href="/account/messages"') && str_contains($get('/account', 1)['body'], 'data-unread'));

/** Whether the <li> that contains $marker carries the "is-unread" class - not just any earlier row. */
$rowIsUnread = function (string $body, string $marker): bool {
    $pos = strpos($body, $marker);
    $liPos = strrpos(substr($body, 0, $pos), '<li class="');
    $tagEnd = strpos($body, '>', $liPos);

    return str_contains(substr($body, $liPos, $tagEnd - $liPos), 'is-unread');
};

$post('/orders/' . $fileOrder . '/message', ['body' => 'Letzte Nachricht zur Bestellung, gerade eben.'], 2);
$r = $get('/account/messages', 1);
check('inbox: the newest order message leads the list, for the provider who has not seen it yet', $r['status'] === 200
    && $rowIsUnread($r['body'], 'Letzte Nachricht zur Bestellung, gerade eben.'));
check('notification: a new order message also notifies via the bell (counts in both places, as decided)', $pdo->query('SELECT account_id, type FROM notification ORDER BY id DESC LIMIT 1')->fetch() == ['account_id' => 1, 'type' => 'order_message']);

$post('/offers/ich-gestalte-dein-logo/contact', ['message' => 'Und noch eine ganz neue Frage, gerade erst gestellt.'], 2);
check('notification: a new offer question notifies the provider via the bell too', $pdo->query('SELECT account_id, type FROM notification ORDER BY id DESC LIMIT 1')->fetch() == ['account_id' => 1, 'type' => 'offer_message']);
// Both posts can land within the same wall-clock second; the test needs a
// deterministic order, so the offer message is nudged one second later.
$pdo->exec("UPDATE offer_message SET created_at = datetime(created_at, '+1 second') WHERE body = 'Und noch eine ganz neue Frage, gerade erst gestellt.'");
$r = $get('/account/messages', 1);
$posOffer = strpos($r['body'], 'Und noch eine ganz neue Frage');
$posOrder = strpos($r['body'], 'Letzte Nachricht zur Bestellung');
check('inbox: order and offer conversations are mixed by recency, not grouped by type', $posOffer !== false && $posOrder !== false && $posOffer < $posOrder);

$get('/orders/' . $fileOrder, 1);
$r = $get('/account/messages', 1);
check('inbox: opening the real order page marks its row read', !$rowIsUnread($r['body'], 'Letzte Nachricht zur Bestellung'));

check('inbox: links to the real pages, where replying already works', str_contains($r['body'], 'href="/orders/' . $fileOrder . '"') && str_contains($r['body'], 'href="/offers/ich-gestalte-dein-logo'));

check('inbox: each row opens in place with its full history and a reply form posting to the same route the real page uses', str_contains($r['body'], '<details>')
    && str_contains($r['body'], 'action="/orders/' . $fileOrder . '/message"') && str_contains($r['body'], 'action="/offers/ich-gestalte-dein-logo/contact/2"')
    && str_contains($r['body'], 'name="return" value="/account/messages"') && str_contains($r['body'], 'Letzte Nachricht zur Bestellung, gerade eben.'));

$post('/orders/' . $fileOrder . '/message', ['body' => 'Antwort direkt aus dem Postfach.', 'return' => '/account/messages'], 1);
check('inbox: replying inline works through the real endpoint and shows up back in the inbox',
    str_contains($get('/account/messages', 1)['body'], 'Antwort direkt aus dem Postfach.'));
$post('/offers/ich-gestalte-dein-logo/contact/2', ['message' => 'ok passt', 'return' => '/account/messages'], 1);
check('inbox: a short reply like "ok passt" (under the old 20-character minimum) goes through the inline form too', str_contains($get('/account/messages', 1)['body'], 'ok passt'));

// --- Notifications: the bell, counting independently from the inbox envelope ---
check('notifications: the header carries the bell and its own unread badge, separate from the inbox one', str_contains($get('/account', 1)['body'], 'href="/account/notifications"') && str_contains($get('/account', 1)['body'], 'data-unread'));
$unreadNotif = fn (int $account) => (int) (json_decode($get('/account/notifications/unread', $account)['body'], true)['count'] ?? -1);
$before1 = $unreadNotif(1);
check('notifications: at least the order message and the offer question above are pending for the provider', $before1 >= 2);
$r = $get('/account/notifications', 1);
check('notifications: the list shows both, rendered as readable text via trans()', str_contains($r['body'], 'Neue Nachricht zur Bestellung') && str_contains($r['body'], 'Neue Frage zu'));
check('notifications: opening the page marks everything read, like the inbox does for its own pages', $unreadNotif(1) === 0);

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

// --- Direct account ratings ---------------------------------------------------------
// Account 1 is the provider behind $providerId, account 2 is the buyer of $orderId/$fileOrder -
// a real order connects them, so AccountRatings::canRate() allows them to rate each other directly.
$accountRating = fn (int $raterId, int $ratedId) => $pdo->query("SELECT * FROM account_rating WHERE rater_id = {$raterId} AND rated_id = {$ratedId}")->fetch();

check('order page offers to rate the account on the other side of the order', str_contains($get('/orders/' . $orderId, 2)['body'], '/accounts/1/rating"') && str_contains($get('/orders/' . $orderId, 1)['body'], '/accounts/2/rating"'));
$post('/accounts/3/rating', ['rating' => '5', 'body' => 'x'], 2);
check('an account with no real order between them cannot be rated', $accountRating(2, 3) === false);
$post('/accounts/2/rating', ['rating' => '5', 'body' => 'x'], 2);
check('rating oneself is refused', $accountRating(2, 2) === false);
$post('/accounts/1/rating', ['rating' => '6', 'body' => ''], 2);
$post('/accounts/1/rating', ['rating' => '0', 'body' => ''], 2);
check('a rating outside 1 to 5 is refused', $accountRating(2, 1) === false);

$post('/accounts/1/rating', ['rating' => '5', 'body' => "Sehr zuverlässig!\n<script>alert(1)</script>"], 2);
check('account rating: stored with the rater\'s display name, counted for the rated account', $accountRating(2, 1)['rating'] == 5 && $accountRating(2, 1)['rater_name'] === 'Erika M.' && $ratingOf('account', 1) === '1/5');
check('the rated account is told', lastMail($mailLog, 'plain@example.test')['subject'] === 'Jemand hat dich direkt bewertet');
check('order page no longer offers the form once rated; the own rating shows instead, escaped', !str_contains($get('/orders/' . $orderId, 2)['body'], '/accounts/1/rating"') && str_contains($get('/orders/' . $orderId, 2)['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'));
$post('/accounts/1/rating', ['rating' => '1', 'body' => 'doch nicht'], 2);
check('an account is rated once', $accountRating(2, 1)['rating'] == 5);

$post('/accounts/2/rating', ['rating' => '4', 'body' => 'Netter Kontakt'], 1);
check('the other side can rate back, independently of the first rating', $accountRating(1, 2)['rating'] == 4 && $ratingOf('account', 2) === '1/4');

check('provider page shows the direct rating of the account behind it, escaped', str_contains($get('/providers/mueller-design', null)['body'], '5,0 von 5 Sternen aus 1 Bewertung') && str_contains($get('/providers/mueller-design', null)['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'));
check('the account/ratings page lets the rated account answer a rating that has none yet', str_contains($get('/account/ratings', 2)['body'], '/account-ratings/' . $accountRating(1, 2)['id'] . '/reply"'));

$ratingId = (int) $accountRating(2, 1)['id'];
check('order page offers to reply to a rating received, not yet replied', str_contains($get('/orders/' . $orderId, 1)['body'], '/account-ratings/' . $ratingId . '/reply"'));
$post('/account-ratings/' . $ratingId . '/reply', ['reply' => 'Danke!'], 2);
check('only the rated account replies', $accountRating(2, 1)['reply'] === null);
$post('/account-ratings/' . $ratingId . '/reply', ['reply' => ''], 1);
check('an empty reply is refused', $accountRating(2, 1)['reply'] === null);
$post('/account-ratings/' . $ratingId . '/reply', ['reply' => 'Danke, gern wieder!'], 1);
check('the rated account replies; the rater is told', $accountRating(2, 1)['reply'] === 'Danke, gern wieder!' && lastMail($mailLog, 'editor@example.test')['subject'] === 'Antwort auf deine Bewertung');
$post('/account-ratings/' . $ratingId . '/reply', ['reply' => 'Noch was'], 1);
check('the reply cannot be replaced', $accountRating(2, 1)['reply'] === 'Danke, gern wieder!');

check('account rating moderation needs its permission', $get('/admin/account-ratings', 2)['status'] === 403 && $get('/admin/account-ratings', 3)['status'] === 200);
$post('/admin/account-ratings/' . $ratingId . '/hide', ['note' => ''], 3);
check('hiding a rating needs a reason', $accountRating(2, 1)['status'] === 'published');
$post('/admin/account-ratings/' . $ratingId . '/hide', ['note' => 'Beleidigung'], 3);
check('hidden rating: gone from public pages and from the numbers, rater told why', $accountRating(2, 1)['status'] === 'hidden' && $ratingOf('account', 1) === '0/0' && lastMail($mailLog, 'editor@example.test')['subject'] === 'Deine Bewertung wurde ausgeblendet');
check('hidden rating: still visible to the rated account on their own page, with the reason', str_contains($get('/account/ratings', 1)['body'], 'ausgeblendet'));
$post('/admin/account-ratings/' . $ratingId . '/show', [], 3);
check('showing a rating again restores the numbers', $accountRating(2, 1)['status'] === 'published' && $ratingOf('account', 1) === '1/5');

$export = json_decode($get('/account/export', 2)['body'], true);
check('export lists the reviews the account wrote', count($export['reviews'] ?? []) === 2);
check('export lists the direct ratings the account gave and received', count($export['account_ratings_given'] ?? []) === 1 && count($export['account_ratings_received'] ?? []) === 1);
(new Modulento\Core\Review\Reviews($pdo, $words()))->anonymise(2);
check('a deleted account\'s reviews stay without the name', $review($orderId)['author_name'] === '' && str_contains($get('/offers/ich-gestalte-dein-logo', null)['body'], 'Ein Käufer'));
(new Modulento\Core\Review\AccountRatings($pdo, $words()))->anonymise(2);
check('a deleted account\'s direct ratings stay without the name', $accountRating(2, 1)['rater_name'] === '' && str_contains($get('/providers/mueller-design', null)['body'], 'Ein Konto'));

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
check('auction: the lot\'s fields are asked for in the wizard, at step five', $r['status'] === 200 && str_contains($r['body'], 'Schritt 1 von 7') && str_contains($wizardSteps('auction.lot', ['start_price' => '10', 'step' => '', 'duration_days' => '3'])['body'], 'name="start_price"') && str_contains($get('/account/offers/new?type=auction.lot&step=5', false)['body'], 'name="duration_days"'));
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
check('notification: the outbid bidder is told in the bell too', $pdo->query('SELECT account_id, type, link FROM notification ORDER BY id DESC LIMIT 1')->fetch() == ['account_id' => 2, 'type' => 'auction_outbid', 'link' => '/offers/alte-kamera']);
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

// --- Requests: a buyer describes what they need, providers apply with their own price ---
$post('/admin/categories/new', ['text' => $name('Webdesign')], 3);
$requestCatId = (int) $pdo->query("SELECT category_id FROM category_translation WHERE slug = 'webdesign'")->fetchColumn();
$insertAccount->execute([8, 'requester@example.test', $testHash, 'active']);
$insertAccount->execute([9, 'provider9@example.test', $testHash, 'active']);
$post('/account/provider', ['type' => 'private', 'name' => 'Neunter Anbieter', 'street' => 'Weg 9', 'postal_code' => '1010', 'city' => 'Wien', 'country' => 'AT'], 9);
$post('/admin/providers/' . $provider(9)['id'] . '/decide', ['decision' => 'approve'], 3);
$providerAId = (int) $provider(1)['id'];
$providerBId = (int) $provider(9)['id'];
$post('/admin/pages/new', ['status' => 'published', 'role' => 'terms', 'text' => ['de' => $text('AGB')]], 3);
check('requests: an approved provider sees "open requests" in the account nav, a plain account sees "my requests"',
    str_contains($get('/account/settings', 1)['body'], 'Offene Anfragen') && !str_contains($get('/account/settings', 1)['body'], 'Meine Anfragen')
    && str_contains($get('/account/settings', 8)['body'], 'Meine Anfragen') && !str_contains($get('/account/settings', 8)['body'], 'Offene Anfragen')
    && str_contains($get('/account', 1)['body'], 'Offene Anfragen') && !str_contains($get('/account', 1)['body'], 'Meine Anfragen')
    && str_contains($get('/account', 8)['body'], 'Meine Anfragen') && !str_contains($get('/account', 8)['body'], 'Offene Anfragen'));

$requestRow = fn (string $where = '1 = 1') => $pdo->query("SELECT * FROM request WHERE {$where} ORDER BY id DESC")->fetch();
$applicationRow = fn (string $where = '1 = 1') => $pdo->query("SELECT * FROM request_application WHERE {$where} ORDER BY id DESC")->fetch();
$requestForm = ['category_id' => (string) $requestCatId, 'title' => 'Website für Fotostudio', 'description' => 'Ich brauche eine kleine Website mit Galerie.', 'budget_min' => '300', 'budget_max' => '600', 'needed_by' => '2026-12-01'];

check('requests: the module is on by default, with its own nav link and admin menu', str_contains($get('/', null)['body'], 'href="/requests"') && str_contains($get('/admin', 3)['body'], 'href="/admin/requests"'));
check('request: needs a login to create one', $get('/account/requests/new', null)['body'] === '');
$post('/account/requests/new', ['title' => '', 'description' => '', 'category_id' => '0'] + $requestForm, 8);
check('request: needs a title, description and category', $requestRow() === false);

$post('/account/requests/new', $requestForm, 8);
$request = $requestRow();
check('request: created pending while approval is required, with its budget converted to minor units and a slug', $request !== false && $request['status'] === 'pending'
    && $request['slug'] === 'website-fuer-fotostudio' && (int) $request['budget_min'] === 30000 && (int) $request['budget_max'] === 60000 && $request['currency'] === 'EUR');
$requestId = (int) $request['id'];
check('requests: the admin dashboard has a tile with the pending count', str_contains($get('/admin', 3)['body'], 'href="/admin/requests?status=pending"'));
check('request: a pending request is not public', $get('/requests/website-fuer-fotostudio', null)['status'] === 404 && !str_contains($get('/requests', null)['body'], 'Website für Fotostudio'));
check('request: someone else cannot edit, delete or see the applications of it', $get('/account/requests/' . $requestId . '/edit', 9)['status'] === 404
    && $post('/account/requests/' . $requestId . '/delete', [], 9)['status'] === 404 && $get('/account/requests/' . $requestId . '/applications', 9)['status'] === 404);

$post('/admin/requests/' . $requestId . '/decide', ['decision' => 'reject', 'note' => ''], 3);
check('request: rejecting needs a reason', $requestRow()['status'] === 'pending');
$post('/admin/requests/' . $requestId . '/decide', ['decision' => 'reject', 'note' => 'Zu wenig Details'], 3);
$mail = lastMail($mailLog, 'requester@example.test');
check('request: rejected with a reason, owner told by mail and in the edit page', $requestRow()['status'] === 'rejected' && $requestRow()['status_note'] === 'Zu wenig Details'
    && $mail['subject'] === 'Deine Anfrage „Website für Fotostudio“ wurde nicht freigegeben' && str_contains($get('/account/requests/' . $requestId . '/edit', 8)['body'], 'Zu wenig Details'));

$post('/account/requests/' . $requestId, $requestForm, 8);
check('request: saving a corrected, rejected request resubmits it for review', $requestRow()['status'] === 'pending' && $requestRow()['status_note'] === null);
$post('/admin/requests/' . $requestId . '/decide', ['decision' => 'approve'], 3);
check('request: approved, owner told, now public with the budget range and an apply form', $requestRow()['status'] === 'published'
    && lastMail($mailLog, 'requester@example.test')['subject'] === 'Deine Anfrage „Website für Fotostudio“ ist freigegeben'
    && str_contains($get('/requests/website-fuer-fotostudio', null)['body'], 'Website für Fotostudio') && str_contains($get('/requests', null)['body'], 'Website für Fotostudio'));
check('requests: the admin dashboard tile has no pending badge once nothing waits', !str_contains($get('/admin', 3)['body'], 'href="/admin/requests?status=pending"'));
$r = $get('/requests/website-fuer-fotostudio', 1);
check('request: a provider sees the apply form, the owner does not, a visitor is sent to log in', str_contains($r['body'], 'name="price"')
    && !str_contains($get('/requests/website-fuer-fotostudio', 8)['body'], 'name="price"') && str_contains($get('/requests/website-fuer-fotostudio', null)['body'], 'Melde dich an'));
$apply = fn (string $price, int $as, string $message = 'Ich würde das gerne machen.') => $post('/requests/website-fuer-fotostudio/apply', ['price' => $price, 'delivery_days' => '5', 'message' => $message], $as);
check('request: applying needs a provider profile', $apply('400', 8)['status'] === 302 && ($_SESSION['_flash']['error'] ?? '') !== '' && $applicationRow() === false);
$post('/account/requests/new', ['category_id' => (string) $requestCatId, 'title' => 'Eigene Anfrage', 'description' => 'Eine Anfrage vom Anbieter selbst.'] + ['budget_min' => '', 'budget_max' => '', 'needed_by' => ''], 1);
$ownRequestId = (int) $requestRow()['id'];
$post('/admin/requests/' . $ownRequestId . '/decide', ['decision' => 'approve'], 3);
$ownSlug = $requestRow()['slug'];
check('request: a provider cannot apply to their own request', $post('/' . 'requests/' . $ownSlug . '/apply', ['price' => '100', 'delivery_days' => '1', 'message' => 'x'], 1)['status'] === 302
    && $applicationRow("request_id = {$ownRequestId}") === false);
$post('/account/requests/' . $ownRequestId . '/delete', [], 1);
$apply('abc', 1);
check('request: the price has to be a real amount', $applicationRow() === false);
$apply('450', 1, 'Ich mache gerne eine schlanke Website.');
$appA = $applicationRow();
check('request: the first application is stored, submitted, the owner is told', $appA !== false && $appA['status'] === 'submitted' && (int) $appA['price'] === 45000
    && (int) $appA['provider_id'] === $providerAId && $pdo->query('SELECT COUNT(*) FROM notification')->fetchColumn() > 0);
$appAId = (int) $appA['id'];
$apply('500', 9, 'Mit CMS und Blog.');
$appB = $applicationRow();
$appBId = (int) $appB['id'];
check('request: a second provider\'s application does not touch the first', (int) $appB['provider_id'] === $providerBId && $appAId !== $appBId && $pdo->query('SELECT COUNT(*) FROM request_application')->fetchColumn() == 2);
$apply('480', 1, 'Doch lieber mit Blog, neuer Preis.');
check('request: a second application from the same provider replaces the first instead of adding a row', $pdo->query('SELECT COUNT(*) FROM request_application')->fetchColumn() == 2
    && (int) $applicationRow("id = {$appAId}")['price'] === 48000);

$r = $get('/account/requests/' . $requestId . '/applications', 8);
check('request: the owner sees both applications with their own price, nobody else\'s application is hidden from them', str_contains($r['body'], '480,00') && str_contains($r['body'], '500,00'));
$r = $get('/account/applications', 9);
check('request: a provider sees their own application in "my applications", with the request\'s currency', str_contains($r['body'], '500,00') && str_contains($r['body'], 'Website für Fotostudio'));
check('request: a provider cannot see another provider\'s application to withdraw it', $post('/account/applications/' . $appAId . '/withdraw', [], 9)['status'] === 302 && $applicationRow("id = {$appAId}")['status'] === 'submitted');

$ordersBefore = $lastOrder();
check('request: accepting needs to be the owner, and the application must still be open', $get('/account/requests/' . $requestId . '/applications/' . $appBId . '/accept', 1)['status'] === 404
    && $get('/account/requests/' . $requestId . '/applications/999999/accept', 8)['status'] === 404);
$r = $get('/account/requests/' . $requestId . '/applications/' . $appBId . '/accept', 8);
check('request: the confirmation page shows the provider, the price and a terms checkbox', str_contains($r['body'], '500,00') && str_contains($r['body'], 'name="accept_terms"'));
$post('/account/requests/' . $requestId . '/applications/' . $appBId . '/accept', [], 8);
check('request: terms must be accepted to go through', $lastOrder() === $ordersBefore && $requestRow()['status'] === 'published');
$post('/account/requests/' . $requestId . '/applications/' . $appBId . '/accept', ['accept_terms' => '1'], 8);
$orderId = $lastOrder();
$order = $orderRow($orderId);
check('request: accepting creates one real order at the application\'s own price, with the one available payment method chosen automatically', $orderId > $ordersBefore
    && (int) $order['buyer_id'] === 8 && (int) $order['provider_id'] === $providerBId && (int) $order['total'] === 50000 && $order['currency'] === 'EUR'
    && $order['flow'] === 'core.request' && $order['state'] === 'in_progress' && $order['payment_method'] === 'core.offline' && $order['terms_accepted_at'] !== null
    && $pdo->query("SELECT label || ':' || quantity || ':' || unit_price FROM order_item WHERE order_id = {$orderId}")->fetchColumn() === 'Website für Fotostudio:1:50000');
check('request: the accepted application is accepted, the other is declined, the request is fulfilled and points at the order', $applicationRow("id = {$appBId}")['status'] === 'accepted'
    && $applicationRow("id = {$appAId}")['status'] === 'declined' && $requestRow()['status'] === 'fulfilled' && (int) $requestRow()['order_id'] === $orderId
    && (int) $requestRow()['accepted_application_id'] === $appBId);
check('request: the declined provider is notified in-app, exactly once', (int) $pdo->query("SELECT account_id FROM notification WHERE type = 'request_declined' ORDER BY id DESC LIMIT 1")->fetchColumn() === 1
    && $pdo->query("SELECT COUNT(*) FROM notification WHERE type = 'request_declined'")->fetchColumn() == 1);
check('request: a fulfilled request leaves public view and no longer takes applications, but is not deleted', $get('/requests/website-fuer-fotostudio', 1)['status'] === 404
    && !str_contains($get('/requests', null)['body'], 'Website für Fotostudio') && $apply('999', 1)['status'] === 404
    && $post('/account/requests/' . $requestId . '/delete', [], 8)['status'] === 302 && $requestRow() !== false);

$r = $get('/orders/' . $orderId, 8);
check('request: the order page links back to the request it came from', str_contains($r['body'], 'website-fuer-fotostudio') && str_contains($r['body'], 'Website für Fotostudio'));
check('request: the generic order routes carry the flow onward - no request-specific code was needed for this', $act($orderId, 'deliver', 9, 'Fertig, bitte ansehen.')['status'] === 302 && $orderRow($orderId)['state'] === 'delivered');
$act($orderId, 'accept_delivery', 9);
check('request: only the buyer accepts the delivery', $orderRow($orderId)['state'] === 'delivered');
$act($orderId, 'accept_delivery', 8);
check('request: completed, and open for a review despite having no catalogue offer behind it', $orderRow($orderId)['state'] === 'completed' && str_contains($get('/orders/' . $orderId, 8)['body'], 'name="rating"'));
$post('/orders/' . $orderId . '/review', ['rating' => '5', 'body' => 'Alles bestens, danke!'], 8);
$review = $pdo->query("SELECT * FROM review WHERE order_id = {$orderId}")->fetch();
check('request: a review is stored with the provider, but no offer, behind it', $review !== false && (int) $review['provider_id'] === $providerBId && $review['offer_id'] === null
    && (int) $pdo->query("SELECT rating_count FROM provider WHERE id = {$providerBId}")->fetchColumn() === 1);

// A subscription bonus: a provider whose plan grants core.request.notify
// hears about a newly published request at once, another provider without
// that plan does not.
$notifyPlan = (function () use ($pdo): int {
    $pdo->prepare("INSERT INTO subscription_plan (slug, name, price_cents, currency, period_months, features, offer_limits, max_images_per_offer, active)
        VALUES ('abo-request-notify', 'Anfragen-Alarm', 300, 'EUR', 1, 'core.request.notify', '{}', NULL, 1)")->execute();

    return (int) $pdo->lastInsertId();
})();
$assignPlan(9, $notifyPlan);
Modulento\Example\Extension::$subscriptionProvider = new TestSubscriptionProvider($pdo);
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'example'");
$post('/admin/settings', $settings + ['request_approval' => 'off'], 3);
$post('/account/requests/new', ['category_id' => (string) $requestCatId, 'title' => 'Logo gesucht', 'description' => 'Ein einfaches Logo bitte.'], 8);
check('request: with approval switched off, a new request is public at once', $requestRow()['status'] === 'published' && $requestRow()['published_at'] !== null);
check('request: a subscribed provider with the notify feature hears about it at once, one without the plan does not',
    $pdo->query("SELECT COUNT(*) FROM notification WHERE type = 'request_new' AND account_id = 9")->fetchColumn() == 1
    && $pdo->query("SELECT COUNT(*) FROM notification WHERE type = 'request_new' AND account_id = 1")->fetchColumn() == 0);
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'example'");
Modulento\Example\Extension::$subscriptionProvider = null;
$pdo->exec("DELETE FROM subscription WHERE plan_id = $notifyPlan");
$pdo->exec("DELETE FROM subscription_plan WHERE id = $notifyPlan");
$post('/admin/settings', $settings + ['request_approval' => 'required'], 3);
$post('/account/requests/' . (int) $requestRow()['id'] . '/delete', [], 8);
$pdo->exec('DELETE FROM request');
$pdo->exec('DELETE FROM request_application');
$pdo->exec("DELETE FROM orders WHERE id = {$orderId}");
$pdo->exec("DELETE FROM category WHERE id = {$requestCatId}");
$pdo->exec('DELETE FROM account WHERE id IN (8, 9)');
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
// The dashboard's action queue shows an area only while something of it is
// actually pending - nothing to review means no entry, not an empty figure.
$accounts->create('queue-test@example.test', $pw, 'de', verified: true);
$queueAccountId = (int) $row('queue-test@example.test')['id'];
$pdo->exec("INSERT INTO provider (id, account_id, type, status, name, slug, created_at, updated_at)
    VALUES (950, {$queueAccountId}, 'private', 'pending', 'Wartender Anbieter', 'wartender-anbieter', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$queueEntry = fn (string $body, string $path): bool => str_contains($body, 'href="' . $path . '?status=pending"');
$r = $get('/admin', 3);
check('dashboard: the action queue shows an area with something pending', $r['status'] === 200 && $queueEntry($r['body'], '/admin/providers'));
check('dashboard: an area with nothing pending has no entry at all', !$queueEntry($r['body'], '/admin/reviews') && !$queueEntry($r['body'], '/admin/offers'));
$pdo->exec("INSERT INTO role_permission VALUES ({$roleId}, 'core.admin.access'), ({$roleId}, 'core.orders.manage')");
$r = $get('/admin', 1);
check('dashboard: the queue only shows areas the account may manage, pending or not', $r['status'] === 200 && !$queueEntry($r['body'], '/admin/providers'));
$pdo->exec("DELETE FROM provider WHERE id = 950");
$pdo->exec("DELETE FROM account WHERE email = 'queue-test@example.test'");
$pdo->exec("DELETE FROM role_permission WHERE role_id = {$roleId} AND permission IN ('core.admin.access', 'core.orders.manage')");

// --- Dashboard: quick actions and the system health panel -----------------------
$r = $get('/admin', 3);
check('dashboard: an administrator sees every quick action', str_contains($r['body'], 'href="/admin/pages/new"') && str_contains($r['body'], 'href="/admin/accounts/new"')
    && str_contains($r['body'], 'href="/admin/media"') && str_contains($r['body'], 'href="/admin/settings"'));
$accountCount = (int) $pdo->query('SELECT COUNT(*) FROM account')->fetchColumn();
check('dashboard: system health shows the dev version, the real account count, and no app while none is enabled',
    str_contains($r['body'], '>0.0.0<') && str_contains($r['body'], '>' . $accountCount . '<') && str_contains($r['body'], 'Keine App aktiv'));
check('dashboard: no cron run yet says so', str_contains($r['body'], 'noch nie'));
$extensionBefore = $pdo->query("SELECT * FROM extension WHERE id = 'freelancer'")->fetch();
$pdo->exec("DELETE FROM extension WHERE id = 'freelancer'");
$pdo->exec("INSERT INTO extension VALUES ('freelancer', '0.1.0', 1)");
check('dashboard: an enabled app is named', str_contains($get('/admin', 3)['body'], 'freelancer'));
$pdo->exec("INSERT INTO task_run (name, last_finished_at, last_status) VALUES ('core.notifications.send', '2026-06-01 08:00:00', 'ok')");
check('dashboard: the last cron run is shown once a task has run', str_contains($get('/admin', 3)['body'], '2026-06-01 08:00:00'));
$pdo->exec("DELETE FROM task_run");
$pdo->exec("DELETE FROM extension WHERE id = 'freelancer'");
if ($extensionBefore !== false) {
    $pdo->prepare('INSERT INTO extension VALUES (?, ?, ?)')->execute(array_values($extensionBefore));
}
check('dashboard: an account without quick-action permissions sees none of them', !str_contains($get('/admin', 1)['body'], 'href="/admin/settings"') && !str_contains($get('/admin', 1)['body'], 'href="/admin/accounts/new"'));
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

check('withdrawal: no link where nothing can be ordered', !str_contains($get('/', null)['body'], 'href="/withdrawal"'));
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'freelancer'");
check('withdrawal: the footer of every page links to the form', str_contains($get('/', null)['body'], '<a href="/withdrawal">Vertrag widerrufen</a>')
    && str_contains($get('/login', null)['body'], '<a href="/withdrawal">Vertrag widerrufen</a>'));
check('withdrawal: the link in another language', str_contains($get('/en', null)['body'], '<a href="/en/withdrawal">Withdraw from contract</a>'));
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'freelancer'");
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
check('withdrawal: the administration lists every declaration with its order', $r['status'] === 200 && str_contains($r['body'], 'Erika Muster') && str_contains($r['body'], 'href="/admin/orders/9001"'));
check('withdrawal: the list shows what could not be assigned', str_contains($r['body'], 'nicht zugeordnet') && str_contains($r['body'], 'stranger@example.test'));
// The time of a row that is in the list now - not of one deleted further up.
check('withdrawal: the list shows when a declaration arrived', str_contains($r['body'], $withdrawals()[0]['created_at'] . ' UTC'));
check('withdrawal: the list escapes what was typed', str_contains($r['body'], '&lt;b&gt;fett&lt;/b&gt;') && !str_contains($r['body'], '<b>fett'));
check('withdrawal: the list is marked in the menu', str_contains($r['body'], 'href="/admin/withdrawals" aria-current="page"'));
$export = json_decode($get('/account/export', 2)['body'], true);
check('withdrawal: an account\'s declarations are part of its data', count($export['withdrawals'] ?? []) >= 2 && ($export['withdrawals'][0]['order_number'] ?? '') === '009001'
    && ($export['withdrawals'][0]['matched'] ?? null) === true && !isset(json_decode($get('/account/export', 1)['body'], true)['withdrawals']));
$pdo->exec('DELETE FROM orders WHERE id = 9001');
check('withdrawal: a declaration outlives its order', count($withdrawals()) === $before + 1 && $withdrawals()[0]['order_id'] === null && (int) $withdrawals()[0]['matched'] === 1
    && str_contains($get('/admin/withdrawals', 3)['body'], 'Bestellung gelöscht'));

$open = (int) $pdo->query('SELECT id FROM withdrawal WHERE order_id IS NULL ORDER BY id LIMIT 1')->fetchColumn();
$r = $get('/admin/withdrawals', 3);
check('withdrawal: the administration shows what still has to be passed on', str_contains($r['body'], 'warten auf Weiterleitung') && str_contains($r['body'], '/admin/withdrawals/' . $open . '/handled'));
check('withdrawal: ticking one off needs the permission', $post('/admin/withdrawals/' . $open . '/handled', ['handled' => '1'], 2)['status'] === 403);
$waitingBefore = (int) $pdo->query('SELECT COUNT(*) FROM withdrawal WHERE order_id IS NULL AND handled_at IS NULL')->fetchColumn();
$post('/admin/withdrawals/' . $open . '/handled', ['handled' => '1'], 3);
$handled = $pdo->query("SELECT handled_at, handled_by FROM withdrawal WHERE id = {$open}")->fetch();
check('withdrawal: a declaration is ticked off with time and administrator', $handled['handled_at'] !== null && (int) $handled['handled_by'] === 3
    && (int) $pdo->query('SELECT COUNT(*) FROM withdrawal WHERE order_id IS NULL AND handled_at IS NULL')->fetchColumn() === $waitingBefore - 1 && str_contains($get('/admin/withdrawals', 3)['body'], 'erledigt am'));
$post('/admin/withdrawals/' . $open . '/handled', ['handled' => '0'], 3);
check('withdrawal: and can be reopened', $pdo->query("SELECT handled_at FROM withdrawal WHERE id = {$open}")->fetchColumn() === null);

$pdo->exec('DELETE FROM withdrawal');
$pdo->exec('DELETE FROM provider');
$pdo->exec("UPDATE account SET locale = 'de' WHERE id = 1");
$pdo->exec('DELETE FROM rate_limit_attempt');
@unlink($mailLog);

// --- Reporting illegal content --------------------------------------------------
$reports = fn (): array => $pdo->query('SELECT * FROM report ORDER BY id')->fetchAll();
$notice = ['url' => 'https://example.test/offers/gefaelschte-uhr', 'category' => 'intellectual_property', 'explanation' => 'Das ist eine Fälschung einer geschützten Marke. <script>x</script>',
    'name' => 'Erika Muster', 'email' => 'Erika@Example.test', 'good_faith' => '1'];
@unlink($mailLog);
check('report: the footer of every page links to the form', str_contains($get('/', null)['body'], '<a href="/report">Inhalt melden</a>') && str_contains($get('/en', null)['body'], '<a href="/en/report">Report content</a>'));
$r = $get('/report?url=' . rawurlencode('/offers/gefaelschte-uhr'), null);
check('report: the form is public and takes the address along', $r['status'] === 200 && str_contains($r['body'], 'value="/offers/gefaelschte-uhr"') && str_contains($r['body'], 'name="good_faith"') && str_contains($r['body'], 'name="website"'));
check('report: a query parameter that is not text is ignored', $get('/report?url[]=x', null)['status'] === 200);
check('report: a logged-in account finds name and address filled in', str_contains($get('/report', 2)['body'], 'value="editor@example.test"'));
$r = $post('/report', ['url' => '', 'category' => 'nonsense', 'explanation' => 'kurz', 'name' => '', 'email' => 'x'], null);
check('report: every field is checked', $reports() === [] && str_contains($r['body'], 'Adresse der Seite an') && str_contains($r['body'], 'worum es geht') && str_contains($r['body'], 'mindestens 20')
    && str_contains($r['body'], 'deinen Namen') && str_contains($r['body'], 'gültige E-Mail-Adresse') && str_contains($r['body'], 'nach bestem Wissen richtig'));
$r = $post('/report', ['url' => ['x'], 'category' => ['y'], 'explanation' => ['z'], 'name' => ['n'], 'email' => ['e'], 'good_faith' => ['1']], null);
check('report: fields that are not text count as empty', $r['status'] === 200 && $reports() === []);
$r = $post('/report', array_diff_key($notice, ['good_faith' => 1]), null);
check('report: the statement of good faith is required, typed values stay', $reports() === [] && str_contains($r['body'], 'gefaelschte-uhr') && str_contains($r['body'], '&lt;script&gt;'));
$get('/report', null);
$r = $post('/report', $notice);
check('report: a form sent back the moment it was shown stores nothing', $reports() === [] && str_contains($r['body'], 'Das ging sehr schnell'));
$post('/report', ['website' => 'http://spam'] + $notice, null);
check('report: a filled bot trap gets the same answer and stores nothing', $reports() === [] && lastMail($mailLog, 'erika@example.test') === null && str_contains($get('/report/done')['body'], 'eingegangen'));

$post('/report', $notice, null);
$stored = $reports()[0] ?? [];
check('report: stored as open with the address normalised', count($reports()) === 1 && $stored['status'] === 'open' && $stored['email'] === 'erika@example.test' && $stored['category'] === 'intellectual_property' && $stored['account_id'] === null);
$r = $get('/report/done');
check('report: the answer names time and address', str_contains($r['body'], $stored['created_at'] . ' UTC') && str_contains($r['body'], 'erika@example.test'));
$mail = lastMail($mailLog, 'erika@example.test');
check('report: the sender gets a confirmation of receipt with reference', $mail['subject'] === 'Deine Meldung ' . $stored['id'] . ' ist eingegangen' && str_contains((string) file_get_contents($mailLog), 'Thema: Urheber- oder Markenrecht'));
check('report: the administrators are told, with the way to the list', lastMail($mailLog, 'admin@example.test')['subject'] === 'Neue Meldung ' . $stored['id'] . ' zu einem Inhalt' && lastMail($mailLog, 'admin@example.test')['link'] === '/admin/reports');
$post('/report', ['email' => 'editor@example.test'] + $notice, 2);
check('report: a logged-in sender is remembered for the data export', (int) $reports()[1]['account_id'] === 2 && count(json_decode($get('/account/export', 2)['body'], true)['reports'] ?? []) === 1);

check('report: the list in the administration needs its permission', $get('/admin/reports', 1)['status'] === 403 && $post('/admin/reports/' . $stored['id'] . '/decide', ['decision' => 'rejected', 'note' => 'x'], 1)['status'] === 403);
$r = $get('/admin/reports', 3);
check('report: the administration lists open notices, text escaped', $r['status'] === 200 && str_contains($r['body'], '2 offen') && str_contains($r['body'], 'gefaelschte-uhr') && str_contains($r['body'], '&lt;script&gt;') && !str_contains($r['body'], '<script>x'));
$post('/admin/reports/' . $stored['id'] . '/decide', ['decision' => 'actioned', 'note' => ''], 3);
$post('/admin/reports/' . $stored['id'] . '/decide', ['decision' => 'deleted', 'note' => 'Begründung'], 3);
check('report: a decision needs reasons and a known outcome', $reports()[0]['status'] === 'open');
$post('/admin/reports/' . $stored['id'] . '/decide', ['decision' => 'actioned', 'note' => 'Das Angebot wurde entfernt.'], 3);
$decided = $reports()[0];
check('report: decided with reasons, time and administrator', $decided['status'] === 'actioned' && $decided['decision_note'] === 'Das Angebot wurde entfernt.' && $decided['decided_at'] !== null && (int) $decided['decided_by'] === 3);
$log = (string) file_get_contents($mailLog);
check('report: the sender is told the decision, its reasons and the ways to object', lastMail($mailLog, 'erika@example.test')['subject'] === 'Entscheidung zu deiner Meldung ' . $stored['id']
    && str_contains($log, 'Wir haben eine Maßnahme gegen den Inhalt getroffen') && str_contains($log, 'Das Angebot wurde entfernt.') && str_contains($log, 'Streitbeilegung'));
$mailsBefore = substr_count($log, 'To: erika@example.test');
$post('/admin/reports/' . $stored['id'] . '/decide', ['decision' => 'rejected', 'note' => 'Doch nicht.'], 3);
check('report: a decided notice is not decided again', $reports()[0]['status'] === 'actioned' && substr_count((string) file_get_contents($mailLog), 'To: erika@example.test') === $mailsBefore);

$pdo->exec('DELETE FROM rate_limit_attempt');
$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
$before = count($reports());
for ($i = 0; $i < 6; $i++) {
    $r = $post('/report', ['email' => "reporter{$i}@example.test"] + $notice, null);
}
unset($_SERVER['REMOTE_ADDR']);
check('report: no more than five an hour from one address of the network', count($reports()) === $before + 5 && str_contains($r['body'], 'Zu viele'));
$pdo->exec('DELETE FROM report');
$pdo->exec('DELETE FROM rate_limit_attempt');
@unlink($mailLog);

// --- Payments: transfer, PayPal and Stripe, always straight to the provider -----------
// No check here reaches the network: the payment services are replaced by
// something that records what would have been sent and answers from a script.
$http = new class implements Modulento\Core\Support\HttpClient {
    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];
    /** @var array<int, array{status: int, body: string}> */
    private array $answers = [];

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        // Nothing scripted: as if the service did not answer in time.
        return array_shift($this->answers) ?? ['status' => 0, 'body' => ''];
    }

    public function answer(int $status, array|string $body): void
    {
        $this->answers[] = ['status' => $status, 'body' => is_array($body) ? (string) json_encode($body) : $body];
    }

    public function reset(): void
    {
        $this->requests = [];
        $this->answers = [];
    }
};
$payLog = sys_get_temp_dir() . '/modulento-test-paylog-' . bin2hex(random_bytes(4)) . '.log';
ini_set('error_log', $payLog);
$keyPath = sys_get_temp_dir() . '/modulento-test-key-' . bin2hex(random_bytes(4)) . '/secret.key';
$payConfig = $config;
$payConfig['app']['secret_key'] = $keyPath;
$payConfig['payment'] = ['http' => $http];
$pPost = fn (string $path, array $fields, ?int $as) => request($pdo, $payConfig, 'POST', $path, $as, $fields);
$pGet = fn (string $path, ?int $as) => request($pdo, $payConfig, 'GET', $path, $as);
$flash = fn (string $type = 'error') => (string) ($_SESSION['_flash'][$type] ?? '');
$payApp = function () use ($pdo, $payConfig): Modulento\Core\App {
    $_SESSION = [];
    $app = new Modulento\Core\App($payConfig, $pdo);
    $app->translator->load($payConfig['app']['root'] . '/core/lang', 'core');
    Modulento\Core\Kernel::registerCore($app);
    $app->extensions->loadEnabled($app);
    Modulento\Core\Kernel::registerLast($app);
    Modulento\Core\Kernel::loadThemeTexts($app);
    Modulento\Core\Kernel::prepareRequest($app, '/', startSession: false);

    return $app;
};
$stripeKey = 'sk_test_SECRETKEY1234abcd';
$webhookSecret = 'whsec_testSigningSecret5678';
$paypalSecret = 'paypal-secret-XYZ-987';

// Encryption: the key is a file, never the database.
$secrets = new Modulento\Core\Support\Secrets($keyPath);
check('secrets: nothing is decrypted and no key is made by reading alone', $secrets->decrypt('v1:' . base64_encode(str_repeat('x', 60))) === null && !is_file($keyPath));
$sealed = $secrets->encrypt($stripeKey);
check('secrets: the key file appears on first need, 32 bytes, readable by its owner only', is_file($keyPath) && filesize($keyPath) === 32 && (fileperms($keyPath) & 0777) === 0600);
check('secrets: what is stored does not contain the secret and differs every time', !str_contains($sealed, $stripeKey) && !str_contains((string) base64_decode(substr($sealed, 3)), $stripeKey)
    && $sealed !== $secrets->encrypt($stripeKey) && str_starts_with($sealed, 'v1:'));
check('secrets: round trip, also with a fresh object reading the same key file', $secrets->decrypt($sealed) === $stripeKey && (new Modulento\Core\Support\Secrets($keyPath))->decrypt($sealed) === $stripeKey);
$tampered = substr($sealed, 0, -6) . (substr($sealed, -6, 1) === 'A' ? 'B' : 'A') . substr($sealed, -5);
check('secrets: a changed value, a plain value and another installation\'s key open nothing', $secrets->decrypt($tampered) === null && $secrets->decrypt($stripeKey) === null && $secrets->decrypt('') === null
    && (new Modulento\Core\Support\Secrets(dirname($keyPath) . '/other.key'))->decrypt($sealed) === null && !is_file(dirname($keyPath) . '/other.key'));

// Bank details.
$bank = Modulento\Core\Payment\BankAccount::class;
check('iban: valid ones pass, written with or without spaces and in any case', $bank::isIban($bank::normalize('DE89 3704 0044 0532 0130 00')) && $bank::isIban($bank::normalize('at61 1904 3002 3457 3201'))
    && $bank::isIban('GB82WEST12345698765432') && $bank::normalize(" de89\t3704 ") === 'DE893704');
check('iban: a wrong check sum, a swapped digit and nonsense are refused', !$bank::isIban('DE89370400440532013001') && !$bank::isIban('DE89370400440532010300') && !$bank::isIban('DE00370400440532013000')
    && !$bank::isIban('DE89') && !$bank::isIban('') && !$bank::isIban('1234567890123456') && !$bank::isIban('DE89 3704 0044 0532 0130 00'));
check('bic: eight or eleven characters', $bank::isBic('COBADEFF') && $bank::isBic('COBADEFFXXX') && !$bank::isBic('COBADEF') && !$bank::isBic('COBADEFFXX') && !$bank::isBic('1OBADEFF'));
check('iban: shown in groups of four', $bank::formatIban('DE89370400440532013000') === 'DE89 3704 0044 0532 0130 00');

// Amounts for PayPal are decimal strings, made and read without floats.
$paypalClass = Modulento\Core\Payment\PaypalGateway::class;
check('paypal amounts: minor units to decimal and back', $paypalClass::decimal(11900) === '119.00' && $paypalClass::decimal(5) === '0.05' && $paypalClass::decimal(100050) === '1000.50'
    && $paypalClass::minorUnits('119.00') === 11900 && $paypalClass::minorUnits('0.05') === 5 && $paypalClass::minorUnits('119.0') === null && $paypalClass::minorUnits('119,00') === null
    && $paypalClass::minorUnits('119') === null && $paypalClass::minorUnits('-1.00') === null);

// Stripe's webhook signature.
$stripeClass = Modulento\Core\Payment\StripeGateway::class;
$sign = fn (string $payload, string $secret, ?int $at = null) => 't=' . ($at ??= time()) . ',v1=' . hash_hmac('sha256', $at . '.' . $payload, $secret);
$event = fn (string $session, int $amount, string $currency = 'eur', string $status = 'paid', string $type = 'checkout.session.completed') => (string) json_encode([
    'id' => 'evt_1', 'type' => $type, 'account' => 'acct_1TEST',
    'data' => ['object' => ['id' => $session, 'object' => 'checkout.session', 'payment_status' => $status, 'amount_total' => $amount, 'currency' => $currency]],
]);
$body = $event('cs_test_X', 11900);
check('webhook signature: a correct one is accepted and yields the event', ($stripeClass::verifyWebhook($body, $sign($body, $webhookSecret), $webhookSecret)['type'] ?? null) === 'checkout.session.completed');
check('webhook signature: one of several v1 values may fit', $stripeClass::verifyWebhook($body, 't=' . time() . ',v1=' . str_repeat('0', 64) . ',v1=' . hash_hmac('sha256', time() . '.' . $body, $webhookSecret), $webhookSecret) !== null);
check('webhook signature: another secret, a changed body, a missing or malformed header are refused', $stripeClass::verifyWebhook($body, $sign($body, 'whsec_other'), $webhookSecret) === null
    && $stripeClass::verifyWebhook($body . ' ', $sign($body, $webhookSecret), $webhookSecret) === null && $stripeClass::verifyWebhook($body, '', $webhookSecret) === null
    && $stripeClass::verifyWebhook($body, 'v1=' . hash_hmac('sha256', '.' . $body, $webhookSecret), $webhookSecret) === null && $stripeClass::verifyWebhook($body, 't=abc,v1=abc', $webhookSecret) === null
    && $stripeClass::verifyWebhook($body, $sign($body, ''), '') === null);
check('webhook signature: older or newer than five minutes is refused', $stripeClass::verifyWebhook($body, $sign($body, $webhookSecret, time() - 301), $webhookSecret) === null
    && $stripeClass::verifyWebhook($body, $sign($body, $webhookSecret, time() + 301), $webhookSecret) === null && $stripeClass::verifyWebhook($body, $sign($body, $webhookSecret, time() - 290), $webhookSecret) !== null);

// What is sent to Stripe, and how its answers are read.
$refused = function (callable $call): ?string {
    try {
        $call();
    } catch (Modulento\Core\Payment\PaymentException $e) {
        return $e->messageKey . '|' . $e->getMessage();
    }

    return null;
};
$stripe = new Modulento\Core\Payment\StripeGateway($http, $stripeKey);
$http->answer(200, ['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']);
$session = $stripe->createCheckoutSession('acct_1TEST', 11900, 'EUR', 'Logo – 000012', 12, 'https://example.test/ok', 'https://example.test/back');
$sent = $http->requests[0];
parse_str((string) $sent['body'], $fields);
check('stripe checkout: a direct charge on the connected account, authorised with the platform key', $session === ['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']
    && $sent['method'] === 'POST' && $sent['url'] === 'https://api.stripe.com/v1/checkout/sessions' && $sent['headers']['Stripe-Account'] === 'acct_1TEST'
    && $sent['headers']['Authorization'] === 'Bearer ' . $stripeKey && $sent['headers']['Content-Type'] === 'application/x-www-form-urlencoded');
check('stripe checkout: one position in minor units, the order as reference, no fee for the platform', $fields['mode'] === 'payment' && count($fields['line_items']) === 1
    && $fields['line_items'][0]['price_data'] === ['currency' => 'eur', 'unit_amount' => '11900', 'product_data' => ['name' => 'Logo – 000012']] && $fields['line_items'][0]['quantity'] === '1'
    && $fields['client_reference_id'] === '12' && $fields['metadata'] === ['order_id' => '12'] && $fields['success_url'] === 'https://example.test/ok' && $fields['cancel_url'] === 'https://example.test/back'
    && !str_contains((string) $sent['body'], 'application_fee') && !str_contains((string) $sent['body'], 'transfer_data'));
$http->reset();
$http->answer(200, ['id' => 'acct_1NEW', 'charges_enabled' => false]);
$http->answer(200, ['url' => 'https://connect.stripe.com/setup/s/abc']);
$http->answer(200, ['id' => 'acct_1NEW', 'charges_enabled' => true]);
$http->answer(200, ['id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => 11900, 'currency' => 'eur']);
$created = $stripe->createAccount();
$link = $stripe->accountLink('acct_1NEW', 'https://example.test/again', 'https://example.test/done');
parse_str((string) $http->requests[1]['body'], $fields);
check('stripe connect: a standard account, an onboarding link, and whether it can take payments', $created === 'acct_1NEW' && $http->requests[0]['url'] === 'https://api.stripe.com/v1/accounts' && $http->requests[0]['body'] === 'type=standard'
    && !isset($http->requests[0]['headers']['Stripe-Account']) && $link === 'https://connect.stripe.com/setup/s/abc' && $http->requests[1]['url'] === 'https://api.stripe.com/v1/account_links'
    && $fields === ['account' => 'acct_1NEW', 'refresh_url' => 'https://example.test/again', 'return_url' => 'https://example.test/done', 'type' => 'account_onboarding']
    && $stripe->chargesEnabled('acct_1NEW') === true && $http->requests[2]['method'] === 'GET' && $http->requests[2]['url'] === 'https://api.stripe.com/v1/accounts/acct_1NEW' && $http->requests[2]['body'] === null);
check('stripe session: read on the connected account, amount in minor units, currency in capitals', $stripe->checkoutSession('acct_1NEW', 'cs_test_1') === ['id' => 'cs_test_1', 'paid' => true, 'amount' => 11900, 'currency' => 'EUR']
    && $http->requests[3]['url'] === 'https://api.stripe.com/v1/checkout/sessions/cs_test_1' && $http->requests[3]['headers']['Stripe-Account'] === 'acct_1NEW');
$http->reset();
$http->answer(401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided: ' . $stripeKey]]);
$http->answer(400, ['error' => ['type' => 'invalid_request_error', 'code' => 'amount_too_small', 'message' => 'Amount must be at least 50 cents for acct_1TEST']]);
$http->answer(200, '<html>not json</html>');
$http->answer(200, ['id' => 'cs_test_1']);
$wrongKey = $refused(fn () => $stripe->createAccount());
$tooSmall = $refused(fn () => $stripe->createCheckoutSession('acct_1TEST', 1, 'EUR', 'x', 1, 'https://example.test/a', 'https://example.test/b'));
check('stripe errors: a refused key and a refused request are told apart; the log gets status and code, not the service\'s words', str_starts_with((string) $wrongKey, 'core.payment.error.credentials|')
    && !str_contains((string) $wrongKey, $stripeKey) && !str_contains((string) $wrongKey, 'Invalid API Key') && str_starts_with((string) $tooSmall, 'core.payment.error.refused|')
    && str_contains((string) $tooSmall, 'HTTP 400') && str_contains((string) $tooSmall, 'amount_too_small') && !str_contains((string) $tooSmall, 'acct_1TEST'));
check('stripe errors: an answer that is not JSON or lacks what is needed is no success', str_starts_with((string) $refused(fn () => $stripe->chargesEnabled('acct_1TEST')), 'core.payment.error.refused|')
    && str_starts_with((string) $refused(fn () => $stripe->createCheckoutSession('acct_1TEST', 100, 'EUR', 'x', 1, 'https://example.test/a', 'https://example.test/b')), 'core.payment.error.unexpected|'));
$http->reset();
check('stripe errors: no answer in time is "not reachable"; ids that are no ids are never sent', str_starts_with((string) $refused(fn () => $stripe->createAccount()), 'core.payment.error.unreachable|')
    && $refused(fn () => $stripe->checkoutSession('acct_1TEST', '../v1/accounts')) !== null && $refused(fn () => $stripe->chargesEnabled('acct_1/../x')) !== null && count($http->requests) === 1);

// What is sent to PayPal.
$http->reset();
$paypalOrder = ['id' => '5O190127TN364715T', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
    ['href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T', 'rel' => 'self', 'method' => 'GET'],
    ['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T', 'rel' => 'payer-action', 'method' => 'GET'],
]];
$captured = fn (string $value, string $currency = 'EUR', string $captureStatus = 'COMPLETED', ?string $customId = null) => ['id' => '5O190127TN364715T', 'status' => 'COMPLETED', 'purchase_units' => [[
    'reference_id' => 'default', 'payments' => ['captures' => [['id' => '3C679366HH908993F', 'status' => $captureStatus, 'amount' => ['currency_code' => $currency, 'value' => $value]] + ($customId !== null ? ['custom_id' => $customId] : [])]],
]]];
$paypal = new Modulento\Core\Payment\PaypalGateway($http, 'AClientId123', $paypalSecret, true);
$http->answer(200, ['access_token' => 'A21.token', 'token_type' => 'Bearer', 'expires_in' => 32400]);
$http->answer(201, $paypalOrder);
$http->answer(201, $captured('119.00'));
$made = $paypal->createOrder(11900, 'eur', '12', 'Logo – 000012', 'https://example.test/ok', 'https://example.test/back', 'request-1');
$sentJson = json_decode((string) $http->requests[1]['body'], true);
check('paypal token: client credentials with basic auth, against the sandbox in test mode', $http->requests[0]['method'] === 'POST' && $http->requests[0]['url'] === 'https://api-m.sandbox.paypal.com/v1/oauth2/token'
    && $http->requests[0]['headers']['Authorization'] === 'Basic ' . base64_encode('AClientId123:' . $paypalSecret) && $http->requests[0]['body'] === 'grant_type=client_credentials');
check('paypal order: capture intent, the amount as a decimal string, the order as custom id, own return addresses', $made === ['id' => '5O190127TN364715T', 'url' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T']
    && $http->requests[1]['url'] === 'https://api-m.sandbox.paypal.com/v2/checkout/orders' && $http->requests[1]['headers']['Authorization'] === 'Bearer A21.token'
    && $http->requests[1]['headers']['Content-Type'] === 'application/json' && $http->requests[1]['headers']['PayPal-Request-Id'] === 'request-1'
    && $sentJson['intent'] === 'CAPTURE' && count($sentJson['purchase_units']) === 1 && $sentJson['purchase_units'][0]['amount'] === ['currency_code' => 'EUR', 'value' => '119.00']
    && $sentJson['purchase_units'][0]['custom_id'] === '12' && $sentJson['payment_source']['paypal']['experience_context']['return_url'] === 'https://example.test/ok'
    && $sentJson['payment_source']['paypal']['experience_context']['cancel_url'] === 'https://example.test/back');
check('paypal capture: one token for several calls, the completed capture\'s amount in minor units', $paypal->capture('5O190127TN364715T') === ['status' => 'COMPLETED', 'paid' => true, 'amount' => 11900, 'currency' => 'EUR', 'custom_id' => null]
    && count($http->requests) === 3 && $http->requests[2]['method'] === 'POST' && $http->requests[2]['url'] === 'https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T/capture');
check('paypal: a capture PayPal holds back, or an order merely approved, is not a payment', $paypalClass::summary($captured('119.00', 'EUR', 'PENDING'))['paid'] === false
    && $paypalClass::summary(['status' => 'APPROVED'] + $captured('119.00'))['paid'] === false && $paypalClass::summary([])['paid'] === false && $paypalClass::summary($captured('119.00', 'EUR', 'COMPLETED', '12'))['custom_id'] === '12');
$http->reset();
$live = new Modulento\Core\Payment\PaypalGateway($http, 'AClientId123', $paypalSecret, false);
$http->answer(401, ['error' => 'invalid_client', 'error_description' => 'Client Authentication failed']);
$denied = $refused(fn () => $live->verify());
check('paypal errors: refused credentials, live address without test mode, nothing secret in the log line', str_starts_with((string) $denied, 'core.payment.error.credentials|') && !str_contains((string) $denied, $paypalSecret)
    && $http->requests[0]['url'] === 'https://api-m.paypal.com/v1/oauth2/token');
$http->reset();
$http->answer(200, ['access_token' => 'A21.token']);
$http->answer(422, ['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The requested action could not be performed', 'details' => [['issue' => 'ORDER_NOT_APPROVED']]]);
$notApproved = $refused(fn () => $live->capture('5O190127TN364715T'));
check('paypal errors: a refused request, no answer in time, an id that is none', str_starts_with((string) $notApproved, 'core.payment.error.refused|') && str_contains((string) $notApproved, 'HTTP 422')
    && str_starts_with((string) $refused(fn () => $live->order('5O190127TN364715T')), 'core.payment.error.unreachable|') && $refused(fn () => $live->order('../../v1/oauth2/token')) !== null && count($http->requests) === 3
    && str_starts_with((string) $refused(fn () => (new Modulento\Core\Payment\PaypalGateway($http, 'a', 'b', false))->verify()), 'core.payment.error.unreachable|'));

// The operator: which ways to pay are allowed, and the platform's Stripe keys.
$http->reset();
$enabledSetting = fn (string $id) => $pdo->query("SELECT value FROM setting WHERE name = 'core.payment.enabled.{$id}'")->fetchColumn();
$allMethods = ['core.offline', 'core.transfer', 'core.paypal', 'core.stripe'];
check('payment settings: need their permission', $pGet('/admin/payments', 1)['status'] === 403 && $pPost('/admin/payments', ['methods' => $allMethods], 1)['status'] === 403 && $enabledSetting('core.transfer') === false);
$r = $pGet('/admin/payments', 3);
check('payment settings: every method is listed; only "settle it yourselves" is on at first', $r['status'] === 200 && preg_match('/value="core\.offline" checked/', $r['body']) === 1
    && preg_match('/value="core\.(transfer|paypal|stripe)" checked/', $r['body']) === 0 && substr_count($r['body'], 'name="methods[]"') === 4
    && str_contains($r['body'], 'https://example.test/webhooks/stripe') && str_contains($r['body'], 'href="/admin/payments"'));
$pPost('/admin/payments', ['methods' => []], 3);
check('payment settings: at least one method stays allowed', str_contains($flash(), 'Mindestens eine') && $enabledSetting('core.offline') === false && $payApp()->payments->isEnabled('core.offline'));
$pPost('/admin/payments', ['methods' => $allMethods, 'stripe_secret_key' => 'pk_test_PUBLISHABLE1234'], 3);
check('payment settings: a publishable key is not taken for the secret one, and nothing is saved', str_contains($flash(), 'sk_') && $enabledSetting('core.transfer') === false && !$payApp()->payments->stripeConfigured());
$pPost('/admin/payments', ['methods' => $allMethods, 'stripe_secret_key' => $stripeKey, 'stripe_webhook_secret' => 'secret-without-prefix'], 3);
check('payment settings: a webhook secret must look like one', str_contains($flash(), 'whsec_') && !$payApp()->payments->stripeConfigured());
$pPost('/admin/payments', ['methods' => $allMethods, 'stripe_secret_key' => ' ' . $stripeKey . ' ', 'stripe_webhook_secret' => $webhookSecret], 3);
$storedKey = (string) $pdo->query("SELECT value FROM setting WHERE name = 'core.payment.stripe.secret_key'")->fetchColumn();
check('payment settings: methods switched on, keys stored encrypted', $enabledSetting('core.transfer') === '1' && $enabledSetting('core.stripe') === '1' && $payApp()->payments->stripeConfigured()
    && str_starts_with($storedKey, 'v1:') && !str_contains($storedKey, $stripeKey) && $pdo->query("SELECT COUNT(*) FROM setting WHERE value LIKE '%SECRETKEY%' OR value LIKE '%SigningSecret%'")->fetchColumn() == 0);
$r = $pGet('/admin/payments', 3);
check('payment settings: a stored key is never shown again, only its last characters', !str_contains($r['body'], $stripeKey) && !str_contains($r['body'], $webhookSecret) && !str_contains($r['body'], 'SECRETKEY')
    && str_contains($r['body'], '…abcd') && str_contains($r['body'], '…5678') && str_contains($r['body'], 'Angaben vollständig') && preg_match('/name="stripe_secret_key" type="password" value=""/', $r['body']) === 1);
$pPost('/admin/payments', ['methods' => $allMethods, 'stripe_secret_key' => '', 'stripe_webhook_secret' => ''], 3);
check('payment settings: an empty field leaves the stored key as it is', $payApp()->payments->stripeHints() === ['secret_key' => 'abcd', 'webhook_secret' => '5678']);
check('payment settings: another installation\'s key file opens nothing', !(new Modulento\Core\App(['app' => ['secret_key' => dirname($keyPath) . '/other.key'] + $payConfig['app']] + $payConfig, $pdo))->payments->stripeConfigured());

// A provider sets up what buyers are offered.
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'freelancer'");
$pPost('/account/provider', $business, 1);
$pdo->exec("UPDATE provider SET status = 'approved' WHERE account_id = 1");
$payProvider = (int) $provider(1)['id'];
$pPost('/account/offers/new', $offerForm, 1);
$pdo->exec("UPDATE offer SET status = 'published', published_at = '2026-01-01 00:00:00'");
$payOffer = (int) $pdo->query('SELECT MAX(id) FROM offer')->fetchColumn();
$setup = fn (string $method) => $pdo->query("SELECT * FROM provider_payment WHERE provider_id = {$payProvider} AND method = " . $pdo->quote($method))->fetch();
$validBank = ['holder' => 'Müller Design GmbH', 'iban' => 'de89 3704 0044 0532 0130 00', 'bic' => 'cobadeffxxx', 'bank' => 'Commerzbank'];

check('payment setup: needs a provider profile', $pGet('/account/payments', 3)['body'] === '' && $pPost('/account/payments/transfer', $validBank, 3)['body'] === '' && $pdo->query('SELECT COUNT(*) FROM provider_payment')->fetchColumn() == 0
    && $pGet('/account/payments', null)['body'] === '');
$r = $pGet('/account/payments', 1);
check('payment setup: a section per allowed method, linked from the account\'s menu, with the note on refunds', $r['status'] === 200 && str_contains($r['body'], 'Banküberweisung') && str_contains($r['body'], 'name="client_id"')
    && str_contains($r['body'], 'Mit Stripe verbinden') && str_contains($r['body'], 'Rückerstattungen') && str_contains($r['body'], 'Nicht eingerichtet') && substr_count($r['body'], 'href="/account/payments"') >= 1
    && str_contains($pGet('/account', 1)['body'], 'href="/account/payments"'));
$r = $pGet($orderPath . '?package=2', 2);
check('order form: a method the provider has not set up is not offered', $r['status'] === 200 && str_contains($r['body'], 'value="core.offline" checked') && !str_contains($r['body'], 'value="core.transfer"')
    && !str_contains($r['body'], 'value="core.paypal"') && !str_contains($r['body'], 'value="core.stripe"'));

$r = $pPost('/account/payments/transfer', ['iban' => 'DE89 3704 0044 0532 0130 01'] + $validBank, 1);
check('bank details: an IBAN with a typing error is refused and stays in the form to be corrected', $setup('core.transfer') === false && str_contains($r['body'], 'IBAN ist nicht gültig') && str_contains($r['body'], 'value="DE89 3704 0044 0532 0130 01"'));
$r = $pPost('/account/payments/transfer', ['holder' => '', 'bic' => 'XX'] + $validBank, 1);
check('bank details: holder and a well-formed BIC are required', $setup('core.transfer') === false && str_contains($r['body'], 'Kontoinhaber an') && str_contains($r['body'], 'BIC ist nicht gültig'));
$pPost('/account/payments/transfer', $validBank, 1);
check('bank details: stored normalised, and from then on offered', json_decode((string) $setup('core.transfer')['data'], true) === ['holder' => 'Müller Design GmbH', 'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'bank' => 'Commerzbank']
    && $setup('core.transfer')['status'] === 'ready' && str_contains($pGet('/account/payments', 1)['body'], 'value="DE89 3704 0044 0532 0130 00"') && str_contains($pGet($orderPath, 2)['body'], 'value="core.transfer"'));
check('bank details are not public', !str_contains($pGet('/providers/mueller-design', null)['body'], '0532') && !str_contains($pGet('/offers/ich-gestalte-dein-logo', 2)['body'], '0532') && !str_contains($pGet($orderPath, 2)['body'], '0532'));

// PayPal: the provider's own app; offered once PayPal accepted the credentials.
$http->reset();
$http->answer(401, ['error' => 'invalid_client']);
$pPost('/account/payments/paypal', ['client_id' => 'AClientId123', 'secret' => $paypalSecret, 'sandbox' => '1'], 1);
check('paypal setup: credentials PayPal refuses are kept but not offered, and the provider is told why', $setup('core.paypal')['status'] === 'unverified' && str_contains($flash(), 'Zugangsdaten abgelehnt')
    && !str_contains($pGet($orderPath, 2)['body'], 'value="core.paypal"') && $http->requests[0]['url'] === 'https://api-m.sandbox.paypal.com/v1/oauth2/token');
check('paypal setup: the secret is stored encrypted', !str_contains((string) $setup('core.paypal')['data'], $paypalSecret) && str_contains((string) $setup('core.paypal')['data'], '"secret":"v1:')
    && json_decode((string) $setup('core.paypal')['data'], true)['client_id'] === 'AClientId123');
$r = $pGet('/account/payments', 1);
check('paypal setup: the secret never comes back to the browser', !str_contains($r['body'], $paypalSecret) && !str_contains($r['body'], 'v1:') && str_contains($r['body'], 'value="AClientId123"') && str_contains($r['body'], 'Ein Secret ist gespeichert')
    && str_contains($r['body'], 'noch nicht bestätigt'));
$http->reset();
$pPost('/account/payments/paypal/check', [], 1);
check('paypal setup: PayPal not answering is said so, without a server error', str_contains($flash(), 'nicht erreichbar') && $setup('core.paypal')['status'] === 'unverified');
$http->reset();
$http->answer(200, ['access_token' => 'A21.token']);
$pPost('/account/payments/paypal/check', [], 1);
check('paypal setup: "check connection" asks for a token with the stored secret; success makes it available', $setup('core.paypal')['status'] === 'ready' && $flash('success') !== ''
    && $http->requests[0]['headers']['Authorization'] === 'Basic ' . base64_encode('AClientId123:' . $paypalSecret) && str_contains($pGet($orderPath, 2)['body'], 'value="core.paypal"'));
$http->reset();
$http->answer(200, ['access_token' => 'A21.token']);
$pPost('/account/payments/paypal', ['client_id' => 'AClientId456', 'secret' => ''], 1);
check('paypal setup: an empty secret field keeps the stored one; without test mode the live address is asked', $setup('core.paypal')['status'] === 'ready' && $http->requests[0]['url'] === 'https://api-m.paypal.com/v1/oauth2/token'
    && $http->requests[0]['headers']['Authorization'] === 'Basic ' . base64_encode('AClientId456:' . $paypalSecret));
$pPost('/account/payments/paypal', ['client_id' => '', 'secret' => 'x y'], 1);
check('paypal setup: incomplete input changes nothing', json_decode((string) $setup('core.paypal')['data'], true)['client_id'] === 'AClientId456' && $flash() !== '');
$pdo->exec('DELETE FROM rate_limit_attempt');
for ($i = 0; $i < 11; $i++) {
    $http->answer(200, ['access_token' => 'A21.token']);
    $pPost('/account/payments/paypal/check', [], 1);
}
check('paypal setup: checking the connection is rate limited', str_contains($flash(), 'Zu viele'));
$pdo->exec('DELETE FROM rate_limit_attempt');
$http->reset();
$http->answer(200, ['access_token' => 'A21.token']);
$pPost('/account/payments/paypal', ['client_id' => 'AClientId123', 'secret' => $paypalSecret, 'sandbox' => '1'], 1);

// Stripe: a connected account of the provider's own.
$http->reset();
check('stripe setup: following the "link expired" address creates nothing', $pGet('/account/payments/stripe/refresh', 1)['body'] === '' && $http->requests === [] && $setup('core.stripe') === false);
$http->answer(200, ['id' => 'acct_1TEST', 'type' => 'standard', 'charges_enabled' => false]);
$http->answer(200, ['object' => 'account_link', 'url' => 'https://connect.stripe.com/setup/s/acct_1TEST/abc']);
$pPost('/account/payments/stripe/connect', [], 1);
parse_str((string) $http->requests[1]['body'], $fields);
check('stripe setup: "connect" creates a standard account with the platform key and asks for an onboarding link back to this site', json_decode((string) $setup('core.stripe')['data'], true) === ['account' => 'acct_1TEST']
    && $setup('core.stripe')['status'] === 'pending' && $http->requests[0]['body'] === 'type=standard' && $http->requests[0]['headers']['Authorization'] === 'Bearer ' . $stripeKey
    && $fields === ['account' => 'acct_1TEST', 'refresh_url' => 'https://example.test/account/payments/stripe/refresh', 'return_url' => 'https://example.test/account/payments/stripe/return', 'type' => 'account_onboarding']
    && $flash() === '');
$http->reset();
$http->answer(200, ['id' => 'acct_1TEST', 'charges_enabled' => false, 'details_submitted' => false]);
$pGet('/account/payments/stripe/return', 1);
check('stripe setup: back from Stripe with an unfinished account - shown as incomplete, not offered', $setup('core.stripe')['status'] === 'pending' && str_contains($flash(), 'Einrichtung unvollständig')
    && $http->requests[0]['url'] === 'https://api.stripe.com/v1/accounts/acct_1TEST' && str_contains($pGet('/account/payments', 1)['body'], 'Einrichtung bei Stripe fortsetzen')
    && !str_contains($pGet($orderPath, 2)['body'], 'value="core.stripe"'));
$http->reset();
$http->answer(200, ['url' => 'https://connect.stripe.com/setup/s/acct_1TEST/def']);
$pPost('/account/payments/stripe/connect', [], 1);
check('stripe setup: continuing uses the account already stored', count($http->requests) === 1 && $http->requests[0]['url'] === 'https://api.stripe.com/v1/account_links' && json_decode((string) $setup('core.stripe')['data'], true) === ['account' => 'acct_1TEST']);
$http->reset();
$http->answer(200, ['url' => 'https://evil.example/setup']);
$pPost('/account/payments/stripe/connect', [], 1);
check('stripe setup: a link that does not lead to Stripe is not followed', str_contains($flash(), 'schiefgegangen'));
$http->reset();
$http->answer(200, ['id' => 'acct_1TEST', 'charges_enabled' => true]);
$pPost('/account/payments/stripe/status', [], 1);
$r = $pGet('/account/payments', 1);
check('stripe setup: once Stripe takes payments for the account it is ready and offered', $setup('core.stripe')['status'] === 'ready' && str_contains($r['body'], 'acct_1TEST') && !str_contains($r['body'], '/account/payments/stripe/connect')
    && str_contains($pGet($orderPath, 2)['body'], 'value="core.stripe"'));
$http->reset();
$http->answer(401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided: ' . $stripeKey]]);
$r = $pPost('/account/payments/stripe/status', [], 1);
check('stripe setup: a platform key Stripe refuses gives a message, not a server error, and nothing of the answer is shown', $r['status'] !== 500 && str_contains($flash(), 'Zugangsdaten abgelehnt') && !str_contains($flash(), 'Invalid API Key')
    && $setup('core.stripe')['status'] === 'ready');
$http->reset();
$r = $pPost('/account/payments/stripe/status', [], 1);
check('stripe setup: Stripe not answering gives a message, not a server error', $r['status'] !== 500 && str_contains($flash(), 'nicht erreichbar') && $setup('core.stripe')['status'] === 'ready');

// The order form offers what is allowed and set up - and the server checks it again.
// Orders left by the checks above stay untouched.
$ordersBefore = $lastOrder();
$r = $pGet($orderPath . '?package=2', 2);
check('order form: all four are offered and none is chosen for the buyer', substr_count($r['body'], 'name="payment_method"') === 4 && preg_match('/name="payment_method" value="[^"]+" checked/', $r['body']) === 0);
$pPost('/admin/payments', ['methods' => ['core.transfer']], 3);
$r = $pGet($orderPath . '?package=2', 2);
check('order form: a single method is preselected; what the operator switched off is gone', substr_count($r['body'], 'name="payment_method"') === 1 && str_contains($r['body'], 'value="core.transfer" checked'));
$pPost($orderPath, ['package' => '2', 'payment_method' => 'core.stripe'], 2);
check('a method the operator does not allow is refused, whatever the form sends', $lastOrder() === $ordersBefore);
$pPost('/admin/payments', ['methods' => $allMethods], 3);
$pPost('/account/payments/paypal/delete', [], 1);
$r = $pPost($orderPath, ['package' => '2', 'payment_method' => 'core.paypal'], 2);
check('a method this provider has not set up is refused, whatever the form sends', $lastOrder() === $ordersBefore && $setup('core.paypal') === false && str_contains($r['body'], 'Bitte wähle eine Zahlungsart'));
$pPost($orderPath, ['package' => '2', 'payment_method' => 'core.nope'], 2);
$pPost($orderPath, ['package' => '2', 'payment_method' => ['core.offline']], 2);
check('a method that does not exist is refused', $lastOrder() === $ordersBefore);
$http->reset();
$http->answer(200, ['access_token' => 'A21.token']);
$pPost('/account/payments/paypal', ['client_id' => 'AClientId123', 'secret' => $paypalSecret, 'sandbox' => '1'], 1);
$http->reset();

// Bank transfer: the buyer reads the bank details on the order page, the provider confirms.
$pPost($orderPath, ['package' => '2', 'extras' => ['0'], 'payment_method' => 'core.transfer'], 2);
$transferOrder = $lastOrder();
$number = fn (int $id) => sprintf('%06d', $id);
check('transfer: the order is placed with it, unpaid, without any call to a service', $transferOrder > $ordersBefore && $orderRow($transferOrder)['payment_method'] === 'core.transfer' && $orderRow($transferOrder)['payment_state'] === 'unpaid'
    && $http->requests === [] && $pdo->query('SELECT COUNT(*) FROM order_payment')->fetchColumn() == 0);
$r = $pGet('/orders/' . $transferOrder, 2);
check('transfer: the buyer sees holder, IBAN, amount and the order number as payment reference', str_contains($r['body'], 'DE89 3704 0044 0532 0130 00') && str_contains($r['body'], 'COBADEFFXXX') && str_contains($r['body'], 'Müller Design GmbH')
    && str_contains($r['body'], 'Verwendungszweck') && str_contains($r['body'], '<td>Bestellung ' . $number($transferOrder) . '</td>') && !str_contains($r['body'], 'Jetzt bezahlen') && !str_contains($r['body'], 'Zahlung als erhalten markieren'));
$pPost('/orders/' . $transferOrder . '/paid', [], 2);
check('transfer: the buyer cannot confirm the own payment', $orderRow($transferOrder)['payment_state'] === 'unpaid');
check('transfer: the provider confirms the receipt as before', str_contains($pGet('/orders/' . $transferOrder, 1)['body'], 'Zahlung als erhalten markieren') && $pPost('/orders/' . $transferOrder . '/paid', [], 1)
    && $orderRow($transferOrder)['payment_state'] === 'paid');
$r = $pGet('/orders/' . $transferOrder, 2);
check('transfer: once paid the page says when, and the bank details are gone', str_contains($r['body'], 'bezahlt am ' . $orderRow($transferOrder)['paid_at']) && !str_contains($r['body'], '0532') && !str_contains($r['body'], 'Zahlungsart übernehmen'));
$pdo->exec("DELETE FROM orders WHERE id > {$ordersBefore}");
@unlink($mailLog);

// Stripe: ordering leads to Checkout on the provider's account; the return confirms it.
$paymentsOf = fn (int $orderId) => $pdo->query("SELECT * FROM order_payment WHERE order_id = {$orderId} ORDER BY id")->fetchAll();
$paidMails = fn () => substr_count((string) @file_get_contents($mailLog), 'Zahlung eingegangen');
$stripeSession = fn (string $id, string $status = 'unpaid', int $amount = 11900, string $currency = 'eur') => ['id' => $id, 'object' => 'checkout.session', 'payment_status' => $status, 'amount_total' => $amount, 'currency' => $currency];
$http->answer(200, ['id' => 'cs_test_A1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_A1']);
$pPost($orderPath, ['package' => '2', 'extras' => ['0'], 'payment_method' => 'core.stripe'], 2);
$stripeOrder = $lastOrder();
$started = $paymentsOf($stripeOrder);
parse_str((string) ($http->requests[0]['body'] ?? ''), $fields);
check('stripe order: the session id is stored with the order, with amount and currency', count($started) === 1 && $started[0]['provider_reference'] === 'cs_test_A1' && $started[0]['status'] === 'pending'
    && (int) $started[0]['amount'] === 11900 && $started[0]['currency'] === 'EUR' && $started[0]['method'] === 'core.stripe' && $orderRow($stripeOrder)['payment_state'] === 'unpaid');
check('stripe order: charged on the provider\'s account, in cents, named after offer and order, returning to this order', count($http->requests) === 1 && $http->requests[0]['headers']['Stripe-Account'] === 'acct_1TEST'
    && $http->requests[0]['headers']['Authorization'] === 'Bearer ' . $stripeKey && $fields['line_items'][0]['price_data']['unit_amount'] === '11900' && $fields['line_items'][0]['price_data']['currency'] === 'eur'
    && $fields['line_items'][0]['price_data']['product_data']['name'] === 'Ich gestalte dein Logo – ' . $number($stripeOrder) && $fields['client_reference_id'] === (string) $stripeOrder
    && $fields['metadata']['order_id'] === (string) $stripeOrder && $fields['success_url'] === 'https://example.test/orders/' . $stripeOrder . '/payments/' . $started[0]['id'] . '/return'
    && $fields['cancel_url'] === 'https://example.test/orders/' . $stripeOrder && !str_contains((string) $http->requests[0]['body'], 'application_fee'));
check('stripe order: buyer gets "pay now"; provider and administration see method, state and the session id', str_contains($pGet('/orders/' . $stripeOrder, 2)['body'], 'Jetzt bezahlen (119,00 €)')
    && !str_contains($pGet('/orders/' . $stripeOrder, 2)['body'], 'cs_test_A1') && str_contains($pGet('/orders/' . $stripeOrder, 1)['body'], '<code>cs_test_A1</code>')
    && !str_contains($pGet('/orders/' . $stripeOrder, 1)['body'], 'Jetzt bezahlen') && !str_contains($pGet('/orders/' . $stripeOrder, 1)['body'], 'Zahlung als erhalten markieren')
    && str_contains($pGet('/admin/orders/' . $stripeOrder, 3)['body'], '<code>cs_test_A1</code>') && str_contains($pGet('/admin/orders/' . $stripeOrder, 3)['body'], 'Karte und weitere (Stripe)'));
$returnPath = '/orders/' . $stripeOrder . '/payments/' . $started[0]['id'] . '/return';
$http->reset();
check('return: only the buyer of the order - not the provider, not someone else, not a visitor - and Stripe is not even asked', $pGet($returnPath, 3)['status'] === 404 && $pGet($returnPath, 1)['status'] === 404 && $pGet($returnPath, null)['body'] === ''
    && $http->requests === [] && $orderRow($stripeOrder)['payment_state'] === 'unpaid');
$http->answer(200, $stripeSession('cs_test_A1'));
$pGet($returnPath . '?session_id=cs_test_EVIL', 2);
check('return: the session asked about is the stored one, whatever the address says; unpaid stays unpaid', $http->requests[0]['method'] === 'GET' && $http->requests[0]['url'] === 'https://api.stripe.com/v1/checkout/sessions/cs_test_A1'
    && $http->requests[0]['headers']['Stripe-Account'] === 'acct_1TEST' && $orderRow($stripeOrder)['payment_state'] === 'unpaid' && str_contains($flash(), 'nicht abgeschlossen'));
$http->answer(200, $stripeSession('cs_test_A1', 'paid', 100));
$pGet($returnPath, 2);
$http->answer(200, $stripeSession('cs_test_A1', 'paid', 11900, 'usd'));
$pGet($returnPath, 2);
$http->answer(200, $stripeSession('cs_test_OTHER', 'paid'));
$pGet($returnPath, 2);
check('return: another amount, another currency or another session is not a payment of this order', $orderRow($stripeOrder)['payment_state'] === 'unpaid' && $paymentsOf($stripeOrder)[0]['status'] === 'pending' && $paidMails() === 0);
$http->reset();
$r = $pGet($returnPath, 2);
check('return: Stripe not answering leaves the order unpaid, with a message and no server error', $r['status'] !== 500 && str_contains($flash(), 'nicht erreichbar') && $orderRow($stripeOrder)['payment_state'] === 'unpaid');
$http->answer(200, $stripeSession('cs_test_A1', 'paid'));
$pGet($returnPath, 2);
check('return: paid with the order\'s amount and currency marks the order as paid', $orderRow($stripeOrder)['payment_state'] === 'paid' && $orderRow($stripeOrder)['paid_at'] !== null && $paymentsOf($stripeOrder)[0]['status'] === 'paid'
    && $flash('success') !== '');
check('paid by a service: buyer and provider are both told by mail', $paidMails() === 2 && lastMail($mailLog, 'editor@example.test')['subject'] === 'Bestellung ' . $number($stripeOrder) . ': Zahlung eingegangen'
    && lastMail($mailLog, 'plain@example.test')['subject'] === 'Bestellung ' . $number($stripeOrder) . ': Zahlung eingegangen' && lastMail($mailLog, 'plain@example.test')['link'] === '/orders/' . $stripeOrder
    && str_contains((string) file_get_contents($mailLog), '119,00 € über Karte und weitere (Stripe)'));
$r = $pGet('/orders/' . $stripeOrder, 2);
check('paid by a service: the order page says so, with the time, and offers no further payment', str_contains($r['body'], 'bezahlt am ' . $orderRow($stripeOrder)['paid_at']) && !str_contains($r['body'], 'Jetzt bezahlen') && !str_contains($r['body'], 'Zahlungsart übernehmen'));
$http->reset();
$paidAt = $orderRow($stripeOrder)['paid_at'];
$pGet($returnPath, 2);
$again = $payApp();
$body = $event('cs_test_A1', 11900);
check('idempotent: the return opened again and the webhook arriving afterwards change nothing and mail nobody', $http->requests === [] && $again->payments->handleStripeWebhook($again, $body, $sign($body, $webhookSecret)) === 200
    && $paidMails() === 2 && $orderRow($stripeOrder)['paid_at'] === $paidAt && count($paymentsOf($stripeOrder)) === 1);
$pPost('/orders/' . $stripeOrder . '/pay', [], 2);
$pPost('/orders/' . $stripeOrder . '/payment-method', ['payment_method' => 'core.transfer'], 2);
check('a paid order is neither paid again nor moved to another method', $http->requests === [] && $orderRow($stripeOrder)['payment_method'] === 'core.stripe' && count($paymentsOf($stripeOrder)) === 1);
$pdo->exec("DELETE FROM orders WHERE id > {$ordersBefore}");
@unlink($mailLog);

// The webhook: Stripe reports the payment itself, signed.
$http->reset();
$http->answer(200, ['id' => 'cs_test_B2', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_B2']);
$pPost($orderPath, ['package' => '2', 'extras' => ['0'], 'payment_method' => 'core.stripe'], 2);
$hookOrder = $lastOrder();
$hook = function (string $body, string $signature) use ($payApp): int {
    $app = $payApp();

    return $app->payments->handleStripeWebhook($app, $body, $signature);
};
$body = $event('cs_test_B2', 11900);
check('webhook: without a valid signature the answer is 400 and nothing happens', $hook($body, '') === 400 && $hook($body, 't=' . time() . ',v1=' . str_repeat('ab', 32)) === 400 && $hook($body, $sign($body, 'whsec_someone_else')) === 400
    && $hook($body, $sign($body, $webhookSecret, time() - 600)) === 400 && $hook($event('cs_test_B2', 1), $sign($body, $webhookSecret)) === 400 && $orderRow($hookOrder)['payment_state'] === 'unpaid');
$r = request($pdo, $payConfig, 'POST', '/webhooks/stripe', null, ['_csrf' => '']);
check('webhook: the address is public and needs no CSRF token, but an unsigned call gets 400', $r['status'] === 400 && $r['body'] === 'invalid signature' && $orderRow($hookOrder)['payment_state'] === 'unpaid');
$wrongAmount = $event('cs_test_B2', 100);
$wrongCurrency = $event('cs_test_B2', 11900, 'usd');
$notPaid = $event('cs_test_B2', 11900, 'eur', 'unpaid');
$unknown = $event('cs_test_NOBODY', 11900);
$otherType = $event('cs_test_B2', 11900, 'eur', 'paid', 'payment_intent.succeeded');
check('webhook: signed, but another amount, another currency, not paid, an unknown session or another event - accepted and ignored', $hook($wrongAmount, $sign($wrongAmount, $webhookSecret)) === 200
    && $hook($wrongCurrency, $sign($wrongCurrency, $webhookSecret)) === 200 && $hook($notPaid, $sign($notPaid, $webhookSecret)) === 200 && $hook($unknown, $sign($unknown, $webhookSecret)) === 200
    && $hook($otherType, $sign($otherType, $webhookSecret)) === 200 && $hook('not json', $sign('not json', $webhookSecret)) === 400
    && $orderRow($hookOrder)['payment_state'] === 'unpaid' && $paymentsOf($hookOrder)[0]['status'] === 'pending' && $paidMails() === 0);
check('webhook: a signed "completed" with the order\'s amount marks the order found by the stored session id as paid', $hook($body, $sign($body, $webhookSecret)) === 200 && $orderRow($hookOrder)['payment_state'] === 'paid'
    && $paymentsOf($hookOrder)[0]['status'] === 'paid' && $paidMails() === 2 && $http->requests !== [] && count($http->requests) === 1);
$paidAt = $orderRow($hookOrder)['paid_at'];
check('webhook: delivered twice it acts once', $hook($body, $sign($body, $webhookSecret)) === 200 && $hook($body, $sign($body, $webhookSecret)) === 200 && $paidMails() === 2 && $orderRow($hookOrder)['paid_at'] === $paidAt);
$pdo->exec("DELETE FROM orders WHERE id > {$ordersBefore}");
@unlink($mailLog);

// Nobody redeems someone else's payment, and "pay now" starts over safely.
$http->reset();
$http->answer(200, ['id' => 'cs_test_C3', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_C3']);
$pPost($orderPath, ['package' => '2', 'extras' => ['0'], 'payment_method' => 'core.stripe'], 2);
$orderOfTwo = $lastOrder();
$http->answer(200, ['id' => 'cs_test_D4', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_D4']);
$pPost($orderPath, ['package' => '1', 'payment_method' => 'core.stripe'], 3);
$orderOfThree = $lastOrder();
$paymentOfTwo = (int) $paymentsOf($orderOfTwo)[0]['id'];
$paymentOfThree = (int) $paymentsOf($orderOfThree)[0]['id'];
$http->reset();
check('a payment of another order cannot be redeemed for one\'s own, nor one\'s own for another order', $orderOfThree > $orderOfTwo && $pGet('/orders/' . $orderOfThree . '/payments/' . $paymentOfTwo . '/return', 3)['status'] === 404
    && $pGet('/orders/' . $orderOfTwo . '/payments/' . $paymentOfThree . '/return', 3)['status'] === 404 && $pGet('/orders/' . $orderOfTwo . '/payments/' . $paymentOfTwo . '/return', 3)['status'] === 404
    && $pGet('/orders/' . $orderOfThree . '/payments/999999/return', 3)['status'] === 404 && $http->requests === [] && $orderRow($orderOfTwo)['payment_state'] === 'unpaid' && $orderRow($orderOfThree)['payment_state'] === 'unpaid');
check('"pay now" and changing the method are the buyer\'s alone', $pPost('/orders/' . $orderOfTwo . '/pay', [], 3)['status'] === 404 && $pPost('/orders/' . $orderOfTwo . '/pay', [], 1)['status'] === 404
    && $pPost('/orders/' . $orderOfTwo . '/payment-method', ['payment_method' => 'core.transfer'], 1)['status'] === 404 && $pPost('/orders/' . $orderOfTwo . '/pay', ['_csrf' => 'wrong'], 2)['body'] === ''
    && $http->requests === [] && $orderRow($orderOfTwo)['payment_method'] === 'core.stripe' && count($paymentsOf($orderOfTwo)) === 1);
$http->answer(200, $stripeSession('cs_test_C3'));
$http->answer(200, ['id' => 'cs_test_E5', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_E5']);
$pPost('/orders/' . $orderOfTwo . '/pay', [], 2);
check('"pay now": after a payment broken off a new session is started, the open one having been checked first', count($http->requests) === 2 && $http->requests[0]['url'] === 'https://api.stripe.com/v1/checkout/sessions/cs_test_C3'
    && $http->requests[1]['method'] === 'POST' && count($paymentsOf($orderOfTwo)) === 2 && $paymentsOf($orderOfTwo)[1]['provider_reference'] === 'cs_test_E5' && $paymentsOf($orderOfTwo)[1]['status'] === 'pending');
$http->reset();
$http->answer(200, $stripeSession('cs_test_E5'));
$http->answer(200, ['id' => 'cs_test_F6', 'url' => 'https://evil.example/c/pay/cs_test_F6']);
$r = $pPost('/orders/' . $orderOfTwo . '/pay', [], 2);
check('"pay now": an address that is not Stripe\'s is not followed and its session not kept', $r['status'] !== 500 && str_contains($flash(), 'schiefgegangen') && $paymentsOf($orderOfTwo)[2]['status'] === 'failed' && $paymentsOf($orderOfTwo)[2]['provider_reference'] === null);
$http->reset();
$http->answer(200, $stripeSession('cs_test_E5', 'paid'));
$pPost('/orders/' . $orderOfTwo . '/pay', [], 2);
check('"pay now": a payment that went through without the buyer coming back is recorded instead of being asked for twice', count($http->requests) === 1 && $orderRow($orderOfTwo)['payment_state'] === 'paid'
    && $paymentsOf($orderOfTwo)[1]['status'] === 'paid' && count($paymentsOf($orderOfTwo)) === 3 && $paidMails() === 2);
$http->reset();
$r = $pPost('/orders/' . $orderOfThree . '/pay', [], 3);
check('"pay now": Stripe not answering - a message, no server error, the order stays as it is', $r['status'] !== 500 && str_contains($flash(), 'nicht erreichbar') && $orderRow($orderOfThree)['payment_state'] === 'unpaid');
for ($i = 0; $i < 21; $i++) {
    $pPost('/orders/' . $orderOfThree . '/pay', [], 3);
}
check('"pay now" is rate limited per account', str_contains($flash(), 'Zu viele'));
$pdo->exec('DELETE FROM rate_limit_attempt');
$pdo->exec("DELETE FROM orders WHERE id > {$ordersBefore}");
@unlink($mailLog);

// The service fails while ordering: the order stands and can be paid later or differently.
$http->reset();
$r = $pPost($orderPath, ['package' => '2', 'extras' => ['0'], 'payment_method' => 'core.stripe'], 2);
$failedOrder = $lastOrder();
check('ordering while Stripe does not answer: the order exists, unpaid, and the buyer reads why on its page', $r['status'] !== 500 && $failedOrder > $ordersBefore && $orderRow($failedOrder)['payment_state'] === 'unpaid'
    && str_contains($flash(), 'nicht erreichbar') && $paymentsOf($failedOrder)[0]['status'] === 'failed' && str_contains($pGet('/orders/' . $failedOrder, 2)['body'], 'Jetzt bezahlen'));
$http->answer(400, ['error' => ['type' => 'invalid_request_error', 'code' => 'account_invalid', 'message' => 'The provided key ' . $stripeKey . ' does not have access to account acct_1TEST']]);
$r = $pPost('/orders/' . $failedOrder . '/pay', [], 2);
check('a refusal by the service is told in own words; nothing of its answer reaches the page', $r['status'] !== 500 && str_contains($flash(), 'Anfrage abgelehnt') && !str_contains($flash(), 'acct_') && !str_contains($flash(), 'sk_test'));
$r = $pGet('/orders/' . $failedOrder, 2);
check('changing the method: the other available ones are offered while unpaid', str_contains($r['body'], 'Andere Zahlungsart wählen') && str_contains($r['body'], 'value="core.transfer"') && str_contains($r['body'], 'value="core.paypal"')
    && str_contains($r['body'], 'value="core.offline"') && !str_contains($r['body'], 'value="core.stripe"'));
$pPost('/orders/' . $failedOrder . '/payment-method', ['payment_method' => 'core.nope'], 2);
check('changing the method: only to one that exists', $orderRow($failedOrder)['payment_method'] === 'core.stripe' && $flash() !== '');
$pPost('/orders/' . $failedOrder . '/payment-method', ['payment_method' => 'core.transfer'], 2);
$r = $pGet('/orders/' . $failedOrder, 2);
check('changing the method: to bank transfer, and the bank details appear', $orderRow($failedOrder)['payment_method'] === 'core.transfer' && str_contains($r['body'], 'DE89 3704 0044 0532 0130 00') && !str_contains($r['body'], 'Jetzt bezahlen')
    && str_contains($r['body'], 'value="core.stripe"'));
$pPost('/account/payments/transfer/delete', [], 1);
check('a provider removes the bank details: gone from the order page, the buyer is asked to choose again', $setup('core.transfer') === false && !str_contains($pGet('/orders/' . $failedOrder, 2)['body'], '0532')
    && str_contains($pGet('/orders/' . $failedOrder, 2)['body'], 'noch keine Zahlungsart gewählt') && !str_contains($pGet($orderPath, 2)['body'], 'value="core.transfer"'));
$pPost('/account/payments/transfer', $validBank, 1);
$pdo->exec("DELETE FROM orders WHERE id > {$ordersBefore}");

// PayPal: the order is created at PayPal, approved there, captured on return.
$http->reset();
$http->answer(200, ['access_token' => 'A21.token']);
$http->answer(201, $paypalOrder);
$pPost($orderPath, ['package' => '2', 'extras' => ['0'], 'payment_method' => 'core.paypal'], 2);
$paypalOrderId = $lastOrder();
$started = $paymentsOf($paypalOrderId);
$sentJson = json_decode((string) ($http->requests[1]['body'] ?? ''), true);
check('paypal order: created with the provider\'s credentials, the PayPal order id stored with the order', count($started) === 1 && $started[0]['provider_reference'] === '5O190127TN364715T' && $started[0]['status'] === 'pending'
    && $http->requests[0]['headers']['Authorization'] === 'Basic ' . base64_encode('AClientId123:' . $paypalSecret) && $http->requests[1]['url'] === 'https://api-m.sandbox.paypal.com/v2/checkout/orders'
    && $sentJson['purchase_units'][0]['amount'] === ['currency_code' => 'EUR', 'value' => '119.00'] && $sentJson['purchase_units'][0]['custom_id'] === (string) $paypalOrderId
    && $sentJson['payment_source']['paypal']['experience_context']['return_url'] === 'https://example.test/orders/' . $paypalOrderId . '/payments/' . $started[0]['id'] . '/return'
    && $sentJson['payment_source']['paypal']['experience_context']['cancel_url'] === 'https://example.test/orders/' . $paypalOrderId && strlen($http->requests[1]['headers']['PayPal-Request-Id']) === 32);
$returnPath = '/orders/' . $paypalOrderId . '/payments/' . $started[0]['id'] . '/return';
$http->reset();
$http->answer(200, ['access_token' => 'A21.token']);
$http->answer(200, $paypalOrder);
$pGet($returnPath . '?token=5O190127TN364715T&PayerID=ABC', 2);
check('paypal return: an order the buyer did not approve is not captured', count($http->requests) === 2 && $http->requests[1]['method'] === 'GET' && $orderRow($paypalOrderId)['payment_state'] === 'unpaid' && str_contains($flash(), 'nicht abgeschlossen'));
foreach ([$captured('1.00'), $captured('119.00', 'USD'), $captured('119.00', 'EUR', 'PENDING'), $captured('119.00', 'EUR', 'COMPLETED', '999999'), ['status' => 'COMPLETED']] as $answer) {
    $http->reset();
    $http->answer(200, ['access_token' => 'A21.token']);
    $http->answer(200, ['status' => 'APPROVED'] + $paypalOrder);
    $http->answer(201, $answer);
    $pGet($returnPath, 2);
}
check('paypal return: another amount or currency, a capture held back, another order\'s capture, or no capture at all is not a payment', $orderRow($paypalOrderId)['payment_state'] === 'unpaid' && $paymentsOf($paypalOrderId)[0]['status'] === 'pending'
    && count($http->requests) === 3 && $paidMails() === 0);
$http->reset();
$http->answer(200, ['access_token' => 'A21.token']);
$http->answer(200, ['status' => 'APPROVED'] + $paypalOrder);
$http->answer(201, $captured('119.00', 'EUR', 'COMPLETED', (string) $paypalOrderId));
$pGet($returnPath . '?token=SOMEONEELSESORDER&PayerID=ABC', 2);
check('paypal return: the order captured is the stored one, whatever the address says', $http->requests[1]['url'] === 'https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T'
    && $http->requests[2]['method'] === 'POST' && $http->requests[2]['url'] === 'https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T/capture'
    && !str_contains(implode(' ', array_column($http->requests, 'url')), 'SOMEONEELSESORDER') && $http->requests[2]['headers']['Authorization'] === 'Bearer A21.token');
check('paypal return: completed with the order\'s amount and currency marks it as paid, both sides are mailed', $orderRow($paypalOrderId)['payment_state'] === 'paid' && $paymentsOf($paypalOrderId)[0]['status'] === 'paid' && $paidMails() === 2
    && str_contains((string) file_get_contents($mailLog), '119,00 € über PayPal') && str_contains($pGet('/orders/' . $paypalOrderId, 1)['body'], '<code>5O190127TN364715T</code>'));
$http->reset();
$pGet($returnPath, 2);
check('paypal return: opened again it captures nothing and mails nobody', $http->requests === [] && $paidMails() === 2);
$pdo->exec("DELETE FROM orders WHERE id > {$ordersBefore}");
@unlink($mailLog);
$http->reset();
$http->answer(200, ['access_token' => 'A21.token']);
$http->answer(422, ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'CURRENCY_NOT_SUPPORTED']]]);
$r = $pPost($orderPath, ['package' => '2', 'payment_method' => 'core.paypal'], 2);
check('ordering while PayPal refuses: the order stands, unpaid, with a message', $r['status'] !== 500 && $lastOrder() > $ordersBefore && $orderRow($lastOrder())['payment_state'] === 'unpaid' && str_contains($flash(), 'Anfrage abgelehnt')
    && $paymentsOf($lastOrder())[0]['status'] === 'failed');
$http->reset();
$http->answer(200, ['access_token' => 'A21.token']);
$http->answer(201, ['id' => '7XY', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.paypal.com.evil.example/checkoutnow?token=7XY']]]);
$pPost('/orders/' . $lastOrder() . '/pay', [], 2);
check('paypal: an approval address that is not PayPal\'s is not followed', str_contains($flash(), 'schiefgegangen') && $paymentsOf($lastOrder())[1]['status'] === 'failed');
$pdo->exec("DELETE FROM orders WHERE id > {$ordersBefore}");

// An order an extension created itself (an auction's sale) starts with
// "core.offline" - also where the operator has switched that off.
$pPost('/admin/payments', ['methods' => ['core.transfer', 'core.paypal', 'core.stripe']], 3);
$extApp = $payApp();
$soldId = $extApp->orders->create(['id' => 2, 'email' => 'editor@example.test', 'display_name' => null], $extApp->offers->find($payOffer), 'Alte Kamera', $extApp->orders->flow('freelancer.service'),
    [['label' => 'Alte Kamera', 'quantity' => 1, 'unit_price' => 1600]], [], 'core.offline', 'de', null, false);
$r = $pGet('/orders/' . $soldId, 2);
check('an order created with a method that is switched off: the buyer is asked to choose among the available ones', $orderRow($soldId)['payment_method'] === 'core.offline' && str_contains($r['body'], 'noch keine Zahlungsart gewählt')
    && str_contains($r['body'], 'value="core.transfer"') && str_contains($r['body'], 'value="core.stripe"') && !str_contains($r['body'], 'value="core.offline"') && !str_contains($r['body'], 'Direkt mit dem Anbieter')
    && !str_contains($r['body'], 'Jetzt bezahlen'));
$http->reset();
$pPost('/orders/' . $soldId . '/pay', [], 2);
$pPost('/orders/' . $soldId . '/payment-method', ['payment_method' => 'core.offline'], 2);
check('such an order: nothing is paid before a method is chosen, and the switched-off one cannot be chosen', $http->requests === [] && $orderRow($soldId)['payment_method'] === 'core.offline' && $flash() !== '');
$pPost('/orders/' . $soldId . '/payment-method', ['payment_method' => 'core.stripe'], 2);
$http->answer(200, ['id' => 'cs_test_G7', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_G7']);
$pPost('/orders/' . $soldId . '/pay', [], 2);
parse_str((string) ($http->requests[0]['body'] ?? ''), $fields);
check('such an order: once a method is chosen it is paid like any other', $orderRow($soldId)['payment_method'] === 'core.stripe' && $paymentsOf($soldId)[0]['provider_reference'] === 'cs_test_G7'
    && ($fields['line_items'][0]['price_data']['unit_amount'] ?? null) === '1600');
// Paid through a window that was still open after the buyer chose something else.
$pPost('/orders/' . $soldId . '/payment-method', ['payment_method' => 'core.transfer'], 2);
$body = $event('cs_test_G7', 1600);
check('a payment arriving for a method the buyer has left meanwhile still counts, and the order shows how it was paid', $orderRow($soldId)['payment_method'] === 'core.transfer' && $hook($body, $sign($body, $webhookSecret)) === 200
    && $orderRow($soldId)['payment_state'] === 'paid' && $orderRow($soldId)['payment_method'] === 'core.stripe');
check('the provider still confirms by hand where the buyer chose nothing, also with that method switched off', (function () use ($pdo, $extApp, $payOffer, $pPost, $orderRow): bool {
    $id = $extApp->orders->create(['id' => 2, 'email' => 'editor@example.test', 'display_name' => null], $extApp->offers->find($payOffer), 'Alte Kamera', $extApp->orders->flow('freelancer.service'),
        [['label' => 'Alte Kamera', 'quantity' => 1, 'unit_price' => 1600]], [], 'core.offline', 'de', null, false);
    $pPost('/orders/' . $id . '/paid', [], 1);

    return $orderRow($id)['payment_state'] === 'paid';
})());
$before = $lastOrder();
$r = $pPost($orderPath, ['package' => '2'], 2);
check('with "settle it yourselves" switched off the order form does not offer it, and with several methods none is assumed', !str_contains($pGet($orderPath, 2)['body'], 'value="core.offline"') && $lastOrder() === $before
    && str_contains($r['body'], 'Bitte wähle eine Zahlungsart'));
$pdo->exec("DELETE FROM orders WHERE id > {$ordersBefore}");

// Nothing secret in any page, in the data export or in the log.
$pages = $pGet('/admin/payments', 3)['body'] . $pGet('/account/payments', 1)['body'] . $pGet($orderPath, 2)['body'] . $pGet('/admin/providers/' . $payProvider, 3)['body'];
$export = $pGet('/account/export', 1)['body'];
check('keys appear in no page', strlen($pages) > 5000 && !str_contains($pages, $stripeKey) && !str_contains($pages, $webhookSecret) && !str_contains($pages, $paypalSecret) && !str_contains($pages, 'v1:'));
check('data export: bank details and account ids, but no key and no encrypted key', str_contains($export, 'DE89370400440532013000') && str_contains($export, 'acct_1TEST') && str_contains($export, 'AClientId123')
    && !str_contains($export, $paypalSecret) && !str_contains($export, 'v1:') && !str_contains($export, '"secret"') && !str_contains($export, $stripeKey));
$logged = (string) @file_get_contents($payLog);
check('the log says what failed, without keys or the services\' own words', str_contains($logged, 'HTTP 0') && str_contains($logged, 'HTTP 400') && !str_contains($logged, $stripeKey) && !str_contains($logged, $paypalSecret)
    && !str_contains($logged, $webhookSecret) && !str_contains($logged, 'Invalid API Key') && !str_contains($logged, 'A21.token') && !str_contains($logged, 'acct_1TEST'));

// A provider who has set up nothing, on a site without "settle it yourselves".
foreach (['transfer', 'paypal', 'stripe'] as $slug) {
    $pPost('/account/payments/' . $slug . '/delete', [], 1);
}
$r = $pGet($orderPath . '?package=2', 2);
$pPost($orderPath, ['package' => '2'], 2);
check('no method available: the order form says so and no order is placed', $pdo->query('SELECT COUNT(*) FROM provider_payment')->fetchColumn() == 0 && str_contains($r['body'], 'noch keine Zahlungsart eingerichtet')
    && $lastOrder() === $ordersBefore && !str_contains($pGet('/account/payments', 1)['body'], 'acct_1TEST'));
$pPost('/account/payments/transfer', $validBank, 1);
$pPost('/admin/payments', ['methods' => ['core.offline'], 'stripe_remove' => '1'], 3);
check('the operator removes the Stripe keys and switches the new methods off again', !$payApp()->payments->stripeConfigured() && !$payApp()->payments->isEnabled('core.transfer') && $payApp()->payments->isEnabled('core.offline')
    && $pPost('/account/payments/transfer', $validBank, 1)['body'] === '' && !str_contains($pGet('/account/payments', 1)['body'], 'name="iban"'));
$pdo->exec('DELETE FROM provider');
check('deleting a provider removes what it set up', $pdo->query('SELECT COUNT(*) FROM provider_payment')->fetchColumn() == 0);
$pdo->exec('DELETE FROM offer');
$pdo->exec("DELETE FROM setting WHERE name LIKE 'core.payment.%'");
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'freelancer'");
$pdo->exec('DELETE FROM rate_limit_attempt');
ini_restore('error_log');
@unlink($payLog);
@unlink($keyPath);
@rmdir(dirname($keyPath));
@unlink($mailLog);

// --- Modules: optional functions of the core ------------------------------------
check('modules: the page needs the settings permission', $get('/admin/modules', 1)['status'] === 403 && $post('/admin/modules', ['modules' => []], 2)['status'] === 403);
$r = $get('/admin/modules', 3);
check('modules: everything is on until switched off', $r['status'] === 200 && substr_count($r['body'], 'name="modules[]"') === 9 && substr_count($r['body'], ' checked') === 9);
$post('/admin/modules', ['modules' => ['contact', 'avatars', 'nonsense', ['x']]], 3);
check('modules: switched off is stored, unknown names are ignored', $pdo->query("SELECT value FROM setting WHERE name = 'core.modules_disabled'")->fetchColumn() === 'reviews,withdrawal,reports,remember_login,inbox,notifications,requests');
check('modules: without inbox there is no envelope, no overview page, no unread polling, and no account-nav link', !str_contains($get('/account', 1)['body'], 'href="/account/messages"') && !str_contains($get('/account', 1)['body'], 'data-unread')
    && $get('/account/messages', 1)['status'] === 404 && $get('/account/unread', 1)['status'] === 404 && !str_contains($get('/account/settings', 1)['body'], 'href="/account/messages"'));
check('modules: without notifications there is no bell, no overview page, no unread polling, and no account-nav link', !str_contains($get('/account', 1)['body'], 'href="/account/notifications"')
    && $get('/account/notifications', 1)['status'] === 404 && $get('/account/notifications/unread', 1)['status'] === 404 && !str_contains($get('/account/settings', 1)['body'], 'href="/account/notifications"'));
$notifCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM notification')->fetchColumn();
(new Modulento\Core\App($config, $pdo))->notifications->create(1, 'order_state', 'core.notification.order_state', ['number' => '000001', 'title' => 'x', 'event' => 'x'], '/orders/1');
check('modules: switched off, the service writes nothing either - not just the routes', $pdo->query('SELECT COUNT(*) FROM notification')->fetchColumn() == $notifCountBefore);
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'freelancer'");
$home = $get('/', null)['body'];
check('modules: without withdrawal and reports their footer links are gone', !str_contains($home, 'href="/withdrawal"') && !str_contains($home, '/report'));
check('modules: their pages are gone', $get('/withdrawal', null)['status'] === 404 && $get('/report', null)['status'] === 404 && $get('/admin/reviews', 3)['status'] === 404
    && $get('/admin/withdrawals', 3)['status'] === 404 && $get('/admin/reports', 3)['status'] === 404 && $post('/orders/1/review', ['rating' => '5'], 2)['status'] === 404);
$r = $get('/admin', 3);
check('modules: and so are their menu entries and permissions', !str_contains($r['body'], 'href="/admin/reviews"') && !str_contains($r['body'], 'href="/admin/reports"') && !str_contains($r['body'], 'href="/admin/withdrawals"')
    && !str_contains($get('/admin/roles/new', 3)['body'], 'core.reviews.manage'));
check('modules: no "stay logged in" without the module', !str_contains($get('/login', null)['body'], 'name="remember"') && !str_contains($get('/account/settings', 1)['body'], '/account/sessions/revoke'));
$post('/login', ['email' => 'plain@example.test', 'password' => 'correct horse battery', 'remember' => '1'], null);
check('modules: and a ticked box from an old form remembers nothing', $pdo->query('SELECT COUNT(*) FROM account_login_token')->fetchColumn() == 0);
check('modules: what is still on works', str_contains($get('/account/settings', 1)['body'], 'action="/account/avatar"'));
$post('/admin/modules', ['modules' => []], 3);
check('modules: everything can be off at once', $get('/account/settings', 1)['status'] === 200 && !str_contains($get('/account/settings', 1)['body'], 'action="/account/avatar"') && $post('/account/avatar', [], 1)['status'] === 404);
check('modules: without requests its routes and nav links are gone too', $get('/requests', null)['status'] === 404 && $get('/account/requests', 1)['status'] === 404 && $get('/admin/requests', 3)['status'] === 404
    && !str_contains($get('/', null)['body'], 'href="/requests"') && !str_contains($get('/admin', 3)['body'], 'href="/admin/requests"'));
$post('/admin/modules', ['modules' => array_keys(Modulento\Core\Support\Modules::ALL)], 3);
check('modules: switched on again, everything is back', str_contains($get('/', null)['body'], 'href="/withdrawal"') && $get('/report', null)['status'] === 200 && str_contains($get('/login', null)['body'], 'name="remember"'));
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'freelancer'");
$pdo->exec("DELETE FROM setting WHERE name = 'core.modules_disabled'");
$pdo->exec('DELETE FROM rate_limit_attempt');

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
    preg_match_all("/(?:trans\\(|flash\\('[a-z]+', \\\$this->trans\\(|'key' => |back\\([^,]+, '[a-z]+', |Key\\) => |return |\\\$errors\\[\\] = |UpdateException\\(|result\\([a-z]+, '[a-z_]+', )'((?:core|" . implode('|', array_map('basename', glob($root . '/extensions/*', GLOB_ONLYDIR))) . ")\\.[a-z0-9_.]+[a-z0-9])'/", (string) file_get_contents($file), $found);
    foreach ($found[1] as $key) {
        // Offer type ids look like keys but are not.
        if (!isset($knownKeys[$key]) && !in_array($key, ['freelancer.service', 'auction.lot', 'auction.sale', 'core.offline', 'shop.product'], true)) {
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

// --- Header menu, colour scheme, "show password" ------------------------------
$r = $get('/', null);
check('header: a visitor gets the login link, no dashboard link and no account menu', $r['status'] === 200 && str_contains($r['body'], 'href="/login"')
    && !str_contains($r['body'], 'href="/account"') && !str_contains($r['body'], 'account-menu') && !str_contains($r['body'], 'action="/logout"'));
check('colour scheme: a visitor\'s page leaves the choice to the device', str_contains($r['body'], '<html lang="de">'));
$r = $get('/', 1);
preg_match('#<details class="account-menu">.*?</details>#s', $r['body'], $menu);
check('header: an account gets "Dashboard" and its menu', str_contains($r['body'], '<a href="/account">Dashboard</a>') && ($menu[0] ?? '') !== '' && !str_contains($r['body'], 'Mein Konto'));
check('header: the menu leads to the settings and logs out by a form with a token', str_contains($menu[0] ?? '', '<a href="/account/settings">Profileinstellungen</a>')
    && preg_match('#<form method="post" action="/logout">\s*<input type="hidden" name="_csrf" value="test-token">\s*<button type="submit" class="link-button">Abmelden</button>#', $menu[0] ?? '') === 1
    && substr_count($r['body'], 'action="/logout"') === 1);
check('header: the menu has a name for screen readers and an initial as placeholder', str_contains($menu[0] ?? '', '<span class="visually-hidden">Kontomenü</span>')
    && str_contains($menu[0] ?? '', '<span class="account-menu-picture" aria-hidden="true">P</span>'));
check('header: no link to the administration without the permission', !str_contains($r['body'], 'href="/admin"') && !str_contains($get('/', 2)['body'], 'href="/admin"'));
check('header: "Administration" with the permission', str_contains($get('/', 3)['body'], '<a href="/admin">Administration</a>'));
check('header: the same in English', str_contains($get('/en', 3)['body'], '<a href="/en/admin">Administration</a>') && str_contains($get('/en', 3)['body'], '<a href="/en/account/settings">Profile settings</a>'));
$_FILES = ['avatar' => ['tmp_name' => $makeImage(80, 80), 'error' => UPLOAD_ERR_OK]];
$post('/account/avatar', [], 1);
$_FILES = [];
check('header: the menu shows the account\'s picture once it has one', preg_match('#<img class="account-menu-picture" src="/media/avatars/[a-f0-9]{32}\.(webp|jpg)" alt=""#', $get('/', 1)['body']) === 1
    && !str_contains($get('/', 2)['body'], '/media/avatars/'));
$post('/account/avatar/delete', [], 1);
$r = $get('/account', 1);
check('account pages: the overview is called "Dashboard"', str_contains($r['body'], '<title>Dashboard – ') && str_contains($r['body'], '<a href="/account">Dashboard</a>'));
$r = $get('/admin', 3);
check('administration: called "Administration" in title and menu', str_contains($r['body'], '<title>Administration – ') && str_contains($r['body'], 'aria-label="Administration"') && str_contains($r['body'], '<html lang="de">'));

$schemeOf = fn (int $id) => $pdo->query("SELECT value FROM account_preference WHERE name = 'color_scheme' AND account_id = {$id}")->fetchColumn();
$r = $get('/account/settings', 1);
check('colour scheme: the account menu offers three choices, "automatic" chosen', preg_match('#<form method="post" action="/account/appearance" class="account-menu-scheme">.*?value="auto" aria-pressed="true">Auto</button>\s*<button type="submit" name="color_scheme" value="light" aria-pressed="false">Hell</button>\s*<button type="submit" name="color_scheme" value="dark" aria-pressed="false">Dunkel</button>#s', $r['body']) === 1
    && str_contains($r['body'], '<input type="hidden" name="return" value="/account/settings">'));
$post('/account/appearance', ['color_scheme' => 'dark'], 1);
check('colour scheme: a fixed choice is stored for the account', $schemeOf(1) === 'dark' && isset($_SESSION['_flash']['success']));
$r = $get('/account/settings', 1);
check('colour scheme: a fixed choice appears as data-theme and in the form', str_contains($r['body'], '<html lang="de" data-theme="dark">') && str_contains($r['body'], 'value="dark" aria-pressed="true"'));
$r = $post('/account/appearance', ['color_scheme' => 'dark', 'return' => '/offers'], 1);
check('colour scheme: chosen from the menu, the page one was on comes back without a detour over the settings', !isset($_SESSION['_flash']['success']));
foreach (['//evil.example', 'https://evil.example/', '/\\evil', "/a\nb"] as $elsewhere) {
    $post('/account/appearance', ['color_scheme' => 'dark', 'return' => $elsewhere], 1);
    check('colour scheme: never back to another site (' . json_encode($elsewhere) . ')', isset($_SESSION['_flash']['success']));
}
$r = $get('/admin', 3);
$r = $get('/admin/docs', 3);
check('documentation: in the administration, in the administrator\'s language, with the examples as text', $r['status'] === 200 && str_contains($r['body'], 'Ein Theme bauen') && str_contains($r['body'], '&lt;?php')
    && str_contains($r['body'], '{{ theme_asset(') && str_contains($r['body'], 'href="/admin/docs"') && str_contains($get('/en/admin/docs', 3)['body'], 'Building a theme') && $get('/admin/docs', 1)['status'] === 403);
$r = $get('/admin', 3);
preg_match_all('#<p class="admin-menu-group" id="menu-group-([a-z]+)">#', $r['body'], $sections);
check('administration: the menu comes in sections, in a fixed order', array_values(array_intersect(Modulento\Core\App::ADMIN_GROUPS, $sections[1])) === $sections[1]
    && array_intersect(['content', 'marketplace', 'moderation', 'people', 'system'], $sections[1]) === ['content', 'marketplace', 'moderation', 'people', 'system']
    && preg_match('#id="menu-group-people">.*?href="/admin/accounts".*?href="/admin/roles".*?id="menu-group-system"#s', $r['body']) === 1);
$extensionApp = new Modulento\Core\App($config, $pdo);
$extensionApp->addAdminMenu('x.menu', '/admin/x', 'x.manage', 'nonsense');
check('administration: an entry without a known section goes to "more"', $extensionApp->adminMenu()[0]['group'] === 'more');
$r = $get('/admin', 2);
preg_match_all('#<p class="admin-menu-group" id="menu-group-([a-z]+)">#', $r['body'], $sections);
check('administration: a section the account sees nothing of is left out', !in_array('people', $sections[1], true) && !in_array('system', $sections[1], true));
$r = $get('/admin/pages/new', 3);
preg_match('#data-editor="([^"]*)"#', $r['body'], $editor);
check('editor: the page text gets the editor, its wording in the administrator\'s language', ($editor[1] ?? '') !== '' && (json_decode(html_entity_decode($editor[1]), true)['bold'] ?? null) === 'Fett'
    && str_contains($r['body'], 'editor.js') && file_exists($root . '/themes/admin/assets/editor.js'));
check('editor: the "close" wording for the in-place edit dialog is in the same labels, and inline-edit.js builds it', (json_decode(html_entity_decode($editor[1]), true)['close'] ?? null) === 'Schließen'
    && str_contains((string) file_get_contents($root . '/themes/admin/assets/inline-edit.js'), 'inline-edit-dialog'));
$r = $get('/admin/roles/new', 3);
check('roles: permissions come grouped like the menu, with an explanation', preg_match('#<legend>Allgemein</legend>.*?value="core.admin.access".*?<legend>Inhalte</legend>.*?value="core.pages.manage".*?<legend>Personen</legend>.*?value="core.roles.manage"#s', $r['body']) === 1
    && str_contains($r['body'], 'Ohne dieses Recht sieht die Rolle die Administration gar nicht'));
$r = $get('/admin/roles/new?preset=support', 3);
check('roles: a template fills in name and permissions', str_contains($r['body'], 'value="Kundendienst"') && preg_match('#value="core.orders.manage" checked#', $r['body']) === 1
    && preg_match('#value="core.accounts.manage" checked#', $r['body']) === 1 && preg_match('#value="core.pages.manage" checked#', $r['body']) === 0);
check('roles: an unknown template is an empty form', !str_contains($get('/admin/roles/new?preset=x', 3)['body'], ' checked') && $get('/admin/roles/new?preset[]=x', 3)['status'] === 200);
check('administration: the top bar has the same account menu', str_contains($r['body'], '<details class="account-menu">') && str_contains($r['body'], 'action="/account/appearance"') && str_contains($r['body'], 'account-menu.js'));
check('colour scheme: it is the account\'s own', str_contains($get('/', 2)['body'], '<html lang="de">') && str_contains($get('/', null)['body'], '<html lang="de">'));
foreach (['pink', '', ['dark'], 'DARK'] as $invalid) {
    $post('/account/appearance', ['color_scheme' => $invalid], 1);
    check('colour scheme: ' . json_encode($invalid) . ' is refused and the choice stays', $schemeOf(1) === 'dark' && str_contains($_SESSION['_flash']['error'] ?? '', 'Farbschemata'));
}
$r = $post('/account/appearance', ['color_scheme' => 'light', '_csrf' => 'wrong'], 1);
check('colour scheme: not changed without the token', $schemeOf(1) === 'dark');
$post('/account/appearance', ['color_scheme' => 'light'], 1);
check('colour scheme: changing replaces the one row', $schemeOf(1) === 'light' && $pdo->query('SELECT COUNT(*) FROM account_preference')->fetchColumn() == 1
    && str_contains($get('/offers', 1)['body'], '<html lang="de" data-theme="light">'));
check('colour scheme: a visitor cannot set one', $post('/account/appearance', ['color_scheme' => 'dark'], null)['status'] === 403 && $pdo->query('SELECT COUNT(*) FROM account_preference')->fetchColumn() == 1);
$post('/account/appearance', ['color_scheme' => 'dark'], 3);
$r = $get('/admin', 3);
check('colour scheme: the administration takes the account\'s choice', str_contains($r['body'], '<html lang="de" data-theme="dark">') && str_contains($get('/admin/settings', 3)['body'], 'data-theme="dark"'));
check('colour scheme: part of the data export', (json_decode($get('/account/export', 3)['body'], true)['preferences'] ?? null) === ['color_scheme' => 'dark']);
$post('/account/appearance', ['color_scheme' => 'auto'], 3);
$post('/account/appearance', ['color_scheme' => 'auto'], 1);
check('colour scheme: "automatic" needs no row and writes no attribute', $pdo->query('SELECT COUNT(*) FROM account_preference')->fetchColumn() == 0
    && str_contains($get('/account/settings', 1)['body'], '<html lang="de">') && str_contains($get('/admin', 3)['body'], '<html lang="de">'));
// Word filter in the administration: kept in the settings, the shipped lists apply until someone saves one.
check('badwords admin: only administrators with the settings permission see the word filter', $get('/admin/badwords', 3)['status'] === 200
    && $get('/admin/badwords', 1)['status'] === 403 && str_contains($get('/admin/badwords', 3)['body'], 'name="words"'));
$post('/admin/badwords', ['words' => "Blödmann, Schrott\nschrott\n\n  "], 3);
check('badwords admin: a saved list replaces the shipped one, duplicates and blanks dropped', $pdo->query("SELECT value FROM setting WHERE name = 'core.badwords'")->fetchColumn() === "blödmann\nschrott"
    && $words()->find('Du Schrott!') === 'schrott' && $words()->find('Scheiße') === null && $words()->isCustomized());
$post('/admin/badwords', ['words' => " , \n"], 3);
check('badwords admin: an empty list is refused and the saved one stays', str_contains($_SESSION['_flash']['error'] ?? '', 'leer') && $words()->find('Schrott') === 'schrott');
$post('/admin/badwords/reset', [], 3);
check('badwords admin: back to the shipped lists', !$words()->isCustomized() && $words()->find('Scheiße') === 'scheisse' && $words()->find('Schrott') === null);
$post('/admin/badwords', ['words' => 'Doof'], 1);
check('badwords admin: a visitor or an account without the permission cannot change it', $pdo->query("SELECT COUNT(*) FROM setting WHERE name = 'core.badwords'")->fetchColumn() == 0);

// Every method a controller calls on itself exists (an update once called a method that had been renamed).
foreach (glob($root . '/core/src/Controller/*.php') as $file) {
    $name = basename($file, '.php');
    $source = (string) file_get_contents($file);
    preg_match_all('/\$this->(\w+)\(/', $source, $calls);
    $missing = array_filter(array_unique($calls[1]), fn (string $method) => !method_exists('Modulento\\Core\\Controller\\' . $name, $method));
    check('controllers: ' . $name . ' calls only methods it has' . ($missing === [] ? '' : ': ' . implode(', ', $missing)), $missing === []);
}

// Packages: a GitHub address names its repository; a zip that is no package is refused.
check('packages: a GitHub address is read as its repository', Modulento\Core\Package\Packages::repoFromInput('https://github.com/acme/modulento-ext-x.git') === 'acme/modulento-ext-x'
    && Modulento\Core\Package\Packages::repoFromInput(' www.github.com/acme/tool/tree/main ') === 'acme/tool' && Modulento\Core\Package\Packages::repoFromInput('acme/tool') === 'acme/tool');
$notZip = tempnam(sys_get_temp_dir(), 'pkg');
file_put_contents($notZip, 'Das ist kein ZIP.');
$_FILES = ['package' => ['tmp_name' => $notZip, 'error' => UPLOAD_ERR_OK, 'size' => filesize($notZip), 'name' => 'paket.zip', 'type' => 'application/zip']];
$post('/admin/packages/upload', [], 3);
$_FILES = [];
unlink($notZip);
check('packages: an upload that is no package is refused', str_contains($_SESSION['_flash']['error'] ?? '', 'kein gültiges Paket'));
check('packages: uploads cannot be made by an account without the permission', $get('/admin/packages', 1)['status'] === 403);

// The home page: its blocks in the order an administrator sets, texts per language, cleaned on the way in.
$homeBlocks = fn () => json_decode((string) $pdo->query("SELECT value FROM setting WHERE name = 'core.home_layout'")->fetchColumn(), true);
$idOf = fn (string $type) => array_values(array_filter($homeBlocks() ?? [], fn (array $b) => $b['type'] === $type))[0]['id'] ?? null;
check('home editor: the default page shows a title area and the latest offers, the editor needs its permission', str_contains($get('/', null)['body'], 'Willkommen bei')
    && $get('/admin/home', 3)['status'] === 200 && $get('/admin/home', 1)['status'] === 403);
$post('/admin/home', ['action' => 'add', 'add_type' => 'text'], 3);
$textId = $idOf('text');
$blocksNow = $homeBlocks();
check('home editor: a block can be added to the end of the page', $textId !== null && end($blocksNow)['type'] === 'text');
$post('/admin/home', ['action' => 'save', 'blocks' => [
    'default-hero' => ['enabled' => '1', 'texts' => ['de' => ['title' => 'Hallo Testwelt', 'button_label' => 'Angebote', 'button_url' => '/offers', 'button_url_x' => '']]],
    'default-offers' => ['enabled' => '1', 'settings' => ['count' => '99'], 'texts' => ['de' => ['heading' => 'Neu hier']]],
    $textId => ['enabled' => '1', 'texts' => ['de' => ['heading' => 'Über uns', 'body' => '<p>Wir <strong>gern</strong>.</p><script>alert(1)</script>']]],
]], 3);
// English is edited on the English page: the header language decides which texts a form changes.
$post('/en/admin/home', ['action' => 'save', 'blocks' => [
    'default-hero' => ['enabled' => '1', 'texts' => ['en' => ['title' => 'Hello test world', 'button_url' => 'javascript:alert(1)']]],
    $textId => ['enabled' => '1', 'texts' => ['en' => ['heading' => '', 'body' => '<p>We like it.</p>']]],
]], 3);
$stored = $homeBlocks();
$textBlock = array_values(array_filter($stored, fn (array $b) => $b['type'] === 'text'))[0];
check('home editor: a language edits only its own texts; the others are kept', $stored[0]['texts']['de']['title'] === 'Hallo Testwelt' && $stored[0]['texts']['en']['title'] === 'Hello test world'
    && $stored[0]['texts']['en']['button_url'] === '' && $textBlock['texts']['en']['body'] === '<p>We like it.</p>' && $textBlock['texts']['de']['heading'] === 'Über uns');
check('home editor: texts are kept per language, a script in a text is removed, a javascript address is dropped, a count is limited', $stored[0]['texts']['de']['title'] === 'Hallo Testwelt'
    && $stored[0]['texts']['en']['button_url'] === '' && array_values(array_filter($stored, fn (array $b) => $b['id'] === 'default-offers'))[0]['settings']['count'] === 12 && !str_contains(json_encode($stored), 'alert(1)<') && !str_contains(json_encode($stored), '<script'));
check('home: the visitor sees the text, the cleaned HTML, and the English title where there is one', str_contains($get('/', null)['body'], 'Über uns') && str_contains($get('/', null)['body'], '<strong>gern</strong>')
    && !str_contains($get('/', null)['body'], '<script>alert') && str_contains($get('/en', null)['body'], 'Hello test world') && str_contains($get('/en', null)['body'], 'Über uns'));
$post('/admin/home', ['action' => 'up:' . $textId], 3);
check('home editor: a block moves up, and a step saves the form first', (function () use ($homeBlocks, $textId) { $pos = array_search($textId, array_column($homeBlocks(), 'id')); return $pos > 0 && $homeBlocks()[$pos]['texts']['de']['heading'] === 'Über uns'; })());
$post('/admin/home', ['action' => 'down:' . $textId], 3);
$post('/admin/home', ['action' => 'save', 'blocks' => [$textId => ['texts' => ['de' => ['heading' => 'Über uns']]], 'default-hero' => ['enabled' => '1', 'texts' => ['de' => ['title' => 'Hallo Testwelt']]]]], 3);
check('home editor: an unchecked block is not shown', str_contains($get('/', null)['body'], 'Über uns') === false && str_contains($get('/', null)['body'], 'Hallo Testwelt'));
$post('/admin/home', ['action' => 'delete:' . $textId], 3);
check('home editor: a block can be removed', $idOf('text') === null);
$post('/admin/home', ['action' => 'save', 'blocks' => ['default-hero' => ['enabled' => '1', 'texts' => ['de' => ['title' => 'Hallo']]]]], 3);
$post('/admin/home', ['action' => 'add', 'add_type' => 'links'], 3);
$post('/admin/home', ['action' => 'add', 'add_type' => 'image'], 3);
$post('/admin/home', ['action' => 'save', 'blocks' => [$idOf('image') => ['enabled' => '1', 'settings' => ['media' => '/media/library/../evil.png'], 'texts' => ['de' => ['caption' => 'Bild']]], $idOf('links') => ['enabled' => '1', 'texts' => ['de' => ['heading' => 'Mehr', 'items' => "Impressum | /impressum\nSeite ohne Adresse\nBöse | javascript:x\nExtern | https://example.org"]]]]], 3);
$lastBlocks = $homeBlocks();
check('home editor: a picture outside the library and a link without a safe address are not kept', ($lastBlocks[count($lastBlocks) - 1]['settings']['media'] ?? null) === ''
    && $lastBlocks[count($lastBlocks) - 2]['texts']['de']['items'] === "Impressum | /impressum\nExtern | https://example.org");
$body = $get('/', null)['body'];
check('home: links of the list are shown, paths in the language of the page', str_contains($body, 'href="/impressum"') && str_contains($body, 'href="https://example.org"') && !str_contains($body, 'javascript:x'));

// --- A block's own background: colour/gradient/image, never inline (the site's CSP has no style-src 'unsafe-inline') ---
// The hero always renders regardless of its text, unlike "links" (empty without items) - a settings-only
// save (no "texts" key at all) would otherwise wipe its text fields back to empty, per fromForm()'s own rule.
$post('/admin/home', ['action' => 'save', 'blocks' => ['default-hero' => ['enabled' => '1', 'settings' => [
    'background_type' => 'gradient', 'background_color' => '#112233', 'background_color2' => '#445566',
], 'texts' => ['de' => ['title' => 'Hallo']]]]], 3);
$heroBlock = array_values(array_filter($homeBlocks(), fn (array $b) => $b['id'] === 'default-hero'))[0];
check('home editor: a background is saved for any block type, not gated by its text fields', $heroBlock['settings']['background_type'] === 'gradient'
    && $heroBlock['settings']['background_color'] === '#112233' && $heroBlock['settings']['background_color2'] === '#445566');

$homePage = $get('/', null)['body'];
check('home: the block carries a data attribute (not an id, a theme may already have its own), for the background rule to target', str_contains($homePage, 'data-home-block="default-hero"'));
preg_match('#href="(/design/[a-f0-9]+\.css)"#', $homePage, $cssMatch);
$designCss = $get($cssMatch[1], null)['body'];
check('home: the design stylesheet carries the block\'s gradient, scoped to its own attribute - never an inline style attribute', str_contains($designCss, '[data-home-block="default-hero"] {')
    && str_contains($designCss, 'background: linear-gradient(135deg, #112233, #445566);') && !str_contains($homePage, 'style="'));

$post('/admin/home', ['action' => 'save', 'blocks' => ['default-hero' => ['enabled' => '1', 'settings' => ['background_type' => 'color', 'background_color' => 'not-a-colour'], 'texts' => ['de' => ['title' => 'Hallo']]]]], 3);
$heroBlock = array_values(array_filter($homeBlocks(), fn (array $b) => $b['id'] === 'default-hero'))[0];
check('home editor: an invalid colour is refused, nothing stored for it', !isset($heroBlock['settings']['background_color']));

$heroImage = '/media/library/' . str_repeat('c', 32) . '.webp';
$post('/admin/home', ['action' => 'save', 'blocks' => ['default-hero' => ['enabled' => '1', 'settings' => [
    'background_type' => 'image', 'background_media' => $heroImage,
], 'texts' => ['de' => ['title' => 'Hallo']]]]], 3);
$heroBlock = array_values(array_filter($homeBlocks(), fn (array $b) => $b['id'] === 'default-hero'))[0];
check('home editor: a picture background is saved, from the media library only', $heroBlock['settings']['background_type'] === 'image' && $heroBlock['settings']['background_media'] === $heroImage);
$designCss = $get('/design/' . $app->design->fileName(), null)['body'];
check('home: the design stylesheet carries the block\'s picture background, as a cover image, never inline', str_contains($designCss, '[data-home-block="default-hero"] {')
    && str_contains($designCss, 'background-image: url(' . $heroImage . '); background-size: cover; background-position: center;') && !str_contains($get('/', null)['body'], 'style="'));
check('home: the design stylesheet styles the in-place edit dialog, and the hover pencil is white with a dark halo - not the accent colour, which a block\'s own background may clash with',
    str_contains($designCss, '.inline-edit-dialog {') && str_contains($designCss, '.inline-editable:hover::after { content: " ✎"; color: #fff; text-shadow:'));
$post('/admin/home', ['action' => 'save', 'blocks' => ['default-hero' => ['enabled' => '1', 'settings' => ['background_type' => 'image', 'background_media' => '/media/library/../evil.png'], 'texts' => ['de' => ['title' => 'Hallo']]]]], 3);
$heroBlock = array_values(array_filter($homeBlocks(), fn (array $b) => $b['id'] === 'default-hero'))[0];
check('home editor: a picture background outside the media library is not kept', ($heroBlock['settings']['background_media'] ?? null) === '');

// Text formatting from the editor's toolbar (font, colour, size, as a span) survives a save and reaches the visitor.
$post('/admin/home', ['action' => 'add', 'add_type' => 'text'], 3);
$formattedId = $idOf('text');
$post('/admin/home', ['action' => 'save', 'blocks' => [$formattedId => ['enabled' => '1', 'texts' => ['de' => [
    'heading' => 'Formatiert', 'body' => '<p><span class="text-font-serif text-size-lg" style="color: #112233">Großer Text</span>, <span class="evil" style="position: fixed">unverändert</span>.</p>',
]]]]], 3);
$formattedBlock = array_values(array_filter($homeBlocks(), fn (array $b) => $b['id'] === $formattedId))[0];
check('home editor: a formatted span (font, size, colour) in a text body is kept, an unknown class and style property are not',
    $formattedBlock['texts']['de']['body'] === '<p><span class="text-font-serif text-size-lg" style="color: #112233">Großer Text</span>, unverändert.</p>');
check('home: the visitor sees the formatted span as the editor set it', str_contains($get('/', null)['body'], '<span class="text-font-serif text-size-lg" style="color: #112233">Großer Text</span>'));
$post('/admin/home', ['action' => 'delete:' . $formattedId], 3);

check('home editor: the form shows the language of the header only, no language tabs', str_contains($get('/admin/home', 3)['body'], '[texts][de][title]')
    && !str_contains($get('/admin/home', 3)['body'], '[texts][en]') && str_contains($get('/en/admin/home', 3)['body'], '[texts][en][title]')
    && !str_contains($get('/en/admin/home', 3)['body'], '[texts][de]') && str_contains($get('/en/admin/home', 3)['body'], 'placeholder="Hallo"'));
$post('/admin/home', ['action' => 'reset'], 3);
check('home editor: a reset brings back the default page', !$pdo->query("SELECT COUNT(*) FROM setting WHERE name = 'core.home_layout'")->fetchColumn() && str_contains($get('/', null)['body'], 'Willkommen bei'));
$post('/admin/home', ['action' => 'save', 'blocks' => []], 1);
check('home editor: a visitor without the permission cannot change it', !$pdo->query("SELECT COUNT(*) FROM setting WHERE name = 'core.home_layout'")->fetchColumn());

// The main menu: entries the operator adds, next to the fixed catalogue/requests links and
// whatever an extension already contributes with Registrar::navigation() - the fixed ones can
// be relabelled, reordered and hidden the same way, but not removed, as they have no path of their own.
$menuItems = fn () => json_decode((string) $pdo->query("SELECT value FROM setting WHERE name = 'core.custom_menu'")->fetchColumn(), true);
$customItems = fn () => array_values(array_filter($menuItems() ?? [], fn (array $i) => !str_starts_with($i['id'], 'core.') && !str_starts_with($i['id'], 'ext.')));
check('menu editor: empty at first, the editor needs its permission', ($menuItems() ?? []) === []
    && $get('/admin/menu', 3)['status'] === 200 && $get('/admin/menu', 1)['status'] === 403);
check('menu editor: the fixed "requests" entry (on by default) is listed, locked, with no delete button of its own',
    str_contains($get('/admin/menu', 3)['body'], 'item-core.requests') && !str_contains($get('/admin/menu', 3)['body'], 'delete:core.requests'));
$post('/admin/menu', ['action' => 'save', 'items' => ['core.requests' => ['enabled' => '1', 'label' => 'Support-Anfragen']]], 3);
check('menu editor: a fixed entry can be relabelled, and the visitor sees the new label', str_contains($get('/', null)['body'], '>Support-Anfragen<'));
$post('/admin/menu', ['action' => 'save', 'items' => ['core.requests' => ['label' => 'Support-Anfragen']]], 3);
check('menu editor: unchecking "enabled" hides a fixed entry for the visitor, but it stays in the editor',
    !str_contains($get('/', null)['body'], '>Support-Anfragen<') && str_contains($get('/admin/menu', 3)['body'], 'item-core.requests'));
$post('/admin/menu', ['action' => 'delete:core.requests'], 3);
check('menu editor: a fixed entry cannot be removed', str_contains($get('/admin/menu', 3)['body'], 'item-core.requests'));
$post('/admin/menu', ['action' => 'save', 'items' => ['core.requests' => ['enabled' => '1', 'label' => 'Support-Anfragen']]], 3);
$post('/admin/menu', ['action' => 'add'], 3);
$menuId = $customItems()[0]['id'];
check('menu editor: an entry can be added', $menuId !== null);
$post('/admin/menu', ['action' => 'save', 'items' => [$menuId => ['enabled' => '1', 'path' => '/impressum', 'label' => 'Impressum'], 'core.requests' => ['enabled' => '1', 'label' => 'Support-Anfragen']]], 3);
check('menu editor: an entry is saved with its path and label', $customItems()[0]['path'] === '/impressum' && $customItems()[0]['texts']['de']['label'] === 'Impressum');
check('menu: the visitor sees the entry in the header, after what is already there', str_contains($get('/', null)['body'], 'href="/impressum">Impressum<'));
$post('/admin/menu', ['action' => 'save', 'items' => [$menuId => ['enabled' => '1', 'path' => 'javascript:alert(1)', 'label' => 'Böse'], 'core.requests' => ['enabled' => '1', 'label' => 'Support-Anfragen']]], 3);
check('menu editor: an unsafe address is dropped, so the entry has no target and is not shown', $customItems()[0]['path'] === '' && !str_contains($get('/', null)['body'], 'Böse'));
$post('/admin/menu', ['action' => 'save', 'items' => [$menuId => ['enabled' => '1', 'path' => '/impressum', 'label' => 'Impressum'], 'core.requests' => ['enabled' => '1', 'label' => 'Support-Anfragen']]], 3);
$post('/en/admin/menu', ['action' => 'save', 'items' => [$menuId => ['enabled' => '1', 'path' => '/impressum', 'label' => 'Imprint'], 'core.requests' => ['enabled' => '1', 'label' => 'Support requests']]], 3);
check('menu editor: a language edits only its own label; the others are kept, and the visitor sees its own language\'s label',
    $customItems()[0]['texts']['de']['label'] === 'Impressum' && $customItems()[0]['texts']['en']['label'] === 'Imprint'
    && str_contains($get('/en', null)['body'], '>Imprint<') && str_contains($get('/', null)['body'], '>Impressum<'));
$post('/admin/menu', ['action' => 'add'], 3);
$secondId = array_values(array_filter($customItems(), fn (array $i) => $i['id'] !== $menuId))[0]['id'];
$post('/admin/menu', ['action' => 'up:' . $secondId], 3);
check('menu editor: an entry moves up, and a step saves the form first', $customItems()[0]['id'] === $secondId && $customItems()[1]['texts']['de']['label'] === 'Impressum');
check('menu editor: an entry without a label is left out for the visitor, but stays in the editor', count($customItems()) === 2 && str_contains($get('/', null)['body'], '>Impressum<'));
check('menu editor: dragging an entry needs the permission too', $post('/admin/menu/reorder', ['order' => [$menuId, 'core.requests', $secondId]], 1)['status'] === 403);
$r = $post('/admin/menu/reorder', ['order' => [$menuId, 'core.requests', $secondId]], 3);
$byId = fn () => array_column($menuItems(), null, 'id');
check('menu editor: dragging saves the new order right away, as JSON, leaving every entry\'s own fields untouched',
    $r['status'] === 200 && str_contains($r['body'], '"ok":true')
    && array_column($menuItems(), 'id') === [$menuId, 'core.requests', $secondId]
    && $byId()[$menuId]['texts']['de']['label'] === 'Impressum');
$post('/admin/menu/reorder', ['order' => ['core.requests', $secondId]], 3);
check('menu editor: an id the request does not name (here: menuId left out) keeps its place at the end, nothing is lost',
    array_column($menuItems(), 'id') === ['core.requests', $secondId, $menuId]);
$post('/admin/menu', ['action' => 'delete:' . $secondId], 3);
$post('/admin/menu', ['action' => 'delete:' . $menuId], 3);
check('menu editor: a custom entry can be removed, the fixed one stays', $customItems() === [] && str_contains($get('/admin/menu', 3)['body'], 'item-core.requests'));
$post('/admin/menu', ['action' => 'add'], 1);
check('menu editor: a visitor without the permission cannot change it', $customItems() === []);

// Design: colours, font and radius, written as a stylesheet the pages load; only administrators change them.
check('design: the pages load the stylesheet of the values, and the default values are the theme\'s', str_contains($get('/', null)['body'], '<link rel="stylesheet" href="/design/')
    && str_contains($get('/design/' . $app->design->fileName(), null)['body'], '--accent: #1f5fbf'));
$post('/admin/design', ['accent' => '#00aa55', 'ground' => '#fffdf5', 'text' => '#222222', 'font' => 'serif', 'radius' => '12'], 3);
$css = $get('/design/' . $app->design->fileName(), null)['body'];
check('design: saved values are written to the stylesheet, for the light scheme only', str_contains($css, '--accent: #00aa55') && str_contains($css, '--radius: 12px')
    && str_contains($css, 'Georgia') && str_contains($css, 'prefers-color-scheme: light') && str_contains($css, 'data-theme="light"'));
check('design: the stylesheet carries the rules for every span class the text-formatting toolbar may produce, for every theme',
    str_contains($css, '.text-font-serif { font-family: Georgia') && str_contains($css, '.text-size-lg { font-size: 1.25em; }'));
$post('/admin/design', ['accent' => 'rot', 'font' => 'Comic', 'radius' => '99', 'ground' => '#abcdef', 'text' => '#111111'], 3);
$design = fn () => new Modulento\Core\Support\Design(new Modulento\Core\Support\Settings($pdo));
$values = $design()->values();
check('design: an invalid value keeps the one it had, a valid one is saved', $values['accent'] === '#00aa55' && $values['font'] === 'serif' && $values['radius'] === 12 && $values['ground'] === '#abcdef'
    && str_contains($_SESSION['_flash']['error'] ?? '', 'Nicht gespeichert'));
check('design: a visitor or an account without the permission cannot change it', $post('/admin/design', ['accent' => '#000000'], 1)['status'] === 403 && $design()->values()['accent'] === '#00aa55');
check('design: the administration has the form', str_contains($get('/admin/design', 3)['body'], 'name="accent" type="color"') && $get('/admin/design', 1)['status'] === 403);
$post('/admin/design/reset', [], 3);
check('design: a reset brings back the theme\'s values', !$design()->isCustomized() && $design()->values()['accent'] === '#1f5fbf');

// Accounts: an administrator creates one, and signs in as any account that is not an administrator, blocked or their own.
check('accounts: the form to create one needs the permission', $get('/admin/accounts/new', 3)['status'] === 200 && str_contains($get('/admin/accounts/new', 3)['body'], 'name="email"')
    && $get('/admin/accounts/new', 1)['status'] === 403);
$post('/admin/accounts/new', ['email' => 'Neu@Example.test', 'display_name' => 'Neu Benutzer', 'locale' => 'de', 'verified' => '1'], 3);
$neu = $pdo->query("SELECT * FROM account WHERE email = 'neu@example.test'")->fetch();
check('accounts: created without a password, confirmed, named, and the person gets the link to set one', $neu !== false && $neu['display_name'] === 'Neu Benutzer'
    && $neu['email_verified_at'] !== null && lastMail($mailLog, 'neu@example.test') !== null && str_contains($_SESSION['_flash']['success'] ?? '', 'angelegt'));
$post('/admin/accounts/new', ['email' => 'neu@example.test', 'locale' => 'de'], 3);
check('accounts: a second account with the same address is refused', str_contains($_SESSION['_flash']['error'] ?? '', 'schon ein Konto') && $pdo->query("SELECT COUNT(*) FROM account WHERE email = 'neu@example.test'")->fetchColumn() == 1);
$post('/admin/accounts/new', ['email' => 'mit@example.test', 'password' => 'correct horse battery', 'locale' => 'en'], 3);
check('accounts: with a password, it is set right away', $pdo->query("SELECT COUNT(*) FROM account WHERE email = 'mit@example.test'")->fetchColumn() == 1);
$neuId = (int) $neu['id'];
$post('/admin/accounts/' . $neuId . '/impersonate', [], 1);
check('sign-in as: a visitor or an account without the permission cannot', $get('/admin/accounts/' . $neuId, 1)['status'] === 403 && !isset($_SESSION['impersonator']));
$post('/admin/accounts/3/impersonate', [], 3);
check('sign-in as: not as oneself', str_contains($_SESSION['_flash']['error'] ?? '', 'nicht übernommen') && ($_SESSION['account_id'] ?? null) === 3);
$post('/admin/accounts/4/impersonate', [], 3);
check('sign-in as: not as a blocked account', str_contains($_SESSION['_flash']['error'] ?? '', 'nicht übernommen') && ($_SESSION['account_id'] ?? null) === 3);
$post('/admin/accounts/' . $neuId . '/impersonate', [], 3);
check('sign-in as: the account is signed in, the administrator is remembered, the last login is not changed', ($_SESSION['account_id'] ?? null) === $neuId
    && ($_SESSION['impersonator'] ?? null) === 3 && $pdo->query("SELECT last_login_at FROM account WHERE id = $neuId")->fetchColumn() === null);
$r = $get('/account', false);
check('sign-in as: the page says so, with the way back', str_contains($r['body'], 'Du bist als Neu Benutzer angemeldet') && str_contains($r['body'], 'Beenden und zurück zur Administration'));
$post('/account/email', ['email' => 'anders@example.test', 'current_password' => 'egal'], false);
$post('/account/delete', ['current_password' => 'egal'], false);
check('sign-in as: the address and the account cannot be changed or deleted', $pdo->query("SELECT COUNT(*) FROM account WHERE email = 'neu@example.test'")->fetchColumn() == 1
    && $pdo->query("SELECT COUNT(*) FROM account WHERE email = 'anders@example.test'")->fetchColumn() == 0 && str_contains($_SESSION['_flash']['error'] ?? '', 'nicht möglich'));
$post('/account/stop-impersonating', [], false);
check('sign-in as: back to the administrator\'s own account, and the end is logged', ($_SESSION['account_id'] ?? null) === 3 && !isset($_SESSION['impersonator'])
    && $pdo->query("SELECT COUNT(*) FROM admin_log WHERE action = 'end_impersonation' AND target_id = $neuId")->fetchColumn() == 1);
$start = $pdo->query("SELECT COUNT(*) FROM admin_log WHERE action = 'impersonate' AND target_id = $neuId")->fetchColumn();
check('sign-in as: every sign-in is logged, and the account page shows the log', $start >= 1 && str_contains($get('/admin/accounts/' . $neuId, 3)['body'], 'Als Benutzer angemeldet')
    && str_contains($get('/admin/accounts/' . $neuId, 3)['body'], 'Konto angelegt'));

// Editing in place: administrators see the tools with ?edit=1 on the home and offer page, visitors never; hidden blocks show while editing.
$post('/admin/home', ['action' => 'add', 'add_type' => 'links'], 3);
$inlineBlocks = $homeBlocks();
$linksId = array_values(array_filter($inlineBlocks, fn (array $b) => $b['type'] === 'links'))[0]['id'];
$post('/admin/home', ['action' => 'toggle:' . $linksId], 3);
check('in-place editing: off by default, on with ?edit=1 for administrators only', !str_contains($get('/', 3)['body'], 'inline-tools') && str_contains($get('/?edit=1', 3)['body'], 'inline-tools')
    && !str_contains($get('/?edit=1', null)['body'], 'inline-tools') && !str_contains($get('/?edit=1', 1)['body'], 'inline-tools'));
check('in-place editing: a hidden block shows its tools while editing, and it stays hidden for visitors', str_contains($get('/?edit=1', 3)['body'], 'id="inline-' . $linksId . '"')
    && str_contains($get('/?edit=1', 3)['body'], 'badge-disabled') && !str_contains($get('/', null)['body'], 'id="inline-' . $linksId . '"'));
check('in-place editing: each block has its own steps and text form, sent to the page\'s own action', str_contains($get('/?edit=1', 3)['body'], 'name="action" value="toggle:' . $linksId . '"')
    && str_contains($get('/?edit=1', 3)['body'], 'name="blocks[' . $linksId . '][texts][de][heading]"') && str_contains($get('/?edit=1', 3)['body'], 'name="return" value="/?edit=1"'));
$post('/admin/home', ['action' => 'toggle:' . $linksId], 3);
check('in-place editing: the toggle shows the block again', array_values(array_filter($homeBlocks(), fn (array $b) => $b['id'] === $linksId))[0]['enabled'] === true);
$post('/admin/home', ['action' => 'delete:' . $linksId], 3);
$reflect = new ReflectionMethod(Modulento\Core\Controller\Controller::class, 'safeReturn');
$probe = new class(new Modulento\Core\App($config, $pdo)) extends Modulento\Core\Controller\Controller {};
$safe = fn (string $ret) => (function () use ($reflect, $probe, $ret) { $_POST = ['return' => $ret]; $_GET = []; return $reflect->invoke($probe, '/admin/home'); })();
check('in-place editing: a return path must be a path of this site', $safe('/offers/x?edit=1') === '/offers/x?edit=1' && $safe('https://evil.example/') === '/admin/home'
    && $safe('//evil.example') === '/admin/home' && $safe('/\\evil') === '/admin/home' && $safe("/a\nb") === '/admin/home');
$_POST = [];

// Administration layout: the sidebar by default, or a header bar with a mega menu,
// chosen in the profile settings by administrators only.
check('admin layout: the sidebar is the default', str_contains($get('/admin', 3)['body'], '<body class="layout-sidebar">') && str_contains($get('/admin', 3)['body'], 'id="sidebar"'));
check('admin layout: only an administrator sees the choice in the profile settings', str_contains($get('/account/settings', 3)['body'], 'name="admin_layout" value="header"')
    && !str_contains($get('/account/settings', 1)['body'], 'name="admin_layout"'));
check('admin layout: a visitor and an account without access cannot set it', $post('/account/admin-layout', ['admin_layout' => 'header'], null)['status'] === 403
    && $post('/account/admin-layout', ['admin_layout' => 'header'], 1)['status'] === 403 && $pdo->query("SELECT COUNT(*) FROM account_preference WHERE name = 'admin_layout'")->fetchColumn() == 0);
$post('/account/admin-layout', ['admin_layout' => 'grid'], 3);
check('admin layout: an unknown layout is refused', str_contains($_SESSION['_flash']['error'] ?? '', 'Aufbauten') && $pdo->query("SELECT COUNT(*) FROM account_preference WHERE name = 'admin_layout'")->fetchColumn() == 0);
$post('/account/admin-layout', ['admin_layout' => 'header'], 3);
$r = $get('/admin', 3);
check('admin layout: the header bar with its mega menus replaces the sidebar', str_contains($r['body'], '<body class="layout-header">') && str_contains($r['body'], 'data-header')
    && str_contains($r['body'], 'class="mega-toggle"') && str_contains($r['body'], 'href="/admin/section/content"') && !str_contains($r['body'], 'id="sidebar"'));
check('admin layout: the header bar is the same on every administration page', str_contains($get('/admin/settings', 3)['body'], '<body class="layout-header">') && str_contains($get('/admin/roles', 3)['body'], 'data-header'));
$r = $get('/admin/section/content', 3);
check('admin layout: a section shows its entries as tiles in the content area', $r['status'] === 200 && str_contains($r['body'], 'class="tiles"')
    && str_contains($r['body'], 'class="tile" href="/admin/pages"') && str_contains($r['body'], '<h1>Inhalte</h1>'));
// Account 2 gets a limited administrator role for these two checks: the
// administration, and the pages - but no accounts, roles or settings.
$pdo->exec("INSERT INTO role_permission VALUES (1, 'core.admin.access'), (1, 'core.pages.manage')");
check('admin layout: a section without entries for the account does not exist', $get('/admin/section/people', 2)['status'] === 404 && $get('/admin/section/nope', 3)['status'] === 404);
check('admin layout: a section shows only what the account may open', substr_count($get('/admin/section/system', 2)['body'], 'class="tile" href=') === 2 && str_contains($get('/admin/section/system', 2)['body'], 'href="/admin/docs"') && str_contains($get('/admin/section/system', 2)['body'], 'href="/handbook"'));
$pdo->exec("DELETE FROM role_permission WHERE role_id = 1 AND permission IN ('core.admin.access', 'core.pages.manage')");
check('admin layout: the tiles need the administration permission', $get('/admin/section/content', 1)['status'] === 403);
$post('/account/admin-layout', ['admin_layout' => 'sidebar'], 3);
check('admin layout: back to the sidebar removes the choice', $pdo->query("SELECT COUNT(*) FROM account_preference WHERE name = 'admin_layout'")->fetchColumn() == 0
    && str_contains($get('/admin', 3)['body'], '<body class="layout-sidebar">'));

// Media library: pictures for the site, under "Content" in the administration.
check('media: the library needs its permission', $get('/admin/media', 1)['status'] === 403 && $post('/admin/media', [], 1)['status'] === 403);
check('media: the library is in the content section, and the editor role may use it', str_contains($get('/admin/section/content', 3)['body'], 'href="/admin/media"')
    && str_contains($get('/admin/roles/new?preset=editor', 3)['body'], 'value="core.media.manage" checked'));
$png = tempnam(sys_get_temp_dir(), 'media');
$picture = imagecreatetruecolor(40, 30);
imagefill($picture, 0, 0, imagecolorallocate($picture, 30, 120, 200));
imagepng($picture, $png);
$_FILES = ['file' => ['tmp_name' => $png, 'error' => UPLOAD_ERR_OK, 'size' => filesize($png), 'name' => 'x.php', 'type' => 'image/png']];
$post('/admin/media', ['title' => '  Testbild  '], 3);
$_FILES = [];
unlink($png);
$media = $pdo->query('SELECT * FROM media')->fetch();
check('media: an upload is stored under a random name, with its size and a trimmed title', $media !== false && $media['title'] === 'Testbild'
    && (int) $media['width'] === 40 && (int) $media['height'] === 30 && preg_match('/^[a-f0-9]{32}\.(webp|png)$/', $media['file']) === 1
    && is_file($config['app']['uploads'] . '/media/' . $media['file']) && str_contains($_SESSION['_flash']['success'] ?? '', 'hochgeladen'));
check('media: the picture is served to visitors under its address, other names are not', $get('/media/library/' . $media['file'], null)['status'] === 200
    && $get('/media/library/' . str_repeat('a', 32) . '.png', null)['status'] === 404 && $get('/media/library/x.php', null)['status'] === 404);
$text = tempnam(sys_get_temp_dir(), 'media');
file_put_contents($text, 'Das ist kein Bild, auch wenn es so heißt.');
$_FILES = ['file' => ['tmp_name' => $text, 'error' => UPLOAD_ERR_OK, 'size' => filesize($text), 'name' => 'bild.png', 'type' => 'image/png']];
$post('/admin/media', [], 3);
$_FILES = [];
unlink($text);
check('media: only pictures are accepted, whatever the file is called', str_contains($_SESSION['_flash']['error'] ?? '', 'JPEG') && $pdo->query('SELECT COUNT(*) FROM media')->fetchColumn() == 1);
$r = $get('/admin/media', 3);
check('media: the page lists each picture with its address and a way to remove it', $r['status'] === 200 && str_contains($r['body'], 'value="/media/library/' . $media['file'] . '"')
    && str_contains($r['body'], 'Testbild') && str_contains($r['body'], '/admin/media/' . $media['id'] . '/delete'));
$post('/admin/media/' . $media['id'] . '/delete', [], 3);
check('media: removing a picture removes its file too', $pdo->query('SELECT COUNT(*) FROM media')->fetchColumn() == 0 && !is_file($config['app']['uploads'] . '/media/' . $media['file']));

// Dashboard: the update card shows what the last check found. A check
// older than a day (or none at all) is repeated by the dashboard itself,
// silently - see UpdateChecks::isStale() below, tested without a repository
// so these display-logic checks stay free of any real network access; the
// seeded "checked_at" here is always fresh enough not to trigger that.
$updateConfig = ['update' => ['repo' => 'acme/modulento']] + $config;
$app = new Modulento\Core\App($config, $pdo);
$app->settings->set('core.update_check', json_encode(['core' => '99.0.0', 'packages' => [], 'checked_at' => gmdate('Y-m-d H:i')]));
$rr = request($pdo, $updateConfig, 'GET', '/admin', 3);
check('dashboard: an available update is named, with the installed version next to it', str_contains($rr['body'], 'Modulento 99.0.0'));
$justNow = gmdate('Y-m-d H:i');
$app->settings->set('core.update_check', json_encode(['core' => '0.0.0', 'packages' => [], 'checked_at' => $justNow]));
check('dashboard: an up-to-date check says so', str_contains(request($pdo, $updateConfig, 'GET', '/admin', 3)['body'], 'Aktuell'));
$app->settings->set('core.update_check', '');
check('dashboard: without a repository it says that updates are off', str_contains(request($pdo, $config, 'GET', '/admin', 3)['body'], 'Updates sind ausgeschaltet'));

// The dashboard's own silent repeat check: never checked, or a check older
// than a day, counts as stale; nothing here ever reaches a release server -
// that only happens through the real check (confirmed above never fired by
// a plain display-logic test) or by hand on /admin/updates.
$updateChecks = new Modulento\Core\Support\UpdateChecks($app);
$app->settings->set('core.update_check', json_encode(['core' => '0.0.0', 'packages' => [], 'checked_at' => gmdate('Y-m-d H:i', time() - 3600)]));
check('updates: a check from an hour ago is not stale yet', !$updateChecks->isStale());
$app->settings->set('core.update_check', json_encode(['core' => '0.0.0', 'packages' => [], 'checked_at' => gmdate('Y-m-d H:i', time() - 90000)]));
check('updates: a check from more than a day ago is stale', $updateChecks->isStale());
$app->settings->set('core.update_check', '');
check('updates: never checked at all counts as stale too', $updateChecks->isStale());

// Settings in tabs: one page, every tab in the same form.
$rr = $get('/account/settings', 3);
check('settings tabs: the profile is shown first, the other tabs are only hidden', preg_match('/id="tab-profile" class="account-tab"\s*>/', $rr['body']) === 1 && preg_match('/id="tab-security" class="account-tab" hidden>/', $rr['body']) === 1);
check('settings tabs: a named tab is shown, an unknown one falls back to the first', preg_match('/id="tab-security" class="account-tab"\s*>/', $get('/account/settings?tab=security', 3)['body']) === 1
    && preg_match('/id="tab-profile" class="account-tab"\s*>/', $get('/account/settings?tab=nonsense', 3)['body']) === 1);
// The tab a form returns to: the one it was sent from, else the one in the address, else the first.
$tabOf = function (array $post, array $get, array $tabs): string {
    $_POST = $post;
    $_GET = $get;
    $controller = new Modulento\Core\Controller\SettingsController(new Modulento\Core\App($GLOBALS['config'], $GLOBALS['pdo']));
    $method = new ReflectionMethod($controller, 'tab');

    return $method->invoke($controller, $tabs);
};
$tabs = ['profile', 'security', 'orders', 'data'];
check('settings tabs: the form\'s tab wins, then the address\'s, unknown names fall back to the first', $tabOf(['tab' => 'security'], ['tab' => 'data'], $tabs) === 'security'
    && $tabOf([], ['tab' => 'data'], $tabs) === 'data' && $tabOf(['tab' => 'x'], ['tab' => ['data']], $tabs) === 'profile' && $tabOf([], [], $tabs) === 'profile');
$_POST = [];
$_GET = [];
$rr = $get('/admin/settings?tab=languages', 3);
check('settings tabs: the administration settings are in tabs, all of them in one form', preg_match('/id="languages"\s*>/', $rr['body']) === 1 && preg_match('/id="general" hidden>/', $rr['body']) === 1
    && str_contains($rr['body'], 'name="site_name"') && str_contains($rr['body'], 'name="tab" value="languages"'));
$rr = $post('/admin/settings', ['site_name' => 'Testseite', 'mail_from' => 'noreply@example.test', 'registration' => 'open', 'default_locale' => 'de', 'locales' => ['de', 'en'], 'tab' => 'languages'], 3);
check('settings tabs: saving keeps the administration settings of every tab', $pdo->query("SELECT COUNT(*) FROM setting WHERE name = 'core.site_name' AND value = 'Testseite'")->fetchColumn() == 1 && $rr['status'] === 302);

$app = new Modulento\Core\App($config, $pdo);
$app->preferences->set(4, 'color_scheme', 'dark');
$app->preferences->set(4, 'color_scheme', 'sepia');
check('preferences: an unknown stored scheme counts as automatic', $app->preferences->colorScheme(4) === 'auto' && $app->preferences->colorScheme(null) === 'auto' && $app->preferences->all(4) === ['color_scheme' => 'sepia']);
$app->preferences->set(4, 'color_scheme', null);
check('preferences: before the migration ran, everything has its default', (new Modulento\Core\Account\Preferences(new PDO('sqlite::memory:')))->colorScheme(1) === 'auto'
    && (new Modulento\Core\Account\Preferences(new PDO('sqlite::memory:')))->all(1) === []);
check('preferences: the migration parses', count(Migrator::statements((string) file_get_contents($root . '/core/migrations/016_account_preference.sql'))) === 1);
foreach (['themes/default/assets/theme.css', 'themes/admin/assets/admin.css'] as $stylesheet) {
    $css = (string) file_get_contents($root . '/' . $stylesheet);
    check("colour scheme: {$stylesheet} is dark for the device unless \"light\" is chosen, and for \"dark\"", preg_match('/@media \(prefers-color-scheme: dark\) \{\s*:root:not\(\[data-theme="light"\]\) \{\s*color-scheme: dark;/', $css) === 1
        && preg_match('/\n:root\[data-theme="dark"\] \{\s*color-scheme: dark;/', $css) === 1);
    preg_match_all('/(?:not\(\[data-theme="light"\]\)|\[data-theme="dark"\]) \{([^}]*)\}/', $css, $darkBlocks);
    check("colour scheme: {$stylesheet} has the same dark values in both places", count($darkBlocks[1]) === 2 && preg_replace('/\s+/', ' ', $darkBlocks[1][0]) === preg_replace('/\s+/', ' ', $darkBlocks[1][1]));
}

$toggle = '#<script src="/assets/theme/password-toggle\.js\?v=[0-9a-f]+" defer data-show="Passwort anzeigen" data-hide="Passwort verbergen"></script>#';
$resetToken = $app->tokens->create(1, Modulento\Core\Account\Tokens::RESET_PASSWORD, 3600);
foreach (['/login' => null, '/register' => null, '/reset-password/' . $resetToken => null, '/account/settings' => 1] as $path => $as) {
    $r = $get($path, $as);
    check('show password: the script is loaded on ' . explode('/', $path)[1] . ($as ? '/settings' : ''), $r['status'] === 200 && str_contains($r['body'], 'type="password"') && preg_match($toggle, $r['body']) === 1);
}
$pdo->exec('DELETE FROM account_token');
check('show password: wording in the visitor\'s language', str_contains($get('/en/login', null)['body'], 'data-show="Show password" data-hide="Hide password"'));
$r = $get('/assets/theme/password-toggle.js', null);
check('show password: the script is served and adds a button of type "button"', $r['status'] === 200 && str_contains($r['body'], "button.type = 'button'") && str_contains($r['body'], 'aria-pressed'));
// A page with a password field that brought its own layout would miss the
// script, which every layout has to load.
$unscripted = [];
foreach ([...glob($root . '/themes/*/templates/{,*/,*/*/}*.twig', GLOB_BRACE), ...glob($root . '/extensions/*/templates/{,*/}*.twig', GLOB_BRACE)] as $file) {
    $source = (string) file_get_contents($file);
    if (str_contains($source, 'type="password"') && !str_contains($source, "{% extends 'layout/base.twig' %}") && !str_contains($source, "{% extends '@admin/layout.twig' %}")) {
        $unscripted[] = basename($file);
    }
}
check('show password: every template with a password field uses a layout that loads the script: ' . implode(', ', $unscripted), $unscripted === []
    && str_contains((string) file_get_contents($root . '/themes/admin/templates/layout_base.twig'), 'password-toggle.js')
    && file_get_contents($root . '/themes/admin/assets/password-toggle.js') === file_get_contents($root . '/themes/default/assets/password-toggle.js'));
check('show password: the setup page loads its own copy of the same script', file_get_contents($root . '/core/install/password-toggle.js') === file_get_contents($root . '/themes/default/assets/password-toggle.js')
    && str_contains((string) file_get_contents($root . '/core/install/install.twig'), '<script src="/password-toggle.js" defer data-show="{{ password_show }}" data-hide="{{ password_hide }}"></script>'));
ob_start();
(new Modulento\Core\Install\Installer($root))->handle('GET', '/password-toggle.js');
check('show password: the setup page serves the script', str_contains((string) ob_get_clean(), 'aria-pressed'));
$inline = [];
foreach ([...glob($root . '/themes/*/templates/{,*/,*/*/}*.twig', GLOB_BRACE), ...glob($root . '/core/install/*.twig')] as $file) {
    if (preg_match('/<script(?![^>]*\ssrc=)|\sstyle="|\son[a-z]+="/', (string) file_get_contents($file)) === 1) {
        $inline[] = basename($file);
    }
}
check('templates carry no inline script or style, which the content security policy would block: ' . implode(', ', $inline), $inline === []);

// --- core.home_redirect: another page can be made the start page, without the core
// knowing it or its extension at all - set by whatever offers "use this as the start page".
check('home: without a redirect set, the home page renders as usual', $get('/', null)['status'] === 200);
$app->settings->set('core.home_redirect', '/impressum');
check('home: a redirect set makes "/" forward instead of rendering the home page', $get('/', null)['status'] === 302);
$app->settings->set('core.home_redirect', 'https://evil.example');
check('home: an external address is not accepted - only an internal path redirects', $get('/', null)['status'] === 200);
$app->settings->set('core.home_redirect', 'javascript:alert(1)');
check('home: an unsafe address is not accepted either', $get('/', null)['status'] === 200);
$app->settings->set('core.home_redirect', '');

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
$needsNewer = new Packages($pdo, new Modulento\Core\Support\ReleaseClient(''), $packageRoot . '/extensions', $packageRoot . '/themes', $packageRoot . '/work', ['acme/*'], '0.12.0');
[$zip, $sha] = $makePackage(['theme.json' => '{"id":"future","name":"Future","version":"1.0.0","requires":"0.13.0"}', 'templates/home.twig' => 'x']);
check('package: one that needs a newer core is refused and says which', $packageError(fn () => $needsNewer->installArchive($zip, $sha, 'acme/modulento-theme-future', '1.0.0')) === 'core.package.error.core_version' && !is_dir($packageRoot . '/themes/future'));
[$zip, $sha] = $makePackage(['theme.json' => '{"id":"future","name":"Future","version":"1.0.0","requires":"0.12.0"}', 'templates/home.twig' => 'x']);
check('package: one whose minimum the core meets is installed', $needsNewer->installArchive($zip, $sha, 'acme/modulento-theme-future', '1.0.0')['id'] === 'future');
[$zip, $sha] = $makePackage(['theme.json' => '{"id":"later","name":"Later","version":"1.0.0","requires":"9.0.0"}', 'templates/home.twig' => 'x']);
check('package: a development checkout without a version takes everything', $packages->installArchive($zip, $sha, 'acme/modulento-theme-later', '1.0.0')['id'] === 'later');
$pdo->exec("DELETE FROM package WHERE id IN ('future', 'later')");
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
check('the old /admin/packages address redirects to the merged page', $get('/admin/packages', 3)['status'] === 302);
$r = $get('/admin/extensions', 3);
check('package administration lists packages and the allowed sources', $r['status'] === 200 && str_contains($r['body'], 'acme/modulento-shop') && str_contains($r['body'], 'acme/*, other/exact'));
check('package administration: a theme that is no package can be taken over; updates are not offered on this page', str_contains($r['body'], 'Als Paket übernehmen') && str_contains($r['body'], '/modulento-theme-sample"')
    && !str_contains($r['body'], '/admin/packages/extension/shop/update'));
$app->settings->set('core.update_check', json_encode(['core' => '0.0.0', 'packages' => ['extension:shop' => '9.0.0'], 'checked_at' => '2026-10-04 10:00']));
check('updates: a package with a newer release is offered on the one Updates page', str_contains($get('/admin/updates', 3)['body'], '/admin/packages/extension/shop/update'));
$app->settings->set('core.update_check', '');
check('package administration: extensions that moved out of the core can be taken over', str_contains($r['body'], '/modulento-ext-freelancer"') && str_contains($r['body'], '/modulento-ext-auction"') && !str_contains($r['body'], '/modulento-ext-example"'));
check('package administration: no official packages without knowing whose they are', !str_contains($r['body'], 'Offizielle Pakete'));
$r = request($pdo, ['update' => ['repo' => 'acme/modulento']] + $config, 'GET', '/admin/extensions', 3);
check('package administration offers the official packages that are not here yet', str_contains($r['body'], 'Offizielle Pakete') && str_contains($r['body'], 'name="repo" value="acme/modulento-theme-indigo"')
    && !str_contains($r['body'], 'type="hidden" name="repo" value="acme/modulento-ext-freelancer"') && !str_contains($r['body'], 'type="hidden" name="repo" value="acme/modulento-ext-auction"')
    // The blog is offered exactly where its folder is not there (it is not needed for these tests).
    && str_contains($r['body'], 'type="hidden" name="repo" value="acme/modulento-ext-blog"') === !is_dir($root . '/extensions/blog'));
$r = request($pdo, ['update' => ['repo' => 'elsewhere/modulento']] + $config, 'GET', '/admin/extensions', 3);
check('package administration: official packages only from an allowed source', $r['status'] === 200 && !str_contains($r['body'], 'Offizielle Pakete'));
$post('/admin/packages/install', ['repo' => 'evil/thing'], 3);
check('package administration refuses a source that is not allowed', str_contains($_SESSION['_flash']['error'] ?? '', 'evil/thing'));
$pdo->exec("INSERT INTO package VALUES ('theme', 'sample', 'acme/modulento-theme-sample', '1.0.0', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO setting VALUES ('core.theme', 'sample')");
$post('/admin/packages/theme/sample/remove', [], 3);
check('package administration does not remove the active theme', $pdo->query("SELECT COUNT(*) FROM package WHERE id = 'sample'")->fetchColumn() == 1 && is_dir($testThemes . '/sample'));
$pdo->exec("DELETE FROM setting WHERE name = 'core.theme'");
$r = $get('/admin/extensions', 3);
check('package administration: each package shows whether it is in use and can be switched', str_contains($r['body'], '/admin/packages/theme/sample/enable') && str_contains($r['body'], 'Inaktiv'));
$post('/admin/packages/theme/sample/enable', [], 3);
check('package administration: a theme is chosen from the list', $pdo->query("SELECT value FROM setting WHERE name = 'core.theme'")->fetchColumn() === 'sample' && str_contains($get('/admin/extensions', 3)['body'], '/admin/packages/theme/sample/disable'));
$post('/admin/packages/theme/sample/disable', [], 3);
check('package administration: switching the active theme off leads back to the default theme', $pdo->query("SELECT value FROM setting WHERE name = 'core.theme'")->fetchColumn() === 'default');
$pdo->exec("INSERT INTO package VALUES ('extension', 'example', 'acme/modulento-ext-example', '0.1.0', '2026-01-01 00:00:00')");
// Switching on runs the extension's migrations, which need MariaDB; off is plain.
$enabledBefore = (int) $pdo->query("SELECT enabled FROM extension WHERE id = 'example'")->fetchColumn();
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'example'");
check('package administration: an active extension offers to be switched off', str_contains($get('/admin/extensions', 3)['body'], '/admin/packages/extension/example/disable'));
$post('/admin/packages/extension/example/disable', [], 3);
check('package administration: an extension is switched off from the list', (int) $pdo->query("SELECT enabled FROM extension WHERE id = 'example'")->fetchColumn() === 0);
$pdo->exec("UPDATE extension SET enabled = {$enabledBefore} WHERE id = 'example'");
check('package administration: switching needs the permission', $post('/admin/packages/theme/sample/enable', [], 1)['status'] === 403);
$pdo->exec("DELETE FROM setting WHERE name = 'core.theme'");
$pdo->exec('DELETE FROM package');

// "Update all": one request for every component with a newer release. The core here is
// switched off (no repository configured in $config), so it is skipped without asking
// anything; a package whose source is not allowed fails inside Packages::install() before
// it ever reaches a release server, so this stays as free of real network access as the
// rest of this file.
$pdo->exec("INSERT INTO package VALUES ('extension', 'zzz_update_all_test', 'evil/thing-not-allowed', '1.0.0', '2026-01-01 00:00:00')");
$app->settings->set('core.update_check', json_encode(['core' => '0.0.0', 'packages' => ['extension:zzz_update_all_test' => '2.0.0'], 'checked_at' => gmdate('Y-m-d H:i')]));
check('update all: needs its permission', $post('/admin/updates/apply-all', [], 1)['status'] === 403);
$post('/admin/updates/apply-all', [], 3);
check('update all: a package whose source is not allowed fails without reaching the network, and is reported',
    str_contains($_SESSION['_flash']['error'] ?? '', 'evil/thing-not-allowed') && $pdo->query("SELECT version FROM package WHERE id = 'zzz_update_all_test'")->fetchColumn() === '1.0.0');
$app->settings->set('core.update_check', json_encode(['core' => '0.0.0', 'packages' => [], 'checked_at' => gmdate('Y-m-d H:i')]));
$post('/admin/updates/apply-all', [], 3);
check('update all: nothing to update says so', ($_SESSION['_flash']['success'] ?? '') === 'Es gibt nichts zu aktualisieren.');
$pdo->exec("DELETE FROM package WHERE id = 'zzz_update_all_test'");
$app->settings->set('core.update_check', '');

// --- Licensed packages: ReleaseSourceResolver, LicenseServerClient, LicenseKeys ---
check('package: a "license:<product>" repo is recognised as one, a plain one is not', Packages::isLicensedRepo('license:ext-exklusiv') && Packages::isLicensedRepo('license:a')
    && !Packages::isLicensedRepo('license:') && !Packages::isLicensedRepo('license:Ext') && !Packages::isLicensedRepo('acme/thing'));
check('package: a licensed repo is allowed regardless of the owner-pattern allowlist', $packages->isAllowed('license:ext-exklusiv') && !$packages->isAllowed('evil/thing'));

$licenseKeys = new Modulento\Core\License\LicenseKeys($pdo, $app->secrets);
check('license keys: nothing stored answers null, not an error', $licenseKeys->find('ext-exklusiv') === null && $licenseKeys->all() === []);
$licenseKeys->save('ext-exklusiv', 'sk_live_abcdef123456');
check('license keys: stored encrypted, read back in full, listed masked', $pdo->query("SELECT key_encrypted FROM license_key WHERE product_id = 'ext-exklusiv'")->fetchColumn() !== 'sk_live_abcdef123456'
    && $licenseKeys->find('ext-exklusiv') === 'sk_live_abcdef123456' && $licenseKeys->all() === [['product_id' => 'ext-exklusiv', 'masked' => '••••3456', 'added_at' => $licenseKeys->all()[0]['added_at']]]);
$licenseKeys->save('ext-exklusiv', 'sk_live_newkey7890');
check('license keys: saving again for the same product replaces it, not adds a second row', $licenseKeys->find('ext-exklusiv') === 'sk_live_newkey7890' && count($licenseKeys->all()) === 1);
$licenseKeys->remove('ext-exklusiv');
check('license keys: removed, nothing left for the product', $licenseKeys->find('ext-exklusiv') === null && $licenseKeys->all() === []);
$licenseKeys->save('ext-exklusiv', 'sk_live_abcdef123456');

/** A fake HttpClient answering one fixed response, recording what it was asked. */
final class FakeLicenseHttp implements Modulento\Core\Support\HttpClient
{
    public ?array $lastRequest = null;

    public function __construct(private int $status, private string $body)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->lastRequest = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return ['status' => $this->status, 'body' => $this->body];
    }
}

$licenseAssets = ['ext-exklusiv-1.3.0.zip' => 'https://license.example.test/v1/download/tok1', 'ext-exklusiv-1.3.0.zip.sha256' => 'https://license.example.test/v1/download/tok2'];
$fakeHttp = new FakeLicenseHttp(200, json_encode(['version' => '1.3.0', 'published_at' => '2026-01-01T00:00:00Z', 'changelog' => 'x', 'assets' => $licenseAssets]));
$licenseClient = new Modulento\Core\Support\LicenseServerClient($fakeHttp, 'https://license.example.test', 'kunde.de', $licenseKeys);
$latest = $licenseClient->latest('license:ext-exklusiv');
check('license server: a successful answer is read the same shape a GitHub release is', $latest === ['version' => '1.3.0', 'published_at' => '2026-01-01T00:00:00Z', 'changelog' => 'x', 'assets' => $licenseAssets]);
check('license server: the request carries the product, the stored key and this installation\'s domain, never a GitHub token', json_decode($fakeHttp->lastRequest['body'], true) === ['license_key' => 'sk_live_abcdef123456', 'product' => 'ext-exklusiv', 'domain' => 'kunde.de']
    && $fakeHttp->lastRequest['url'] === 'https://license.example.test/v1/releases/latest' && !isset($fakeHttp->lastRequest['headers']['Authorization']));

$noKeyClient = new Modulento\Core\Support\LicenseServerClient($fakeHttp, 'https://license.example.test', 'kunde.de', new Modulento\Core\License\LicenseKeys($pdo, $app->secrets));
check('license server: no key stored for the product refuses locally, no request made', $packageError(fn () => $noKeyClient->latest('license:never-bought')) === 'core.update.error.license_missing');

foreach (['expired' => 'core.update.error.license_expired', 'domain_mismatch' => 'core.update.error.license_domain', 'revoked' => 'core.update.error.license_revoked', 'not_found' => 'core.update.error.license_unknown', 'something_else' => 'core.update.error.unreachable'] as $serverError => $expectedKey) {
    $errorClient = new Modulento\Core\Support\LicenseServerClient(new FakeLicenseHttp(403, json_encode(['error' => $serverError])), 'https://license.example.test', 'kunde.de', $licenseKeys);
    check("license server: a '{$serverError}' answer becomes {$expectedKey}", $packageError(fn () => $errorClient->latest('license:ext-exklusiv')) === $expectedKey);
}
$unreachableClient = new Modulento\Core\Support\LicenseServerClient(new FakeLicenseHttp(0, ''), 'https://license.example.test', 'kunde.de', $licenseKeys);
check('license server: no answer at all is also "unreachable"', $packageError(fn () => $unreachableClient->latest('license:ext-exklusiv')) === 'core.update.error.unreachable');

$fakeSha256 = hash('sha256', 'the zip bytes');
$shaClient = new Modulento\Core\Support\LicenseServerClient(new FakeLicenseHttp(200, "{$fakeSha256}  ext-exklusiv-1.3.0.zip\n"), 'https://license.example.test', 'kunde.de', $licenseKeys);
check('license server: checksum() reads the same sha256sum format a GitHub asset uses', $shaClient->checksum('https://license.example.test/v1/download/tok2') === $fakeSha256);
$downloadTarget = $packageRoot . '/license-download-test.zip';
$downloadClient = new Modulento\Core\Support\LicenseServerClient(new FakeLicenseHttp(200, 'the zip bytes'), 'https://license.example.test', 'kunde.de', $licenseKeys);
$downloadClient->download('https://license.example.test/v1/download/tok1', $downloadTarget);
check('license server: download() writes the response body to the target path', file_get_contents($downloadTarget) === 'the zip bytes');
unlink($downloadTarget);

$resolverGithub = new Modulento\Core\Support\ReleaseClient('');
$resolverLicense = new Modulento\Core\Support\LicenseServerClient($fakeHttp, 'https://license.example.test', 'kunde.de', $licenseKeys);
$resolver = new Modulento\Core\Support\ReleaseSourceResolver($resolverGithub, $resolverLicense, 'https://license.example.test');
check('ReleaseSourceResolver: a "license:" repo goes to the license server, nothing else does', $resolver->latest('license:ext-exklusiv') === $latest);
$resolverForChecksum = new Modulento\Core\Support\ReleaseSourceResolver($resolverGithub, $shaClient, 'https://license.example.test');
check('ReleaseSourceResolver: an asset url on the license server\'s own host is checked through it too', $resolverForChecksum->checksum('https://license.example.test/v1/download/tok2') === $fakeSha256);
$resolverNoLicense = new Modulento\Core\Support\ReleaseSourceResolver($resolverGithub, null, '');
check('ReleaseSourceResolver: without a configured license server, a "license:" repo falls back to GitHub (and fails there, as it should)', $packageError(fn () => $resolverNoLicense->latest('license:ext-exklusiv')) === 'core.update.error.no_release');

$pdo->exec("DELETE FROM license_key");

// --- Admin: license key management on the packages page ---------------------
$post('/admin/licenses', ['product_id' => 'ext-exklusiv', 'license_key' => 'sk_live_test'], 3);
check('license admin: saving a key is reflected on the page, masked', str_contains($get('/admin/extensions', 3)['body'], '••••test'));
check('license admin: needs the packages permission', $post('/admin/licenses', ['product_id' => 'ext-x', 'license_key' => 'k'], 1)['status'] === 403);
$post('/admin/licenses', ['product_id' => 'Not Valid', 'license_key' => 'k'], 3);
check('license admin: an invalid product id is refused, the earlier key is untouched', str_contains($_SESSION['_flash']['error'] ?? '', 'ungültig') && $pdo->query("SELECT COUNT(*) FROM license_key")->fetchColumn() == 1);
$post('/admin/licenses/ext-exklusiv/remove', [], 3);
check('license admin: removing a key takes it off the page', !str_contains($get('/admin/extensions', 3)['body'], '••••test') && $pdo->query("SELECT COUNT(*) FROM license_key")->fetchColumn() == 0);

// --- Administration -> Shop: the public catalog of a license server --------
check('shop: needs its permission', $get('/admin/shop', 1)['status'] === 403);
$r = $get('/admin/shop', 3);
check('shop: no license server configured says so plainly', $r['status'] === 200 && str_contains($r['body'], 'Kein Lizenzserver konfiguriert'));

$shopConfig = fn (Modulento\Core\Support\HttpClient $http) => ['update' => ['license_server' => 'https://license.example.test', 'license_http' => $http]] + $config;

$emptyCatalogHttp = new FakeLicenseHttp(200, json_encode(['products' => []]));
$r = request($pdo, $shopConfig($emptyCatalogHttp), 'GET', '/admin/shop', 3);
check('shop: a configured server with nothing for sale says so, not an error', $r['status'] === 200 && str_contains($r['body'], 'Derzeit werden keine Erweiterungen angeboten'));

$catalogHttp = new FakeLicenseHttp(200, json_encode(['products' => [
    ['id' => 'ext-exklusiv', 'name' => 'Exklusiv', 'description' => 'Die exklusive Erweiterung.', 'price_cents' => 4990, 'currency' => 'EUR', 'screenshot_url' => 'https://license.example.test/screenshots/x.jpg'],
]]));
$r = request($pdo, $shopConfig($catalogHttp), 'GET', '/admin/shop', 3);
check('shop: a product for sale is shown with name, description, price, picture and a buy button', $r['status'] === 200 && str_contains($r['body'], 'Exklusiv') && str_contains($r['body'], 'Die exklusive Erweiterung.')
    && str_contains($r['body'], '49,90') && str_contains($r['body'], 'https://license.example.test/screenshots/x.jpg') && str_contains($r['body'], '/admin/shop/ext-exklusiv/buy'));
check('shop: asks the configured server\'s catalog endpoint, nothing else', $catalogHttp->lastRequest['url'] === 'https://license.example.test/v1/catalog' && $catalogHttp->lastRequest['method'] === 'GET');

$malformedHttp = new FakeLicenseHttp(200, json_encode(['products' => [
    ['id' => 'ok-one', 'name' => 'Ok', 'description' => '', 'price_cents' => 1000, 'currency' => 'EUR', 'screenshot_url' => null],
    ['id' => 'Not Valid', 'name' => 'Bad id', 'description' => '', 'price_cents' => 1000, 'currency' => 'EUR'],
    ['id' => 'no-price', 'name' => 'Missing price', 'description' => '', 'price_cents' => null, 'currency' => 'EUR'],
    'not even an array',
]]));
$r = request($pdo, $shopConfig($malformedHttp), 'GET', '/admin/shop', 3);
check('shop: a malformed entry from the server is left out, valid ones around it still show', $r['status'] === 200 && str_contains($r['body'], '>Ok<') && !str_contains($r['body'], 'Bad id') && !str_contains($r['body'], 'Missing price'));

$downHttp = new FakeLicenseHttp(500, 'internal error');
$r = request($pdo, $shopConfig($downHttp), 'GET', '/admin/shop', 3);
check('shop: an unreachable or failing server shows an empty catalog, not a broken page', $r['status'] === 200 && str_contains($r['body'], 'Derzeit werden keine Erweiterungen angeboten'));

// --- Shop: buying a product through Stripe Checkout (phase 2) --------------
$buyOkHttp = new FakeLicenseHttp(200, json_encode(['reference' => 'pur_test123', 'checkout_url' => 'https://checkout.stripe.com/fake/pur_test123']));
$r = request($pdo, $shopConfig($buyOkHttp), 'POST', '/admin/shop/ext-exklusiv/buy', 3, []);
check('shop buy: redirects (a 302 - the exact Location target cannot be read back under this CLI test harness, see the card-redirect tests\' own note above)', $r['status'] === 302);
$sentBody = json_decode($buyOkHttp->lastRequest['body'], true);
check('shop buy: sends the product, this installation\'s own domain, and a success_url carrying the {reference} placeholder for the server to fill in', $buyOkHttp->lastRequest['url'] === 'https://license.example.test/v1/purchase/start'
    && $sentBody['product'] === 'ext-exklusiv' && $sentBody['domain'] === 'https://example.test' && str_contains($sentBody['success_url'], '{reference}'));

$buyFailHttp = new FakeLicenseHttp(404, json_encode(['error' => 'not_for_sale']));
$r = request($pdo, $shopConfig($buyFailHttp), 'POST', '/admin/shop/nope/buy', 3, []);
check('shop buy: a product that cannot be bought redirects back with its own error, not a broken page', $r['status'] === 302 && str_contains($_SESSION['_flash']['error'] ?? '', 'nicht (mehr) verkauft'));

check('shop buy: needs its permission', request($pdo, $shopConfig($buyOkHttp), 'POST', '/admin/shop/ext-exklusiv/buy', 1, [])['status'] === 403);

// --- Shop: returning from Stripe, polling /v1/purchase/status --------------
$pendingHttp = new FakeLicenseHttp(200, json_encode(['status' => 'pending']));
$r = request($pdo, $shopConfig($pendingHttp), 'GET', '/admin/shop?purchase=pur_test123', 3);
check('shop return: still pending shows a "please check again" message, not an error', $r['status'] === 200 && str_contains($r['body'], 'wird noch verarbeitet'));

$pdo->exec("DELETE FROM license_key WHERE product_id = 'ext-exklusiv'");
$completedHttp = new FakeLicenseHttp(200, json_encode(['status' => 'completed', 'product' => 'ext-exklusiv', 'license_key' => 'mlk_live_fromshop']));
$r = request($pdo, $shopConfig($completedHttp), 'GET', '/admin/shop?purchase=pur_test123', 3);
check('shop return: a completed purchase with a key saves it automatically, no copy-pasting', $r['status'] === 200 && str_contains($r['body'], 'Jetzt installieren'));
check('shop return: the license key is really stored, not just reported as saved', (new Modulento\Core\License\LicenseKeys($pdo, $app->secrets))->find('ext-exklusiv') === 'mlk_live_fromshop');

$alreadySavedHttp = new FakeLicenseHttp(200, json_encode(['status' => 'completed', 'product' => 'ext-exklusiv', 'license_key' => null]));
$r = request($pdo, $shopConfig($alreadySavedHttp), 'GET', '/admin/shop?purchase=pur_test123', 3);
check('shop return: a completed purchase checked a second time (key already consumed) does not error', $r['status'] === 200 && str_contains($r['body'], 'bereits eingetragen'));

$notFoundHttp = new FakeLicenseHttp(404, json_encode(['status' => 'not_found']));
$r = request($pdo, $shopConfig($notFoundHttp), 'GET', '/admin/shop?purchase=pur_nonexistent', 3);
check('shop return: an unknown reference shows plainly that it was not found', $r['status'] === 200 && str_contains($r['body'], 'wurde nicht gefunden'));
$pdo->exec("DELETE FROM license_key WHERE product_id = 'ext-exklusiv'");

// --- Administration -> Extensions: tiles, filter, toggle without a reload ---
check('extensions page: needs its permission', $get('/admin/extensions', 1)['status'] === 403);
$r = $get('/admin/extensions', 3);
check('extensions page: a tile per discovered extension, typed for the filter buttons, description shown when the manifest names one', $r['status'] === 200
    && str_contains($r['body'], 'data-extension-id="extension:example"') && str_contains($r['body'], 'data-extension-type="addon"')
    && str_contains($r['body'], 'Reference extension: demonstrates every registration point once.')
    && str_contains($r['body'], 'data-filter="app"') && str_contains($r['body'], 'data-filter="addon"') && str_contains($r['body'], 'data-filter="theme"'));

// Only disable goes through the real ExtensionManager in this SQLite suite -
// enabling a real extension would run its (MariaDB-only) migrations; see the
// "package administration" tests above, which hit the exact same limit.
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'example'");
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$r = $post('/admin/packages/extension/example/disable', [], 3);
unset($_SERVER['HTTP_ACCEPT']);
check('extensions toggle: Accept: application/json gets JSON back instead of a redirect', $r['status'] === 200 && json_decode($r['body'], true) === ['ok' => true, 'id' => 'example', 'switched_off' => []]
    && (int) $pdo->query("SELECT enabled FROM extension WHERE id = 'example'")->fetchColumn() === 0);

// enable() itself writes with MariaDB-only "ON DUPLICATE KEY UPDATE", same
// limit the "package administration" tests above already work around - so
// this suite can only ever exercise enable()'s FAILURE path for real
// (an unknown id, below), never a real success; that half is covered by
// the "switched off" unit tests of ExtensionManager::appsToSwitchOff()
// further up, and by hand against the real MariaDB-backed dev install.
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$r = $post('/admin/packages/extension/does-not-exist/enable', [], 3);
unset($_SERVER['HTTP_ACCEPT']);
$failBody = json_decode($r['body'], true);
check('extensions toggle: a failure (unknown id) is reported as JSON too, with its own reason - not a redirect the caller cannot read', $r['status'] === 200 && $failBody['ok'] === false && is_string($failBody['error']) && $failBody['error'] !== '');

check('extensions toggle: without Accept: application/json, the old plain redirect still works (no-JS fallback)', $post('/admin/packages/extension/example/disable', [], 3)['status'] === 302);

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
foreach (['default' => ['layout/base.twig', 'home.twig', 'error.twig', 'page.twig', 'auth/login.twig', 'auth/register.twig', 'auth/forgot.twig', 'auth/reset.twig', 'account/index.twig', 'emails/verify_email.txt.twig', 'emails/reset_password.txt.twig', 'emails/already_registered.txt.twig', 'emails/change_email.txt.twig', 'emails/password_changed.txt.twig'], 'admin' => ['layout.twig', 'index.twig', 'settings.twig', 'pages.twig', 'page_edit.twig', 'orders.twig', 'order.twig', 'reviews.twig', 'offers.twig', 'offer.twig', 'categories.twig', 'category_edit.twig', 'custom_fields.twig', 'custom_field_edit.twig', 'providers.twig', 'provider.twig', 'accounts.twig', 'account.twig', 'roles.twig', 'role_edit.twig', 'extensions.twig', 'themes.twig', 'tasks.twig', 'updates.twig']] as $theme => $templates) {
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

// --- Shop: a single-provider shop with variants, a cart spanning several products, one checkout ---
// A mock "shop" extension/package row was created above (package installer
// tests) in the same shared tables this uses for real - clear it first.
$pdo->exec("DELETE FROM extension WHERE id = 'shop'");
$pdo->exec("DELETE FROM package WHERE kind = 'extension' AND id = 'shop'");
$pdo->exec("CREATE TABLE x_shop_variant (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, position INTEGER, sku TEXT UNIQUE, price INTEGER, stock INTEGER,
    is_digital INTEGER NOT NULL DEFAULT 0, file_name TEXT, file_original_name TEXT, file_extension TEXT, file_bytes INTEGER)");
$pdo->exec("CREATE TABLE x_shop_variant_translation (variant_id INTEGER REFERENCES x_shop_variant (id) ON DELETE CASCADE, locale TEXT, label TEXT, PRIMARY KEY (variant_id, locale))");
$pdo->exec("CREATE TABLE x_shop_cart_item (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, variant_id INTEGER REFERENCES x_shop_variant (id) ON DELETE CASCADE, quantity INTEGER, added_at TEXT, UNIQUE (account_id, variant_id))");
$pdo->exec("CREATE TABLE x_shop_discount (id INTEGER PRIMARY KEY, code TEXT UNIQUE, type TEXT, value INTEGER, active INTEGER DEFAULT 1, expires_at TEXT, max_uses INTEGER, used_count INTEGER DEFAULT 0, min_subtotal INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE x_shop_cart_discount (account_id INTEGER PRIMARY KEY REFERENCES account (id) ON DELETE CASCADE, discount_id INTEGER REFERENCES x_shop_discount (id) ON DELETE CASCADE, applied_at TEXT)");
$pdo->exec("INSERT INTO extension VALUES ('shop', '0.1.0', 1)");

$post('/admin/categories/new', ['text' => ['de' => ['name' => 'Kleidung', 'slug' => ''], 'en' => ['name' => '', 'slug' => '']]], 3);
$shopCatId = (int) $pdo->query("SELECT category_id FROM category_translation WHERE slug = 'kleidung'")->fetchColumn();
$insertAccount->execute([10, 'shopowner@example.test', $testHash, 'active']);
$insertAccount->execute([11, 'shopbuyer@example.test', $testHash, 'active']);
$post('/account/provider', ['type' => 'private', 'name' => 'Shop-Betreiber', 'street' => 'Ladenweg 1', 'postal_code' => '20095', 'city' => 'Hamburg', 'country' => 'DE'], 10);
$post('/admin/providers/' . $provider(10)['id'] . '/decide', ['decision' => 'approve'], 3);
$shopProviderId = (int) $provider(10)['id'];
$post('/admin/shop/settings', ['shipping_flat' => '4,90'], 3);
check('shop: the shipping flat rate is stored in minor units', $pdo->query("SELECT value FROM setting WHERE name = 'shop.shipping_flat'")->fetchColumn() === '490');

$variantRow = fn (string $sku) => $pdo->query("SELECT * FROM x_shop_variant WHERE sku = '{$sku}'")->fetch();
$productForm = ['type' => 'shop.product', 'category_id' => (string) $shopCatId,
    'text' => ['de' => ['title' => 'T-Shirt Basic', 'summary' => 'Baumwolle, zwei Größen', 'description' => "100% Baumwolle.\nMaschinenwäsche bis 30°C."], 'en' => ['title' => '', 'summary' => '', 'description' => '']],
    'variant' => [
        0 => ['sku' => 'TSHIRT-M', 'price' => '19,99', 'stock' => '5', 'text' => ['de' => ['label' => 'Größe M']]],
        1 => ['sku' => 'TSHIRT-L', 'price' => '24,99', 'stock' => '0', 'text' => ['de' => ['label' => 'Größe L']]],
    ]];
$post('/account/offers/new', $productForm, 10);
$product = $pdo->query("SELECT * FROM offer WHERE type = 'shop.product' ORDER BY id DESC")->fetch();
check('shop: a product is saved with the lowest variant price as price_from, two variants stored with their own sku/price/stock',
    $product !== false && $product['status'] === 'draft' && (int) $product['price_from'] === 1999
    && $variantRow('TSHIRT-M') !== false && (int) $variantRow('TSHIRT-M')['stock'] === 5 && (int) $variantRow('TSHIRT-L')['price'] === 2499 && (int) $variantRow('TSHIRT-L')['stock'] === 0);
$productId = (int) $product['id'];
$variantM = (int) $variantRow('TSHIRT-M')['id'];
$variantL = (int) $variantRow('TSHIRT-L')['id'];

check('shop: a second variant with a sku already in use is refused', (function () use ($post, $productForm, $productId, $pdo) {
    $form = $productForm;
    $form['variant'][1]['sku'] = 'TSHIRT-M';
    $post('/account/offers/' . $productId, $form, 10);
    return $pdo->query("SELECT COUNT(*) FROM x_shop_variant WHERE offer_id = {$productId}")->fetchColumn() == 2;
})());

$post('/admin/offers/' . $productId . '/decide', ['decision' => 'approve'], 3);
$productSlug = $product['slug'] ?? $pdo->query("SELECT slug FROM offer_translation WHERE offer_id = {$productId} AND locale = 'de'")->fetchColumn();
$r = $get('/offers/' . $productSlug, null);
check('shop: a visitor who is not logged in is sent to log in, sees no variant picker at all',
    str_contains($r['body'], 'Melde dich an') && !str_contains($r['body'], 'name="variant_id"'));
$r = $get('/offers/' . $productSlug, 11);
check('shop: logged in, both variants are offered, the out-of-stock one disabled',
    str_contains($r['body'], 'Größe M') && str_contains($r['body'], 'Größe L')
    && preg_match('/value="' . $variantL . '"\s+disabled/', $r['body']) === 1
    && preg_match('/value="' . $variantM . '"\s+disabled/', $r['body']) === 0);
check('shop: adding more than is in stock is refused, nothing is added', $post('/cart/add', ['variant_id' => (string) $variantL, 'quantity' => '1'], 11)['status'] === 302
    && $pdo->query('SELECT COUNT(*) FROM x_shop_cart_item')->fetchColumn() == 0);
$post('/cart/add', ['variant_id' => (string) $variantM, 'quantity' => '2'], 11);
check('shop: a valid quantity is added to the cart, tied to the account', $pdo->query("SELECT quantity FROM x_shop_cart_item WHERE account_id = 11 AND variant_id = {$variantM}")->fetchColumn() == 2);
$post('/cart/add', ['variant_id' => (string) $variantM, 'quantity' => '1'], false);
check('shop: adding the same variant again increases the quantity instead of a second row', $pdo->query('SELECT COUNT(*) FROM x_shop_cart_item')->fetchColumn() == 1
    && $pdo->query("SELECT quantity FROM x_shop_cart_item WHERE account_id = 11 AND variant_id = {$variantM}")->fetchColumn() == 3);
$r = $get('/cart', false);
check('shop: the cart shows the line and its total', str_contains($r['body'], 'T-Shirt Basic') && str_contains($r['body'], '59,97'));

$r = $get('/checkout', false);
check('shop: checkout shows subtotal, the flat shipping rate and the grand total', str_contains($r['body'], '59,97') && str_contains($r['body'], '4,90') && str_contains($r['body'], '64,87'));
$ordersBefore = $lastOrder();
$post('/checkout', ['payment_method' => 'core.offline', 'accept_terms' => '1'], false);
$orderId = $lastOrder();
$order = $orderRow($orderId);
check('shop: checkout creates one order for the single provider, total is cart plus shipping, the cart is emptied',
    $orderId > $ordersBefore && (int) $order['buyer_id'] === 11 && (int) $order['provider_id'] === $shopProviderId && $order['offer_id'] === null
    && $order['flow'] === 'shop.product' && $order['state'] === 'placed' && (int) $order['total'] === 6487 && $order['currency'] === 'EUR'
    && $pdo->query('SELECT COUNT(*) FROM x_shop_cart_item')->fetchColumn() == 0);
check('shop: the order has one line per cart item plus a shipping line', $pdo->query("SELECT label || ':' || quantity || ':' || unit_price FROM order_item WHERE order_id = {$orderId} ORDER BY position")->fetchAll(PDO::FETCH_COLUMN)
    == ['T-Shirt Basic — Größe M:3:1999', 'Versand:1:490']);
check('shop: stock was taken for what was ordered, atomically - not just recorded after the fact', (int) $variantRow('TSHIRT-M')['stock'] === 2);

check('shop: the order page shows the shipping line and carries the generic order flow onward', str_contains($get('/orders/' . $orderId, 11)['body'], 'Versand: 4,90'));
check('shop: the provider marks it shipped, the buyer confirms receipt - no shop-specific route needed for this', $act($orderId, 'ship', 10)['status'] === 302 && $orderRow($orderId)['state'] === 'shipped');
$act($orderId, 'accept_delivery', 11);
check('shop: completed, and open for a review', $orderRow($orderId)['state'] === 'completed' && str_contains($get('/orders/' . $orderId, 11)['body'], 'name="rating"'));
$post('/orders/' . $orderId . '/review', ['rating' => '5', 'body' => 'Passt gut, schnell geliefert.'], 11);
check('shop: a review is stored against the provider', (int) $pdo->query("SELECT COUNT(*) FROM review WHERE order_id = {$orderId}")->fetchColumn() === 1);

// A second order, cancelled before shipping: stock has to come back.
$post('/cart/add', ['variant_id' => (string) $variantM, 'quantity' => '1'], 11);
$post('/checkout', ['payment_method' => 'core.offline', 'accept_terms' => '1'], false);
$secondOrderId = $lastOrder();
check('shop: stock taken for the second order too', (int) $variantRow('TSHIRT-M')['stock'] === 1);
$post('/orders/' . $secondOrderId . '/transition', ['transition' => 'request_cancel', 'note' => 'Nicht mehr lieferbar'], 10);
check('shop: a cancellation can be requested by the provider', $orderRow($secondOrderId)['state'] === 'cancel_requested');
$post('/orders/' . $secondOrderId . '/transition', ['transition' => 'agree_cancel'], 11);
check('shop: once the buyer agrees, the order is cancelled and the variant\'s stock is given back', $orderRow($secondOrderId)['state'] === 'cancelled' && (int) $variantRow('TSHIRT-M')['stock'] === 2);

// Discount codes: a pure check of the proration math (no HTTP needed - it is
// a pure function), then a real code through a real checkout.
$discountsService = new Modulento\Shop\Discounts($pdo);
$prorated = $discountsService->apply([['label' => 'X', 'quantity' => 2, 'unit_price' => 1999]], 101);
check('shop: a flat discount that does not divide evenly by the quantity splits the line instead of rounding',
    $prorated === [['label' => 'X', 'quantity' => 1, 'unit_price' => 1999], ['label' => 'X', 'quantity' => 1, 'unit_price' => 1898]]
    && array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $prorated)) === 2 * 1999 - 101);
$prorated2 = $discountsService->apply([['label' => 'A', 'quantity' => 1, 'unit_price' => 1000], ['label' => 'B', 'quantity' => 1, 'unit_price' => 2000]], 300);
check('shop: a discount is prorated across several lines by their share of the subtotal',
    $prorated2 === [['label' => 'A', 'quantity' => 1, 'unit_price' => 900], ['label' => 'B', 'quantity' => 1, 'unit_price' => 1800]]);

$post('/admin/shop/discounts', ['code' => 'invalid code', 'type' => 'percent', 'value' => '10', 'active' => '1'], 3);
check('shop: a discount code with a space is refused', $discountsService->findByCode('invalid code') === null);
$post('/admin/shop/discounts', ['code' => 'test10', 'type' => 'percent', 'value' => '10', 'active' => '1', 'max_uses' => '5'], 3);
$percentDiscount = $discountsService->findByCode('TEST10');
check('shop: a percent discount code is created, stored uppercase, with its usage limit', $percentDiscount !== null && $percentDiscount['type'] === 'percent' && $percentDiscount['value'] === 10 && $percentDiscount['max_uses'] === 5);
$r = $get('/admin/shop/discounts', 3);
check('shop: the admin list shows the new code with its use count', $r['status'] === 200 && str_contains($r['body'], 'TEST10') && str_contains($r['body'], '10 %') && str_contains($r['body'], '0 / 5'));

check('shop: applying a code that does not exist is refused', $post('/cart/discount', ['code' => 'NOPE'], 11)['status'] === 302
    && $pdo->query('SELECT COUNT(*) FROM x_shop_cart_discount')->fetchColumn() == 0);
$post('/cart/add', ['variant_id' => (string) $variantM, 'quantity' => '2'], 11);
$post('/cart/discount', ['code' => 'test10'], false);
check('shop: a valid code is applied to the account\'s cart, matched case-insensitively', $pdo->query('SELECT discount_id FROM x_shop_cart_discount WHERE account_id = 11')->fetchColumn() == $percentDiscount['id']);
$r = $get('/cart', false);
check('shop: the cart shows the discount and the reduced total (3998 - 10% = 3598)', str_contains($r['body'], 'TEST10') && str_contains($r['body'], '35,98'));

$ordersBeforeDiscount = $lastOrder();
$post('/checkout', ['payment_method' => 'core.offline', 'accept_terms' => '1'], false);
$discountOrderId = $lastOrder();
$discountOrder = $orderRow($discountOrderId);
check('shop: checkout with a discount applies it to the order total (3598 + 4,90 shipping = 4088)',
    $discountOrderId > $ordersBeforeDiscount && (int) $discountOrder['total'] === 4088);
check('shop: the discount is prorated into the product line, never a negative price', $pdo->query("SELECT label || ':' || quantity || ':' || unit_price FROM order_item WHERE order_id = {$discountOrderId} ORDER BY position")->fetchAll(PDO::FETCH_COLUMN)
    == ['T-Shirt Basic — Größe M:2:1799', 'Versand:1:490']);
check('shop: one use was taken, atomically, and the cart\'s applied code was cleared', $discountsService->find($percentDiscount['id'])['used_count'] === 1
    && $pdo->query('SELECT COUNT(*) FROM x_shop_cart_discount')->fetchColumn() == 0);
check('shop: the order page shows which code was used and how much it took off', str_contains($get('/orders/' . $discountOrderId, 11)['body'], 'TEST10'));

$post('/admin/shop/discounts/' . $percentDiscount['id'] . '/toggle', [], 3);
check('shop: the admin toggle deactivates a code, shown as inactive in the list', !$discountsService->find($percentDiscount['id'])['active']
    && str_contains($get('/admin/shop/discounts', 3)['body'], 'inaktiv'));
$post('/cart/discount', ['code' => 'TEST10'], 11);
check('shop: an inactive code cannot be applied', $pdo->query('SELECT COUNT(*) FROM x_shop_cart_discount')->fetchColumn() == 0);

$post('/orders/' . $discountOrderId . '/transition', ['transition' => 'request_cancel', 'note' => 'Testweise'], 10);
$post('/orders/' . $discountOrderId . '/transition', ['transition' => 'agree_cancel'], 11);
check('shop: cancelling the order gives the discount\'s use back too', $discountsService->find($percentDiscount['id'])['used_count'] === 0);
$post('/admin/shop/discounts/' . $percentDiscount['id'] . '/delete', [], 3);
check('shop: the admin delete removes the code for good', $discountsService->find($percentDiscount['id']) === null);

// Discount codes as their own module (Administration → Modules), added
// only because the shop extension is active - switching it off hides the
// cart's apply-code form and removes the admin discounts routes, without
// touching core modules (subscriptions stays off, set at the top of this
// section) or any code still in x_shop_discount.
check('shop: the discounts module is listed in Administration → Modules, on by default',
    str_contains($get('/admin/modules', 3)['body'], 'Rabattcodes')
    && str_contains($get('/admin/modules', 3)['body'], 'value="shop.discounts" checked'));

$coreModulesWithoutSubscriptions = array_values(array_diff(array_keys(Modulento\Core\Support\Modules::ALL), ['subscriptions']));
$post('/admin/modules', ['modules' => $coreModulesWithoutSubscriptions], 3);
check('shop: switched off, its admin page and the cart route are both gone',
    $get('/admin/shop/discounts', 3)['status'] === 404 && $post('/cart/discount', ['code' => 'ANY'], 11)['status'] === 404);

$pdo->exec("INSERT INTO x_shop_cart_item (account_id, variant_id, quantity, added_at) VALUES (11, {$variantM}, 1, '2026-01-01 00:00:00')");
check('shop: switched off, the cart no longer shows the discount code field', !str_contains($get('/cart', 11)['body'], 'Rabattcode'));
$pdo->exec('DELETE FROM x_shop_cart_item WHERE account_id = 11');

$post('/admin/modules', ['modules' => array_merge($coreModulesWithoutSubscriptions, ['shop.discounts'])], 3);
check('shop: switched back on, its admin page and cart route work again',
    $get('/admin/shop/discounts', 3)['status'] === 200
    && str_contains($get('/admin/modules', 3)['body'], 'value="shop.discounts" checked'));

// Digital products: no shipping, no stock to run out of, a download once paid.
$digitalForm = ['type' => 'shop.product', 'category_id' => (string) $shopCatId,
    'text' => ['de' => ['title' => 'E-Book Testwissen', 'summary' => 'Ein PDF zum Lernen', 'description' => 'Praktisches Wissen.'], 'en' => ['title' => '', 'summary' => '', 'description' => '']],
    'variant' => [0 => ['text' => ['de' => ['label' => 'Standard']], 'price' => '9,99', 'stock' => '0', 'digital' => '1']]];
$post('/account/offers/new', $digitalForm, 10);
$digitalOfferId = (int) $pdo->query("SELECT id FROM offer WHERE type = 'shop.product' AND id <> {$productId} ORDER BY id DESC")->fetchColumn();
$digitalVariantId = (int) $pdo->query("SELECT id FROM x_shop_variant WHERE offer_id = {$digitalOfferId}")->fetchColumn();
$digitalVariantRow = fn () => $pdo->query("SELECT * FROM x_shop_variant WHERE id = {$digitalVariantId}")->fetch();
check('shop: a digital variant is saved with is_digital set, stock stays 0 and irrelevant', (bool) $digitalVariantRow()['is_digital'] === true && (int) $digitalVariantRow()['stock'] === 0);
$post('/admin/offers/' . $digitalOfferId . '/decide', ['decision' => 'approve'], 3);

$tmpFile = tempnam(sys_get_temp_dir(), 'shop_test');
file_put_contents($tmpFile, 'PDF-ish test content');
$_FILES = ['file' => ['tmp_name' => $tmpFile, 'error' => UPLOAD_ERR_OK, 'name' => 'Lehrbuch.pdf', 'size' => filesize($tmpFile)]];
$post('/account/shop/variants/' . $digitalVariantId . '/file', [], 10);
$_FILES = [];
check('shop: the file is stored, the original name kept for the download', $digitalVariantRow()['file_name'] !== null && $digitalVariantRow()['file_original_name'] === 'Lehrbuch.pdf');
// rename()'s fallback (no real HTTP upload in this suite) moves the file
// rather than copying it, so the second attempt needs one of its own.
$tmpFile2 = tempnam(sys_get_temp_dir(), 'shop_test');
file_put_contents($tmpFile2, 'Different content');
$_FILES = ['file' => ['tmp_name' => $tmpFile2, 'error' => UPLOAD_ERR_OK, 'name' => 'x.pdf', 'size' => filesize($tmpFile2)]];
check('shop: the file cannot be replaced by another provider', $post('/account/shop/variants/' . $digitalVariantId . '/file', [], 1)['status'] === 404
    && $digitalVariantRow()['file_original_name'] === 'Lehrbuch.pdf');
$_FILES = [];
@unlink($tmpFile2);

$post('/cart/add', ['variant_id' => (string) $digitalVariantId, 'quantity' => '1'], 11);
check('shop: a digital variant can be added to the cart regardless of its stock', $pdo->query("SELECT quantity FROM x_shop_cart_item WHERE account_id = 11 AND variant_id = {$digitalVariantId}")->fetchColumn() == 1);
check('shop: an all-digital cart is not charged shipping', str_contains($get('/checkout', false)['body'], 'Versand: 0,00'));
$digitalOrdersBefore = $lastOrder();
$post('/checkout', ['payment_method' => 'core.offline', 'accept_terms' => '1'], false);
$digitalOrderId = $lastOrder();
check('shop: checkout of an all-digital cart creates an order with one line, no shipping line',
    $digitalOrderId > $digitalOrdersBefore && $pdo->query("SELECT COUNT(*) FROM order_item WHERE order_id = {$digitalOrderId}")->fetchColumn() == 1);
check('shop: the download is refused before the order is paid', $get('/orders/' . $digitalOrderId . '/download/' . $digitalVariantId, 11)['status'] === 404);

$post('/orders/' . $digitalOrderId . '/paid', [], 10);
check('shop: the provider confirming payment marks the order paid', $orderRow($digitalOrderId)['payment_state'] === 'paid');
check('shop: the order page now offers the download', str_contains($get('/orders/' . $digitalOrderId, 11)['body'], 'Standard herunterladen'));
$download = $get('/orders/' . $digitalOrderId . '/download/' . $digitalVariantId, 11);
check('shop: the buyer downloads the exact file that was uploaded', $download['status'] === 200 && $download['body'] === 'PDF-ish test content');
check('shop: nobody else can download it', $get('/orders/' . $digitalOrderId . '/download/' . $digitalVariantId, 1)['status'] === 404);

// The scheduled task: Scheduler::runDue() itself uses MySQL's GET_LOCK(),
// which this SQLite suite cannot run, so DigitalDeliveries is called
// directly - the same reason Auctions::closeDue() is tested this way.
$shopApp = new Modulento\Core\App($config, $pdo);
$shopApp->translator->load($config['app']['root'] . '/core/lang', 'core');
Modulento\Core\Kernel::registerCore($shopApp);
$shopApp->extensions->loadEnabled($shopApp);
Modulento\Core\Kernel::registerLast($shopApp);
$mailedCount = (new Modulento\Shop\DigitalDeliveries($pdo))->mailDue($shopApp);
check('shop: the scheduled task mails the download link once, and not a second time',
    $mailedCount === 1 && (new Modulento\Shop\DigitalDeliveries($pdo))->mailDue($shopApp) === 0);
$mail = lastMail($mailLog, 'shopbuyer@example.test');
check('shop: the mail names the order in its subject, with the order link',
    $mail !== null && str_contains($mail['subject'], sprintf('%06d', $digitalOrderId)) && str_contains($mail['link'], '/orders/' . $digitalOrderId));
$mailBlocks = array_filter(explode("\n--\n", (string) file_get_contents($mailLog)));
$ourMail = array_values(array_filter($mailBlocks, fn (string $m) => str_starts_with(ltrim($m), 'To: shopbuyer@example.test')));
check('shop: the mail body lists the downloadable variant by name', $ourMail !== [] && str_contains(end($ourMail), 'Standard'));

// A cart that somehow spans two providers is refused, not guessed at or split.
$insertAccount->execute([12, 'secondshopowner@example.test', $testHash, 'active']);
$post('/account/provider', ['type' => 'private', 'name' => 'Zweiter Shop-Betreiber', 'street' => 'Weg 2', 'postal_code' => '20099', 'city' => 'Hamburg', 'country' => 'DE'], 12);
$post('/admin/providers/' . $provider(12)['id'] . '/decide', ['decision' => 'approve'], 3);
$secondShopForm = ['type' => 'shop.product', 'category_id' => (string) $shopCatId,
    'text' => ['de' => ['title' => 'Zweites Produkt', 'summary' => '', 'description' => 'x'], 'en' => ['title' => '', 'summary' => '', 'description' => '']],
    'variant' => [0 => ['text' => ['de' => ['label' => 'Standard']], 'price' => '5', 'stock' => '5']]];
$post('/account/offers/new', $secondShopForm, 12);
$secondOfferId = (int) $pdo->query("SELECT id FROM offer WHERE type = 'shop.product' AND id NOT IN ({$productId}, {$digitalOfferId}) ORDER BY id DESC")->fetchColumn();
$secondVariantId = (int) $pdo->query("SELECT id FROM x_shop_variant WHERE offer_id = {$secondOfferId}")->fetchColumn();
$post('/admin/offers/' . $secondOfferId . '/decide', ['decision' => 'approve'], 3);

$post('/cart/add', ['variant_id' => (string) $digitalVariantId, 'quantity' => '1'], 11);
$post('/cart/add', ['variant_id' => (string) $secondVariantId, 'quantity' => '1'], false);
check('shop: a cart spanning two providers is sent back from checkout, not charged or split',
    $get('/checkout', false)['status'] === 302 && $post('/checkout', ['payment_method' => 'core.offline'], false)['status'] === 302
    && $pdo->query('SELECT COUNT(*) FROM orders WHERE id > ' . $digitalOrderId)->fetchColumn() == 0);
check('shop: the cart page itself still works with mixed providers in it', str_contains($get('/cart', false)['body'], 'Zweites Produkt'));

// CSV import: create-only, grouped by the "product" column, one row per variant.
check('shop: a buyer without a provider profile is sent to create one, not shown the import form', $get('/account/shop/import', 11)['status'] === 302);
$r = $get('/account/shop/import/template', 10);
check('shop: the template download starts with the header row', $r['status'] === 200 && str_starts_with($r['body'], "product,category,summary,description,variant_label,sku,price,stock,digital\n"));

$importRows = [
    ['Import-Hose', 'Kleidung', 'Bequeme Hose', 'Robuster Stoff.', 'Größe M', 'HOSE-M', '29.99', '8', ''],
    ['Import-Hose', 'Kleidung', 'Bequeme Hose', 'Robuster Stoff.', 'Größe L', 'HOSE-L', '32.50', '4', ''],
    ['T-Shirt Basic', 'Kleidung', '', '', 'Standard', 'TSHIRT-DUP', '9.99', '3', ''],
    ['Import-Buch', 'Kleidung', 'Ein E-Book', '', 'Standard', 'TSHIRT-M', '4.99', '0', '1'],
    ['Import-Mixed', 'kleidung', '', '', 'Gut', '', '15.00', '2', ''],
    ['Import-Mixed', 'Kleidung', '', '', 'Kaputt', '', '', '2', ''],
];
$csvPath = tempnam(sys_get_temp_dir(), 'shop_import');
$csvHandle = fopen($csvPath, 'w');
fputcsv($csvHandle, Modulento\Shop\CsvImport::TEMPLATE_HEADER);
foreach ($importRows as $row) {
    fputcsv($csvHandle, $row);
}
fclose($csvHandle);

$_FILES = ['file' => ['tmp_name' => $csvPath, 'error' => UPLOAD_ERR_OK, 'name' => 'products.csv', 'size' => filesize($csvPath)]];
$r = $post('/account/shop/import', [], 10);
$_FILES = [];
@unlink($csvPath);

check('shop: the import creates one product per distinct name, with as many variants as valid rows',
    $r['status'] === 200 && str_contains($r['body'], '2 Produkt(e) mit insgesamt 3 Variante(n) angelegt.'));
check('shop: a product name that already exists is skipped, not merged or duplicated',
    str_contains($r['body'], 'T-Shirt Basic') && $pdo->query("SELECT COUNT(*) FROM offer_translation WHERE title = 'T-Shirt Basic'")->fetchColumn() == 1);
check('shop: a row whose sku is already taken is rejected, and a product left with no valid variant is not created at all',
    str_contains($r['body'], 'Import-Buch') && $pdo->query("SELECT COUNT(*) FROM offer_translation WHERE title = 'Import-Buch'")->fetchColumn() == 0);
check('shop: a row with no price is rejected but its sibling variant still gets its product created',
    str_contains($r['body'], 'Import-Mixed') && $pdo->query("SELECT COUNT(*) FROM x_shop_variant v JOIN offer_translation t ON t.offer_id = v.offer_id WHERE t.title = 'Import-Mixed'")->fetchColumn() == 1);

$hoseId = (int) $pdo->query("SELECT offer_id FROM offer_translation WHERE title = 'Import-Hose'")->fetchColumn();
$hoseM = $pdo->query("SELECT * FROM x_shop_variant WHERE sku = 'HOSE-M'")->fetch();
$hoseL = $pdo->query("SELECT * FROM x_shop_variant WHERE sku = 'HOSE-L'")->fetch();
check('shop: an imported product is categorised, priced and stocked from the csv, with price_from the lowest variant',
    (int) $hoseM['offer_id'] === $hoseId && (int) $hoseM['price'] === 2999 && (int) $hoseM['stock'] === 8
    && (int) $hoseL['price'] === 3250 && (int) $hoseL['stock'] === 4
    && (int) $pdo->query("SELECT category_id FROM offer WHERE id = {$hoseId}")->fetchColumn() === $shopCatId
    && (int) $pdo->query("SELECT price_from FROM offer WHERE id = {$hoseId}")->fetchColumn() === 2999);
check('shop: an imported product needs the same approval as one created by hand', $pdo->query("SELECT status FROM offer WHERE id = {$hoseId}")->fetchColumn() === 'pending');

$mixedId = (int) $pdo->query("SELECT offer_id FROM offer_translation WHERE title = 'Import-Mixed'")->fetchColumn();
check('shop: the category match is case-insensitive', (int) $pdo->query("SELECT category_id FROM offer WHERE id = {$mixedId}")->fetchColumn() === $shopCatId);

$pdo->exec("DELETE FROM x_shop_variant WHERE offer_id IN ({$hoseId}, {$mixedId})");
$pdo->exec("DELETE FROM offer WHERE id IN ({$hoseId}, {$mixedId})");

$pdo->exec("DELETE FROM x_shop_cart_item WHERE account_id = 11");
$pdo->exec("DELETE FROM x_shop_variant WHERE offer_id = {$secondOfferId}");
$pdo->exec("DELETE FROM offer WHERE id = {$secondOfferId}");
$pdo->exec('DELETE FROM account WHERE id = 12');

// $tmpFile was moved, not copied, into storage by the successful upload
// above (rename()'s fallback) - gone already, nothing to clean up here.
$downloadsDir = $config['app']['uploads'] . '/shop-downloads/' . $digitalOfferId;
array_map('unlink', glob($downloadsDir . '/*') ?: []);
is_dir($downloadsDir) && rmdir($downloadsDir);

$pdo->exec("DELETE FROM orders WHERE id IN ({$discountOrderId}, {$digitalOrderId})");
$pdo->exec('DELETE FROM x_shop_cart_item');
$pdo->exec('DELETE FROM x_shop_cart_discount');
$pdo->exec('DELETE FROM x_shop_discount');
$pdo->exec("DELETE FROM review WHERE order_id IN ({$orderId}, {$secondOrderId})");
$pdo->exec("DELETE FROM orders WHERE id IN ({$orderId}, {$secondOrderId})");
$pdo->exec("DELETE FROM x_shop_variant WHERE offer_id IN ({$productId}, {$digitalOfferId})");
$pdo->exec("DELETE FROM offer WHERE id IN ({$productId}, {$digitalOfferId})");
$pdo->exec("DELETE FROM category WHERE id = {$shopCatId}");
$pdo->exec('DELETE FROM account WHERE id IN (10, 11)');
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'shop'");

// --- Card: turns an approved provider's profile into a shareable digital business card ---
// This section is precisely about Pro-gating, so the example extension's
// test provider (see the Subscriptions section far above) is wired in
// again - nothing runs after this section, so there is nothing to put
// back afterward.
Modulento\Example\Extension::$subscriptionProvider = new TestSubscriptionProvider($pdo);
$pdo->exec("UPDATE extension SET enabled = 1 WHERE id = 'example'");
$pdo->exec("DELETE FROM extension WHERE id = 'card'");
$pdo->exec("CREATE TABLE x_card_profile (id INTEGER PRIMARY KEY, provider_id INTEGER UNIQUE REFERENCES provider (id) ON DELETE CASCADE,
    company TEXT, category_id INTEGER REFERENCES category (id) ON DELETE SET NULL, logo_name TEXT, logo_extension TEXT,
    whatsapp TEXT, website_url TEXT, linkedin_url TEXT, instagram_url TEXT, facebook_url TEXT, youtube_url TEXT, snapchat_url TEXT,
    booking_url TEXT, booking_mode TEXT, opening_hours TEXT, design TEXT NOT NULL DEFAULT 'classic', use_custom_colors INTEGER NOT NULL DEFAULT 0,
    color_background TEXT, color_header TEXT, color_content TEXT, color_footer TEXT,
    view_count INTEGER NOT NULL DEFAULT 0, clicks_phone INTEGER NOT NULL DEFAULT 0, clicks_email INTEGER NOT NULL DEFAULT 0,
    clicks_website INTEGER NOT NULL DEFAULT 0, clicks_address INTEGER NOT NULL DEFAULT 0, clicks_linkedin INTEGER NOT NULL DEFAULT 0,
    clicks_instagram INTEGER NOT NULL DEFAULT 0, clicks_facebook INTEGER NOT NULL DEFAULT 0, clicks_youtube INTEGER NOT NULL DEFAULT 0,
    clicks_booking INTEGER NOT NULL DEFAULT 0, clicks_whatsapp INTEGER NOT NULL DEFAULT 0, clicks_snapchat INTEGER NOT NULL DEFAULT 0,
    created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE x_card_gallery (id INTEGER PRIMARY KEY, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, name TEXT, extension TEXT, width INTEGER, height INTEGER, position INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE x_card_offering (id INTEGER PRIMARY KEY, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, title TEXT, description TEXT, price TEXT, position INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE x_card_event (id INTEGER PRIMARY KEY, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, type TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE x_card_organization (id INTEGER PRIMARY KEY, owner_provider_id INTEGER UNIQUE REFERENCES provider (id) ON DELETE CASCADE,
    company TEXT, logo_name TEXT, logo_extension TEXT, design TEXT NOT NULL DEFAULT 'classic', color_preset TEXT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE x_card_organization_member (id INTEGER PRIMARY KEY, organization_id INTEGER REFERENCES x_card_organization (id) ON DELETE CASCADE,
    provider_id INTEGER UNIQUE REFERENCES provider (id) ON DELETE CASCADE, role TEXT, workplace TEXT, joined_at TEXT)");
$pdo->exec("INSERT INTO extension VALUES ('card', '0.2.0', 1)");

$post('/admin/categories/new', ['text' => ['de' => ['name' => 'Handwerk', 'slug' => ''], 'en' => ['name' => '', 'slug' => '']]], 3);
$cardCatId = (int) $pdo->query("SELECT category_id FROM category_translation WHERE slug = 'handwerk'")->fetchColumn();

$insertAccount->execute([30, 'cardowner@example.test', $testHash, 'active']);
$insertAccount->execute([31, 'cardplain@example.test', $testHash, 'active']);
check('card: without a provider profile, the editor sends you to create one', $get('/account/card', 31)['status'] === 302);

$post('/account/provider', ['type' => 'private', 'name' => 'Rosa Fischer', 'street' => 'Werkstattweg 3', 'postal_code' => '50667', 'city' => 'Köln', 'country' => 'DE',
    'phone' => '+49 221 1234567', 'contact_email' => 'rosa@example.test',
    'text' => ['de' => ['headline' => 'Tischlerin', 'description' => 'Handgefertigte Möbel aus Köln.'], 'en' => ['headline' => '', 'description' => '']]], 30);
$post('/admin/providers/' . $provider(30)['id'] . '/decide', ['decision' => 'approve'], 3);
$cardProviderId = (int) $provider(30)['id'];
$cardSlug = (string) $pdo->query("SELECT slug FROM provider WHERE id = {$cardProviderId}")->fetchColumn();

check('card: the account nav offers a link once a provider exists', str_contains($get('/account/provider', 30)['body'], 'href="/account/card"'));

$r = $get('/account/card', 30);
check('card: the editor shows the provider\'s own name, and both Pro sections are locked without a plan', str_contains($r['body'], 'Rosa Fischer')
    && str_contains($r['body'], 'card-box') === false && substr_count($r['body'], 'Nur mit Pro') >= 2);

$post('/account/card', [
    'company' => 'Fischer Tischlerei', 'category_id' => (string) $cardCatId, 'whatsapp' => '+49 171 9876543',
    'website_url' => 'fischer-tischlerei.de', 'linkedin_url' => 'https://linkedin.com/in/rosa-fischer',
    'opening_hours' => "Mo-Fr 8-17 Uhr\nSa geschlossen", 'design' => 'modern', 'use_custom_colors' => '1',
    'color_background' => '#ffffff', 'color_header' => '#112233', 'color_content' => '#112233', 'color_footer' => '#112233',
    'booking_mode' => 'link', 'booking_url' => 'https://calendly.com/rosa',
], 30);
$cardRow = fn () => $pdo->query("SELECT * FROM x_card_profile WHERE provider_id = {$cardProviderId}")->fetch();
check('card: saved fields stick, but every Pro-only field silently falls back without a plan', $cardRow()['company'] === 'Fischer Tischlerei'
    && $cardRow()['category_id'] == $cardCatId && $cardRow()['whatsapp'] === '+49 171 9876543'
    && $cardRow()['website_url'] === 'https://fischer-tischlerei.de'
    && $cardRow()['design'] === 'classic' && (int) $cardRow()['use_custom_colors'] === 0
    && $cardRow()['booking_mode'] === null && $cardRow()['booking_url'] === null);
check('card: a bare domain gets "https://" added automatically', $cardRow()['website_url'] === 'https://fischer-tischlerei.de');

$r = $get('/card/' . $cardSlug, null);
check('card: the public page is the classic design (Free fallback), shows the provider\'s own headline/description', str_contains($r['body'], 'data-design="classic"')
    && str_contains($r['body'], 'Rosa Fischer') && str_contains($r['body'], 'Tischlerin') && str_contains($r['body'], 'Handgefertigte Möbel aus Köln')
    && str_contains($r['body'], 'Fischer Tischlerei') && str_contains($r['body'], 'Handwerk'));
check('card: a view is counted on every public page load', (int) $cardRow()['view_count'] === 1);
$get('/card/' . $cardSlug, null);
check('card: and again on the next one', (int) $cardRow()['view_count'] === 2);

check('card: Free still gets its contact links, click-tracked through /go/', str_contains($r['body'], '/go/' . $cardSlug . '/phone')
    && str_contains($r['body'], '/go/' . $cardSlug . '/email') && str_contains($r['body'], '/go/' . $cardSlug . '/whatsapp')
    && str_contains($r['body'], '/go/' . $cardSlug . '/website') && str_contains($r['body'], '/go/' . $cardSlug . '/linkedin'));
check('card: Free gets no gallery/offerings/vCard/booking button at all', !str_contains($r['body'], 'vcard.vcf') && !str_contains($r['body'], '/termin')
    && !str_contains($r['body'], 'card-gallery') && !str_contains($r['body'], 'card-offerings'));

check('card: a phone click redirects and is counted', $get('/go/' . $cardSlug . '/phone', null)['status'] === 302 && (int) $cardRow()['clicks_phone'] === 1);
check('card: an address click redirects and is counted', $get('/go/' . $cardSlug . '/address', null)['status'] === 302 && (int) $cardRow()['clicks_address'] === 1);
check('card: an unknown link type 404s, nothing counted', $get('/go/' . $cardSlug . '/carrier-pigeon', null)['status'] === 404);
check('card: booking is not reachable at all without the feature and a mode', $get('/go/' . $cardSlug . '/booking', null)['status'] === 404);

// formatAddress() itself, the part no HTTP status code can show: the exact
// join order and the Maps query it feeds (headers_list() is empty under
// the CLI SAPI this suite runs under, so a redirect's Location can only be
// checked this way - as a pure function - not read back off the response).
$cardAddressProvider = ['street' => 'Werkstattweg 3', 'postal_code' => '50667', 'city' => 'Köln', 'country' => 'DE'];
check('card: formatAddress() joins street, postal code + city, and the country name', Modulento\Card\Cards::formatAddress($cardAddressProvider, fn ($c) => $c === 'DE' ? 'Deutschland' : $c)
    === 'Werkstattweg 3, 50667 Köln, Deutschland');
check('card: formatAddress() is null with nothing to show', Modulento\Card\Cards::formatAddress(['street' => '', 'postal_code' => '', 'city' => '', 'country' => ''], fn ($c) => $c) === null);
check('card: vCard is refused without the Pro feature', $get('/card/' . $cardSlug . '/vcard.vcf', null)['status'] === 404);

// --- Firma/team landing page (/firma): public, live preview of whichever plan(s) grant the feature ---
check('card: the landing page is linked from the public navigation of any theme', str_contains($get('/', null)['body'], 'href="/firma"'));
check('card: with no plan granting the team feature yet, the landing page says so plainly', str_contains($get('/firma', null)['body'], 'kein Plan eingerichtet'));

// Grant every card feature through a real plan, exactly the way an operator would.
$cardPlan = (function () use ($pdo): int {
    $pdo->prepare("INSERT INTO subscription_plan (slug, name, price_cents, currency, period_months, features, offer_limits, max_images_per_offer, per_seat, min_quantity, active)
        VALUES ('card-pro', 'Karte Pro', 900, 'EUR', 1, 'card.feature.design,card.feature.colors,card.feature.gallery,card.feature.offerings,card.feature.booking,card.feature.vcard,card.feature.stats,card.feature.team', '{}', NULL, 0, 1, 1)")->execute();

    return (int) $pdo->lastInsertId();
})();
$assignPlan(30, $cardPlan);

$r = $get('/account/card', 30);
check('card: with a plan, the Pro sections are no longer locked', !str_contains($r['body'], 'Nur mit Pro'));

// --- Dashboard panel (/account): the card's own status/quick-actions section, registered through the new dashboardSection() hook ---
$r = $get('/account', 30);
check('card: the dashboard panel shows the Pro badge, status and quick actions', str_contains($r['body'], 'Pro') && str_contains($r['body'], 'Karte bearbeiten') && str_contains($r['body'], 'QR-Code'));
$cardNoProviderApp = new Modulento\Core\App($config, $pdo);
check('card: the dashboard panel closure shows nothing for an account with no provider at all', Modulento\Card\DashboardPanel::data(['id' => 999999], $cardNoProviderApp) === []);

$cardViewsBeforePreview = (int) $cardRow()['view_count'];
$r = $get('/account/card/preview', 30);
check('card: the owner\'s own live preview renders the real design, without counting as a view', $r['status'] === 200 && str_contains($r['body'], 'data-design=') && (int) $cardRow()['view_count'] === $cardViewsBeforePreview);
check('card: a visitor cannot open someone else\'s preview - it always needs a login of its own', $get('/account/card/preview', null)['status'] !== 200);
check('card: the dashboard panel embeds the live preview in an iframe', str_contains($get('/account', 30)['body'], 'src="/account/card/preview"'));

$post('/account/card', [
    'company' => 'Fischer Tischlerei', 'category_id' => (string) $cardCatId, 'whatsapp' => '+49 171 9876543',
    'website_url' => 'fischer-tischlerei.de', 'linkedin_url' => 'https://linkedin.com/in/rosa-fischer',
    'opening_hours' => "Mo-Fr 8-17 Uhr\nSa geschlossen", 'design' => 'modern', 'use_custom_colors' => '1',
    'color_background' => '#ffffff', 'color_header' => '#112233', 'color_content' => '#112233', 'color_footer' => '#112233',
    'booking_mode' => 'link', 'booking_url' => 'https://calendly.com/rosa',
], 30);
check('card: with the plan, Pro fields are actually saved this time', $cardRow()['design'] === 'modern' && (int) $cardRow()['use_custom_colors'] === 1
    && $cardRow()['booking_mode'] === 'link' && $cardRow()['booking_url'] === 'https://calendly.com/rosa');

$r = $get('/card/' . $cardSlug, null);
check('card: the public page now renders the modern design and offers vCard + booking', str_contains($r['body'], 'data-design="modern"')
    && str_contains($r['body'], 'vcard.vcf') && str_contains($r['body'], '/go/' . $cardSlug . '/booking'));

check('card: now the booking link redirects and is counted', $get('/go/' . $cardSlug . '/booking', null)['status'] === 302 && (int) $cardRow()['clicks_booking'] === 1);

$vcard = $get('/card/' . $cardSlug . '/vcard.vcf', null);
check('card: the vCard is a well-formed vCard 3.0 with the provider\'s own fields', $vcard['status'] === 200
    && str_contains($vcard['body'], "BEGIN:VCARD\r\n") && str_contains($vcard['body'], 'FN:Rosa Fischer')
    && str_contains($vcard['body'], 'TITLE:Tischlerin') && str_contains($vcard['body'], 'ORG:Fischer Tischlerei')
    && str_contains($vcard['body'], 'TEL;TYPE=WORK,VOICE:+49 221 1234567') && str_contains($vcard['body'], "END:VCARD\r\n"));

// Gallery: a real upload through the real route, the limit enforced, ownership checked on delete.
$cardImage = tempnam(sys_get_temp_dir(), 'cardimg');
imagepng(imagecreatetruecolor(300, 200), $cardImage);
$_FILES = ['image' => ['tmp_name' => $cardImage, 'error' => UPLOAD_ERR_OK, 'size' => filesize($cardImage), 'name' => 'werkstatt.png', 'type' => 'image/png']];
$post('/account/card/gallery/add', [], 30);
$_FILES = [];
check('card: a gallery picture is stored, resized, two sizes written', $pdo->query("SELECT COUNT(*) FROM x_card_gallery WHERE provider_id = {$cardProviderId}")->fetchColumn() == 1);
$cardGalleryDir = $config['app']['uploads'] . '/card-gallery/' . $cardProviderId;
$cardGalleryRow = $pdo->query("SELECT * FROM x_card_gallery WHERE provider_id = {$cardProviderId}")->fetch();
check('card: both sizes exist on disk', is_file($cardGalleryDir . '/' . $cardGalleryRow['name'] . '.' . $cardGalleryRow['extension'])
    && is_file($cardGalleryDir . '/' . $cardGalleryRow['name'] . '_thumb.' . $cardGalleryRow['extension']));
check('card: the picture shows on the public page once the gallery feature is granted', str_contains($get('/card/' . $cardSlug, null)['body'], 'card-gallery'));

for ($i = 0; $i < Modulento\Card\Gallery::MAX_IMAGES - 1; $i++) {
    $img = tempnam(sys_get_temp_dir(), 'cardimg');
    imagepng(imagecreatetruecolor(50, 50), $img);
    $_FILES = ['image' => ['tmp_name' => $img, 'error' => UPLOAD_ERR_OK, 'size' => filesize($img), 'name' => "g{$i}.png", 'type' => 'image/png']];
    $post('/account/card/gallery/add', [], 30);
    $_FILES = [];
}
check('card: the gallery stops at its maximum', $pdo->query("SELECT COUNT(*) FROM x_card_gallery WHERE provider_id = {$cardProviderId}")->fetchColumn() == Modulento\Card\Gallery::MAX_IMAGES);
$img = tempnam(sys_get_temp_dir(), 'cardimg');
imagepng(imagecreatetruecolor(50, 50), $img);
$_FILES = ['image' => ['tmp_name' => $img, 'error' => UPLOAD_ERR_OK, 'size' => filesize($img), 'name' => 'over.png', 'type' => 'image/png']];
$post('/account/card/gallery/add', [], 30);
$_FILES = [];
check('card: one more than the maximum is refused', $pdo->query("SELECT COUNT(*) FROM x_card_gallery WHERE provider_id = {$cardProviderId}")->fetchColumn() == Modulento\Card\Gallery::MAX_IMAGES);
$post('/account/card/gallery/' . $cardGalleryRow['id'] . '/delete', [], 30);
check('card: a deleted picture is gone from the row and from disk', $pdo->query("SELECT COUNT(*) FROM x_card_gallery WHERE provider_id = {$cardProviderId}")->fetchColumn() == Modulento\Card\Gallery::MAX_IMAGES - 1
    && !is_file($cardGalleryDir . '/' . $cardGalleryRow['name'] . '.' . $cardGalleryRow['extension']));

// Offerings: a plain display list, no checkout anywhere near it.
$post('/account/card/offerings/add', ['title' => 'Maßanfertigung', 'price' => 'ab 450 €', 'description' => 'Individuelle Möbel nach Maß.'], 30);
$cardOffering = $pdo->query("SELECT * FROM x_card_offering WHERE provider_id = {$cardProviderId}")->fetch();
check('card: an offering is stored with its free-text price', $cardOffering['title'] === 'Maßanfertigung' && $cardOffering['price'] === 'ab 450 €');
check('card: the offering shows on the public page', str_contains($get('/card/' . $cardSlug, null)['body'], 'Maßanfertigung'));
$post('/account/card/offerings/add', ['title' => ''], 30);
check('card: a title is required', $pdo->query("SELECT COUNT(*) FROM x_card_offering WHERE provider_id = {$cardProviderId}")->fetchColumn() == 1);
for ($i = 0; $i < Modulento\Card\Offerings::MAX_OFFERINGS - 1; $i++) {
    $post('/account/card/offerings/add', ['title' => "Angebot {$i}"], 30);
}
check('card: the offerings list stops at its maximum', $pdo->query("SELECT COUNT(*) FROM x_card_offering WHERE provider_id = {$cardProviderId}")->fetchColumn() == Modulento\Card\Offerings::MAX_OFFERINGS);
$post('/account/card/offerings/add', ['title' => 'Zu viel'], 30);
check('card: one more than the maximum is refused', $pdo->query("SELECT COUNT(*) FROM x_card_offering WHERE provider_id = {$cardProviderId}")->fetchColumn() == Modulento\Card\Offerings::MAX_OFFERINGS);
$post('/account/card/offerings/' . $cardOffering['id'] . '/delete', [], 30);
check('card: an offering can be removed again', $pdo->query("SELECT COUNT(*) FROM x_card_offering WHERE provider_id = {$cardProviderId}")->fetchColumn() == Modulento\Card\Offerings::MAX_OFFERINGS - 1);

// Booking request form (mode "request"): a plain e-mail, no scheduling of its own.
$post('/account/card', [
    'company' => 'Fischer Tischlerei', 'category_id' => (string) $cardCatId, 'opening_hours' => '', 'design' => 'modern',
    'booking_mode' => 'request', 'booking_url' => '',
], 30);
check('card: switching to the request form clears the external URL', $cardRow()['booking_mode'] === 'request' && $cardRow()['booking_url'] === null);
check('card: the booking button now links to the request form, not /go/', str_contains($get('/card/' . $cardSlug, null)['body'], '/card/' . $cardSlug . '/termin'));

// The timing trap compares time() at render vs. at submit, in the same
// session - "false" on the posts below reuses the session the preceding
// get() just rendered the form into, exactly as a real browser would.
$r = $get('/card/' . $cardSlug . '/termin', null);
check('card: the request form page loads', $r['status'] === 200 && str_contains($r['body'], 'name="preferred_time"'));
$post('/card/' . $cardSlug . '/termin', ['name' => 'Jonas Beck', 'email' => 'jonas@example.test', 'preferred_time' => 'Dienstagnachmittag', 'message' => 'Brauche ein neues Regal.', 'website' => '', 'js_ok' => '1'], false);
check('card: a submission inside the timing trap (no wait) is silently swallowed - no mail sent', lastMail($mailLog, 'rosa@example.test') === null);

$get('/card/' . $cardSlug . '/termin', false);
sleep(4);
$post('/card/' . $cardSlug . '/termin', ['name' => 'Jonas Beck', 'email' => 'jonas@example.test', 'preferred_time' => 'Dienstagnachmittag', 'message' => 'Brauche ein neues Regal.', 'website' => '', 'js_ok' => '1'], false);
$mail = lastMail($mailLog, 'rosa@example.test');
check('card: a real submission, past the timing trap, mails the card owner', $mail !== null && str_contains($mail['subject'], 'Jonas Beck'));

$post('/card/' . $cardSlug . '/termin', ['name' => 'Bot', 'email' => 'bot@example.test', 'preferred_time' => 'x', 'website' => 'http://spam.test', 'js_ok' => '1'], false);
check('card: a filled honeypot is silently caught - same flash, no mail', lastMail($mailLog, 'rosa@example.test')['subject'] === $mail['subject']);

// Statistics: locked without the feature (already granted here, so check the gate itself with a second, plain account).
$insertAccount->execute([32, 'cardplain2@example.test', $testHash, 'active']);
$post('/account/provider', ['type' => 'private', 'name' => 'Plain Card', 'street' => 'Weg 9', 'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'DE'], 32);
$post('/admin/providers/' . $provider(32)['id'] . '/decide', ['decision' => 'approve'], 3);
check('card: the statistics page is locked without the feature', str_contains($get('/account/card/stats', 32)['body'], 'Das ist in deinem aktuellen Plan nicht enthalten'));
check('card: the dashboard panel shows the Free badge and locked stats for a plain account', str_contains($get('/account', 32)['body'], 'Kostenlos') && str_contains($get('/account', 32)['body'], 'Das ist in deinem aktuellen Plan nicht enthalten'));

$r = $get('/account/card/stats', 30);
check('card: the statistics page shows the real view count once granted', str_contains($r['body'], '<strong>' . $cardRow()['view_count'] . '</strong>'));

$r = $get('/providers/' . $cardSlug, null);
check('card: the standard marketplace profile page still works, with a teaser linking to the full card', $r['status'] === 200
    && str_contains($r['body'], 'href="/card/' . $cardSlug . '"'));

// Custom colours actually render now (a real Phase-1 gap found and fixed
// while building Firma/team branding, which depends on this working at
// all - see card.css's comment and _meta.twig's inline <style> block).
$post('/account/card', [
    'company' => 'Fischer Tischlerei', 'category_id' => (string) $cardCatId, 'design' => 'classic', 'use_custom_colors' => '1',
    'color_background' => '#fdf2e9', 'color_header' => '#b45309', 'color_content' => '#1f2430', 'color_footer' => '#b45309',
    'booking_mode' => 'request', 'booking_url' => '',
], 30);
$coloredCard = $get('/card/' . $cardSlug, null);
check('card: custom colours actually render as an inline override, scoped to beat the design\'s own default', str_contains($coloredCard['body'], 'body[data-design]')
    && str_contains($coloredCard['body'], '--card-accent: #b45309;') && str_contains($coloredCard['body'], '--card-bg: #fdf2e9;'));
check('card: without "use custom colours", no override style is rendered at all', (function () use ($post, $get, $cardCatId, $cardSlug) {
    // The checkbox is omitted entirely, exactly as an unchecked HTML checkbox
    // never appears in the POST body at all - Cards::validate() reads it
    // with isset(), not by its value.
    $post('/account/card', ['company' => 'Fischer Tischlerei', 'category_id' => (string) $cardCatId, 'design' => 'classic'], 30);
    return !str_contains($get('/card/' . $cardSlug, null)['body'], 'body[data-design]');
})());

// --- Firma/team: a Pro owner founds an organisation, invites a member, branding resolves live ---
check('card: the team page is locked for an account without the feature', str_contains($get('/account/team', 32)['body'], 'Das ist in deinem aktuellen Plan nicht enthalten'));

$teamForm = $get('/account/team', 30);
check('card: a Pro account without an organisation sees the founding form', $teamForm['status'] === 200 && str_contains($teamForm['body'], 'name="company"'));

$post('/account/team', ['company' => ''], 30);
check('card: founding with an empty company name is refused', $pdo->query("SELECT COUNT(*) FROM x_card_organization WHERE owner_provider_id = {$cardProviderId}")->fetchColumn() == 0);
$post('/account/team', ['company' => 'Fischer Gruppe'], 30);
$cardOrgId = (int) $pdo->query("SELECT id FROM x_card_organization WHERE owner_provider_id = {$cardProviderId}")->fetchColumn();
check('card: founding creates the organisation with the owner as its first member', $cardOrgId > 0
    && $pdo->query("SELECT role FROM x_card_organization_member WHERE organization_id = {$cardOrgId} AND provider_id = {$cardProviderId}")->fetchColumn() === 'owner');
check('card: the dashboard panel now shows the Firma-owner badge instead of Pro', str_contains($get('/account', 30)['body'], 'Firma (Inhaber:in)'));

$post('/account/team', ['company' => 'Zweite Firma'], 30);
check('card: an account already in an organisation cannot found a second one', $pdo->query('SELECT COUNT(*) FROM x_card_organization')->fetchColumn() == 1);

$ownerDashboard = $get('/account/team', 30);
check('card: the owner now sees the management dashboard, not the founding form', str_contains($ownerDashboard['body'], 'Fischer Gruppe') && !str_contains($ownerDashboard['body'], 'card.team.found_submit'));

$post('/account/team/branding', ['company' => 'Fischer Gruppe', 'design' => 'professional', 'color_preset' => 'graphite'], 30);
$cardOrgRow = $pdo->query("SELECT design, color_preset FROM x_card_organization WHERE id = {$cardOrgId}")->fetch();
check('card: the owner\'s branding form saves the design and the curated colour preset', $cardOrgRow['design'] === 'professional' && $cardOrgRow['color_preset'] === 'graphite');

$ownerPublicCard = $get('/card/' . $cardSlug, null);
check('card: the owner\'s own public card reflects the organisation\'s branding - company, design and preset colours', str_contains($ownerPublicCard['body'], 'data-design="professional"')
    && str_contains($ownerPublicCard['body'], 'Fischer Gruppe') && str_contains($ownerPublicCard['body'], '--card-accent: #334155;'));

$http->reset();
$post('/account/team/invite', ['email' => 'not-an-email'], 30);
check('card: inviting an invalid e-mail address is refused, nothing created', $pdo->query("SELECT COUNT(*) FROM x_card_organization_member WHERE organization_id = {$cardOrgId}")->fetchColumn() == 1);
$post('/account/team/invite', ['email' => 'kollege@example.test'], 30);
$memberAccountId = (int) $pdo->query("SELECT id FROM account WHERE email = 'kollege@example.test'")->fetchColumn();
$memberProviderId = (int) $pdo->query("SELECT id FROM provider WHERE account_id = {$memberAccountId}")->fetchColumn();
check('card: inviting creates an approved provider and an employee membership, named from the e-mail', $memberAccountId > 0 && $memberProviderId > 0
    && $pdo->query("SELECT status FROM provider WHERE id = {$memberProviderId}")->fetchColumn() === 'approved'
    && $pdo->query("SELECT name FROM provider WHERE id = {$memberProviderId}")->fetchColumn() === 'Kollege'
    && $pdo->query("SELECT role FROM x_card_organization_member WHERE provider_id = {$memberProviderId}")->fetchColumn() === 'employee');
check('card: the seat count synced to the subscription (even a flat plan tracks it; only a per-seat plan bills by it)', (int) $pdo->query("SELECT quantity FROM subscription WHERE account_id = 30 AND status = 'active'")->fetchColumn() === 2);
check('card: the member\'s own dashboard panel shows the team badge with the company name', str_contains($get('/account', $memberAccountId)['body'], 'Team: Fischer Gruppe'));

$inviteMail = lastMail($mailLog, 'kollege@example.test');
check('card: the invited member gets a set-password mail naming the company', $inviteMail !== null && str_contains($inviteMail['subject'], 'Fischer Gruppe') && str_starts_with($inviteMail['link'], '/reset-password/'));
$post('/account/team/invite', ['email' => 'kollege@example.test'], 30);
check('card: inviting the same e-mail twice is refused (the account already exists)', $pdo->query("SELECT COUNT(*) FROM account WHERE email = 'kollege@example.test'")->fetchColumn() == 1);

$post($inviteMail['link'], ['password' => $pw, 'password_repeat' => $pw], null);
$post('/login', ['email' => 'kollege@example.test', 'password' => $pw], false);

$memberEditor = $get('/account/card', $memberAccountId);
check('card: the new member\'s own editor shows it is team-managed and hides logo/design/colour sections', str_contains($memberEditor['body'], 'Fischer Gruppe')
    && !str_contains($memberEditor['body'], 'name="logo"') && str_contains($memberEditor['body'], 'card.edit.team_managed') === false && str_contains($memberEditor['body'], 'Vom Team verwaltet'));
$post('/account/card', ['category_id' => '', 'company' => 'Versuchte Eigene Firma', 'design' => 'playful', 'use_custom_colors' => '1', 'workplace' => 'Niederlassung München'], $memberAccountId);
$memberCardRow = $pdo->query("SELECT company, design, use_custom_colors FROM x_card_profile WHERE provider_id = {$memberProviderId}")->fetch();
check('card: a member cannot set their own company or design - silently ignored, exactly like the Free/Pro gate', $memberCardRow['company'] === null && $memberCardRow['design'] === 'classic' && (int) $memberCardRow['use_custom_colors'] === 0);

$post('/account/card/logo', [], $memberAccountId);
check('card: a member\'s own logo-upload attempt is refused, not silently accepted', $pdo->query("SELECT logo_name FROM x_card_profile WHERE provider_id = {$memberProviderId}")->fetchColumn() === null);

$memberSlug = $pdo->query("SELECT slug FROM provider WHERE id = {$memberProviderId}")->fetchColumn();
$memberPublicCard = $get('/card/' . $memberSlug, null);
check('card: the member\'s own public card shows the organisation\'s branding, not a blank one', str_contains($memberPublicCard['body'], 'data-design="professional"')
    && str_contains($memberPublicCard['body'], 'Fischer Gruppe') && str_contains($memberPublicCard['body'], '--card-accent: #334155;'));
check('card: without an address of its own, the member\'s card falls back to the owner\'s address, prefixed by the member\'s own workplace', str_contains($memberPublicCard['body'], 'Niederlassung München')
    && str_contains($memberPublicCard['body'], 'Werkstattweg 3'));

// A Firma seat carries full Pro access, not just branding - the member has
// no subscription of their own at all, it is entirely inherited from the
// organisation owner's plan (Organizations::effectiveAllows()).
check('card: a member with no subscription of their own can still download a vCard - inherited from the owner\'s plan', $get('/card/' . $memberSlug . '/vcard.vcf', null)['status'] === 200);
check('card: a member\'s own statistics page is unlocked too, same inheritance', !str_contains($get('/account/card/stats', $memberAccountId)['body'], 'Das ist in deinem aktuellen Plan nicht enthalten'));

$post('/account/team/branding', ['company' => 'Sollte nicht klappen', 'design' => 'classic', 'color_preset' => ''], $memberAccountId);
check('card: a member cannot change the organisation\'s branding at all - still professional/graphite', $pdo->query("SELECT design FROM x_card_organization WHERE id = {$cardOrgId}")->fetchColumn() === 'professional');

$post('/account/team/' . $cardProviderId . '/remove', [], 30);
check('card: the owner cannot be removed from their own organisation', $pdo->query("SELECT COUNT(*) FROM x_card_organization_member WHERE organization_id = {$cardOrgId}")->fetchColumn() == 2);
$post('/account/team/' . $memberProviderId . '/remove', [], 30);
check('card: removing a member deletes the membership and syncs the seat count back down', $pdo->query("SELECT COUNT(*) FROM x_card_organization_member WHERE provider_id = {$memberProviderId}")->fetchColumn() == 0
    && (int) $pdo->query("SELECT quantity FROM subscription WHERE account_id = 30 AND status = 'active'")->fetchColumn() === 1);
$removedMemberCard = $get('/card/' . $memberSlug, null);
check('card: a removed member\'s card survives, just without the organisation\'s branding any more', $removedMemberCard['status'] === 200 && !str_contains($removedMemberCard['body'], 'Fischer Gruppe'));

// --- CSV import: several invitations at once, through the exact same invite() the single form uses ---
check('card: the import pages require being the team owner, not just a logged-in account', $get('/account/team/import', 32)['status'] === 302);

$cardCsv = tempnam(sys_get_temp_dir(), 'teamcsv');
file_put_contents($cardCsv, "email,workplace\nkollege1@example.test,Niederlassung Hamburg\nkollege1@example.test,Zweite Zeile\nkollege2@example.test,\n");
$_FILES = ['file' => ['tmp_name' => $cardCsv, 'error' => UPLOAD_ERR_OK, 'size' => filesize($cardCsv), 'name' => 'team.csv', 'type' => 'text/csv']];
$r = $post('/account/team/import', [], 30);
$_FILES = [];
check('card: a CSV import invites each new row and skips an in-file duplicate address', $r['status'] === 200
    && str_contains($r['body'], '2 Einladung(en) verschickt') && str_contains($r['body'], 'Diese Adresse steht schon in einer früheren Zeile'));
check('card: both imported rows are real members, the workplace column was carried over', $pdo->query("SELECT COUNT(*) FROM x_card_organization_member WHERE organization_id = {$cardOrgId} AND role = 'employee'")->fetchColumn() == 2
    && $pdo->query("SELECT om.workplace FROM x_card_organization_member om JOIN provider p ON p.id = om.provider_id JOIN account a ON a.id = p.account_id WHERE a.email = 'kollege1@example.test'")->fetchColumn() === 'Niederlassung Hamburg');
check('card: the seat count synced up to match the new total', (int) $pdo->query("SELECT quantity FROM subscription WHERE account_id = 30 AND status = 'active'")->fetchColumn() === 3);

$_FILES = ['file' => ['tmp_name' => $cardCsv, 'error' => UPLOAD_ERR_OK, 'size' => filesize($cardCsv), 'name' => 'team.csv', 'type' => 'text/csv']];
$r = $post('/account/team/import', [], 30);
$_FILES = [];
check('card: re-importing the exact same file invites nobody - every address is already taken', str_contains($r['body'], '0 Einladung(en) verschickt')
    && $pdo->query("SELECT COUNT(*) FROM x_card_organization_member WHERE organization_id = {$cardOrgId} AND role = 'employee'")->fetchColumn() == 2);
unlink($cardCsv);
$cardImportedIds = $pdo->query("SELECT provider_id FROM x_card_organization_member WHERE organization_id = {$cardOrgId} AND role = 'employee'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($cardImportedIds as $importedProviderId) {
    $post('/account/team/' . $importedProviderId . '/remove', [], 30);
}
check('card: both imported members can be removed again, seat count syncs back down to the owner alone', $pdo->query("SELECT COUNT(*) FROM x_card_organization_member WHERE organization_id = {$cardOrgId}")->fetchColumn() == 1
    && (int) $pdo->query("SELECT quantity FROM subscription WHERE account_id = 30 AND status = 'active'")->fetchColumn() === 1);
$pdo->exec("DELETE FROM provider WHERE account_id IN (SELECT id FROM account WHERE email IN ('kollege1@example.test', 'kollege2@example.test'))");
$pdo->exec("DELETE FROM account WHERE email IN ('kollege1@example.test', 'kollege2@example.test')");

// The landing page, now that a real per-seat plan grants the feature (safe to flip here: every
// team test above that cares about the seat count/min_quantity clamp has already run).
$pdo->prepare('UPDATE subscription_plan SET per_seat = 1, min_quantity = 5 WHERE id = :id')->execute(['id' => $cardPlan]);

$r = $get('/firma', null);
check('card: the landing page shows the real plan with its price per seat and the minimum total', $r['status'] === 200
    && str_contains($r['body'], 'Karte Pro') && str_contains($r['body'], '9,00 €') && str_contains($r['body'], '45,00 €'));
check('card: as a guest, the call to action leads to registration, with a login link next to it', str_contains($r['body'], 'href="/register"') && str_contains($r['body'], 'href="/login"'));

check('card: signed in, the call to action leads to the team area instead', str_contains($get('/firma', 30)['body'], 'href="/account/team"'));

// Cleanup (team).
$pdo->exec("DELETE FROM x_card_profile WHERE provider_id = {$memberProviderId}");
$pdo->exec("DELETE FROM x_card_organization_member WHERE organization_id = {$cardOrgId}");
$pdo->exec("DELETE FROM x_card_organization WHERE id = {$cardOrgId}");
$pdo->exec("DELETE FROM provider WHERE id = {$memberProviderId}");
$pdo->exec("DELETE FROM account WHERE id = {$memberAccountId}");
$pdo->exec('UPDATE subscription SET quantity = 1 WHERE account_id = 30');

// Cleanup.
$pdo->exec("DELETE FROM x_card_event WHERE provider_id = {$cardProviderId}");
$pdo->exec("DELETE FROM x_card_offering WHERE provider_id = {$cardProviderId}");
$pdo->exec("DELETE FROM x_card_gallery WHERE provider_id = {$cardProviderId}");
array_map('unlink', glob($cardGalleryDir . '/*') ?: []);
is_dir($cardGalleryDir) && rmdir($cardGalleryDir);
$cardLogoDir = $config['app']['uploads'] . '/card-logo';
array_map('unlink', glob($cardLogoDir . '/*') ?: []);
is_dir($cardLogoDir) && rmdir($cardLogoDir);
$pdo->exec("DELETE FROM x_card_profile WHERE provider_id IN ({$cardProviderId}, (SELECT id FROM provider WHERE account_id = 32))");
$pdo->exec('DELETE FROM subscription WHERE plan_id = ' . $cardPlan);
$pdo->exec('DELETE FROM subscription_plan WHERE id = ' . $cardPlan);
$pdo->exec("DELETE FROM provider WHERE account_id IN (30, 32)");
$pdo->exec('DELETE FROM category WHERE id = ' . $cardCatId);
$pdo->exec('DELETE FROM account WHERE id IN (30, 31, 32)');
$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'card'");

// --- Social login: Google ---------------------------------------------------
// No check here reaches the network: Google's token and userinfo endpoints
// are replaced by something that records what was asked and answers from a script.
$googleHttp = new class implements Modulento\Core\Support\HttpClient {
    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];
    /** @var array<int, array{status: int, body: string}> */
    private array $answers = [];

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return array_shift($this->answers) ?? ['status' => 0, 'body' => ''];
    }

    public function answer(int $status, array|string $body): void
    {
        $this->answers[] = ['status' => $status, 'body' => is_array($body) ? (string) json_encode($body) : $body];
    }

    public function reset(): void
    {
        $this->requests = [];
        $this->answers = [];
    }
};
$googleConfig = $config;
$googleConfig['app']['secret_key'] = sys_get_temp_dir() . '/modulento-test-google-key-' . bin2hex(random_bytes(4)) . '/secret.key';
$googleConfig['social_login'] = ['http' => $googleHttp];
$ggGet = fn (string $path, int|false|null $as = false) => request($pdo, $googleConfig, 'GET', $path, $as);
$ggApp = function () use ($pdo, $googleConfig): Modulento\Core\App {
    $app = new Modulento\Core\App($googleConfig, $pdo, 'de');
    $app->translator->load($googleConfig['app']['root'] . '/core/lang', 'core');

    return $app;
};

check('google login: unconfigured, the login page shows no button', !str_contains($ggGet('/login', null)['body'], 'login/google')
    && $ggApp()->googleLogin() === null);
$ggGet('/login/google', null);
check('google login: starting it without a configured client goes nowhere near Google', $googleHttp->requests === []);

$ggSettingsApp = $ggApp();
$ggSettingsApp->settings->set('core.social_login.google_client_id', 'client-123.apps.googleusercontent.com');
$ggSettingsApp->settings->set('core.social_login.google_client_secret', $ggSettingsApp->secrets->encrypt('shh-client-secret'));
check('google login: configured, the login page shows the button', str_contains($ggGet('/login', null)['body'], 'login/google'));

$google = $ggApp()->googleLogin();
check('GoogleLogin: the authorization address carries the client id, the redirect and a state', $google !== null
    && str_contains($google->authUrl('xyz'), 'client_id=client-123.apps.googleusercontent.com')
    && str_contains($google->authUrl('xyz'), 'redirect_uri=' . rawurlencode('https://example.test/login/google/callback'))
    && str_contains($google->authUrl('xyz'), 'state=xyz'));

$googleHttp->answer(0, '');
check('GoogleLogin: no answer from the token endpoint fails closed', $google->exchange('code') === null);
$googleHttp->answer(200, ['access_token' => 'tok']);
$googleHttp->answer(200, ['sub' => '']);
check('GoogleLogin: a userinfo answer without an email fails too', $google->exchange('code') === null);
$googleHttp->reset();
$googleHttp->answer(200, ['access_token' => 'tok']);
$googleHttp->answer(200, ['sub' => 'g-1', 'email' => 'Someone@Example.test', 'email_verified' => true, 'name' => 'Someone']);
$identity = $google->exchange('code');
check('GoogleLogin: a full answer is parsed, the address lower-cased', $identity === ['subject' => 'g-1', 'email' => 'someone@example.test', 'email_verified' => true, 'name' => 'Someone']);
check('GoogleLogin: the token exchange carries the client secret, the userinfo call the bearer token', $googleHttp->requests[0]['url'] === 'https://oauth2.googleapis.com/token'
    && str_contains((string) $googleHttp->requests[0]['body'], 'client_secret=shh-client-secret') && $googleHttp->requests[1]['headers']['Authorization'] === 'Bearer tok');

// state must be set by a request this same browser made (googleStart), and match what comes back.
// Nothing is queued for Google here: a mismatched state is refused before
// exchange() is ever called, so nothing would be consumed anyway.
$googleHttp->reset();
$_SESSION['google_login_state'] = 'the-real-state';
$r = $ggGet('/login/google/callback?code=x&state=wrong', false);
check('google callback: a state that does not match the session is refused, nothing is asked of Google', ($_SESSION['account_id'] ?? null) === null
    && str_contains($_SESSION['_flash']['error'] ?? '', 'nicht geklappt') && $googleHttp->requests === []);

$_SESSION['google_login_state'] = 'the-real-state';
$googleHttp->answer(200, ['access_token' => 'tok']);
$googleHttp->answer(200, ['sub' => 'g-3', 'email' => 'brandnew@example.test', 'email_verified' => true, 'name' => 'Brand New']);
$ggGet('/login/google/callback?code=x&state=the-real-state', false);
$new = $pdo->query("SELECT * FROM account WHERE email = 'brandnew@example.test'")->fetch();
check('google callback: an unknown, verified address creates a brand new account, already confirmed', $new !== false
    && $new['email_verified_at'] !== null && ($_SESSION['account_id'] ?? null) == $new['id']);
check('google callback: linked by the subject, for next time', $pdo->query("SELECT account_id FROM account_identity WHERE provider = 'google' AND subject = 'g-3'")->fetchColumn() == $new['id']);

// A second sign-in with the same Google account: found by the link, no new account, no second mail etc.
$_SESSION['google_login_state'] = 's2';
$googleHttp->answer(200, ['access_token' => 'tok']);
$googleHttp->answer(200, ['sub' => 'g-3', 'email' => 'brandnew@example.test', 'email_verified' => true]);
$ggGet('/login/google/callback?code=x&state=s2', false);
check('google callback: signing in again finds the same account by its link, creates no second one', ($_SESSION['account_id'] ?? null) == $new['id']
    && $pdo->query("SELECT COUNT(*) FROM account WHERE email = 'brandnew@example.test'")->fetchColumn() == 1);

// An existing, password-based account with the same verified address: linked instead of duplicated.
$pdo->exec("UPDATE setting SET value = 'open' WHERE name = 'core.registration'");
$pdo->exec("DELETE FROM rate_limit_attempt WHERE action = 'register'");
$post('/register', ['email' => 'already@example.test', 'password' => 'a password long enough', 'password_repeat' => 'a password long enough'], null);
$get(lastMail($mailLog, 'already@example.test')['link']);
$existing = $pdo->query("SELECT * FROM account WHERE email = 'already@example.test'")->fetch();
$_SESSION['google_login_state'] = 's3';
$googleHttp->answer(200, ['access_token' => 'tok']);
$googleHttp->answer(200, ['sub' => 'g-4', 'email' => 'already@example.test', 'email_verified' => true]);
$ggGet('/login/google/callback?code=x&state=s3', false);
check('google callback: a verified address that already has a password-based account is linked to it, not duplicated',
    ($_SESSION['account_id'] ?? null) == $existing['id'] && $pdo->query("SELECT COUNT(*) FROM account WHERE email = 'already@example.test'")->fetchColumn() == 1);

// Google says the address is not verified: never trusted to link, and never to create either.
$_SESSION = [];
$_SESSION['google_login_state'] = 's4';
$googleHttp->answer(200, ['access_token' => 'tok']);
$googleHttp->answer(200, ['sub' => 'g-5', 'email' => 'unverified@example.test', 'email_verified' => false]);
$ggGet('/login/google/callback?code=x&state=s4', false);
check('google callback: an address Google will not vouch for signs in nowhere, and nothing is created for it', ($_SESSION['account_id'] ?? null) === null
    && str_contains($_SESSION['_flash']['error'] ?? '', 'nicht geklappt') && $pdo->query("SELECT COUNT(*) FROM account WHERE email = 'unverified@example.test'")->fetchColumn() == 0);

// --- Demo data: accounts, approved provider profiles, requests and (via the ---
// --- freelancer extension's demoOffers() registration) published offers ------
$pdo->exec("DELETE FROM extension");
$pdo->exec("INSERT INTO extension VALUES ('freelancer', '0.1.0', 1)");
$pdo->exec("DELETE FROM setting WHERE name = 'core.modules_disabled'");
$pdo->exec("INSERT INTO setting (name, value) VALUES ('core.modules_disabled', '')");
$demoCounts = fn () => [
    'accounts' => (int) $pdo->query('SELECT COUNT(*) FROM account WHERE is_demo = 1')->fetchColumn(),
    'providers' => (int) $pdo->query('SELECT COUNT(*) FROM provider WHERE is_demo = 1')->fetchColumn(),
    'offers' => (int) $pdo->query('SELECT COUNT(*) FROM offer WHERE is_demo = 1')->fetchColumn(),
    'requests' => (int) $pdo->query('SELECT COUNT(*) FROM request WHERE is_demo = 1')->fetchColumn(),
];
check('demo data: needs its permission', $get('/admin/demo-data', 1)['status'] === 403 && $post('/admin/demo-data/generate', [], 1)['status'] === 403);
check('demo data: none yet', $get('/admin/demo-data', 3)['status'] === 200 && str_contains($get('/admin/demo-data', 3)['body'], 'Noch keine Demodaten'));
$post('/admin/demo-data/generate', [], 3);
$counts = $demoCounts();
check('demo data: accounts, approved providers and published requests are created', $counts['accounts'] === 6 && $counts['providers'] === 4 && $counts['requests'] === 2
    && (int) $pdo->query("SELECT COUNT(*) FROM provider WHERE is_demo = 1 AND status = 'approved'")->fetchColumn() === 4
    && (int) $pdo->query("SELECT COUNT(*) FROM request WHERE is_demo = 1 AND status = 'published'")->fetchColumn() === 2);
check('demo data: the registered freelancer offer generator ran twice per demo provider, published', $counts['offers'] === 8
    && (int) $pdo->query("SELECT COUNT(*) FROM offer WHERE is_demo = 1 AND status = 'published' AND type = 'freelancer.service'")->fetchColumn() === 8);
check('demo data: shown as demo in the accounts list', str_contains($get('/admin/accounts', 3)['body'], 'demo.mara@example.test') && str_contains($get('/admin/accounts', 3)['body'], '<span class="badge">Demo</span>'));
$post('/admin/demo-data/generate', [], 3);
check('demo data: generating again while some already exists changes nothing', $demoCounts() === $counts && str_contains($_SESSION['_flash']['error'] ?? '', 'bereits'));
$post('/admin/demo-data/clear', [], 3);
check('demo data: clearing removes every bit of it - deleting the demo accounts was enough, the rest followed by its foreign key',
    $demoCounts() === ['accounts' => 0, 'providers' => 0, 'offers' => 0, 'requests' => 0]
    && (int) $pdo->query("SELECT COUNT(*) FROM account WHERE email = 'demo.mara@example.test'")->fetchColumn() === 0);
$post('/admin/demo-data/generate', [], 3);
check('demo data: generating again after clearing works', $demoCounts()['accounts'] === 6);
$post('/admin/demo-data/clear', [], 3);

// --- Demo data: chosen quantities and images -------------------------------
$demoImageCounts = fn () => [
    'avatars' => (int) $pdo->query("SELECT COUNT(*) FROM account_avatar WHERE account_id IN (SELECT id FROM account WHERE is_demo = 1)")->fetchColumn(),
    'offer_images' => (int) $pdo->query("SELECT COUNT(*) FROM offer_image WHERE offer_id IN (SELECT id FROM offer WHERE is_demo = 1)")->fetchColumn(),
];
$post('/admin/demo-data/generate', ['providers' => '6', 'customers' => '1', 'offers_per_provider' => '3', 'with_images' => '1'], 3);
$counts = $demoCounts();
check('demo data: a chosen quantity is used instead of the fixed default', $counts['accounts'] === 7 && $counts['providers'] === 6 && $counts['requests'] === 1 && $counts['offers'] === 18);
check('demo data: repeating beyond the base list of names still gets a distinct, published provider', str_contains($get('/admin/providers', 3)['body'], 'Mara Nowak') && str_contains($get('/admin/providers', 3)['body'], 'Mara Nowak (2)')
    && (int) $pdo->query("SELECT COUNT(DISTINCT email) FROM account WHERE is_demo = 1")->fetchColumn() === 7);
$images = $demoImageCounts();
check('demo data: with_images generates an avatar per account and two pictures per offer', $images['avatars'] === 7 && $images['offer_images'] === 36);
$avatarRow = $pdo->query("SELECT name, extension FROM account_avatar LIMIT 1")->fetch();
check('demo data: a generated avatar is a real picture file, not just a database row', $avatarRow !== false && preg_match('/^[a-f0-9]{32}$/', $avatarRow['name']) === 1 && in_array($avatarRow['extension'], ['webp', 'jpg'], true));
$post('/admin/demo-data/clear', [], 3);
check('demo data: clearing also removes the generated picture files, not only their rows', $demoImageCounts() === ['avatars' => 0, 'offer_images' => 0]);

$post('/admin/demo-data/generate', ['providers' => '1', 'customers' => '0', 'offers_per_provider' => '1'], 3);
check('demo data: with_images unticked generates no pictures at all', $demoImageCounts() === ['avatars' => 0, 'offer_images' => 0] && $demoCounts()['requests'] === 0);
$post('/admin/demo-data/clear', [], 3);

$post('/admin/demo-data/generate', ['providers' => (string) (Modulento\Core\Support\DemoData::MAX_PROVIDERS + 1000), 'customers' => '0', 'offers_per_provider' => '0'], 3);
check('demo data: a quantity above the maximum is clamped, not taken at face value', $demoCounts()['providers'] === Modulento\Core\Support\DemoData::MAX_PROVIDERS);
$post('/admin/demo-data/clear', [], 3);

$pdo->exec("UPDATE extension SET enabled = 0 WHERE id = 'freelancer'");

foreach ($GLOBALS['failed'] ?? [] as $label) {
    echo "FAIL  {$label}\n";
}
echo $failures === 0 ? "OK ({$checks} checks)\n" : "{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
