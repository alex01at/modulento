<?php

declare(strict_types=1);

// The setup the HTTP-level checks share: an in-memory SQLite database with the
// core's tables, a test configuration and request(), which sends one request
// through the real kernel and router. Required by tests/run.php and by the
// tests of extensions that drive the core the same way. Expects $root.

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
$pdo->exec("CREATE TABLE account_login_token (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    selector TEXT UNIQUE, token_hash TEXT, previous_hash TEXT, rotated_at TEXT, auth_stamp TEXT, user_agent TEXT, created_at TEXT, last_used_at TEXT, expires_at TEXT)");
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
$pdo->exec("CREATE TABLE report (id INTEGER PRIMARY KEY, url TEXT, category TEXT, explanation TEXT, name TEXT, email TEXT, locale TEXT,
    account_id INTEGER REFERENCES account (id) ON DELETE SET NULL, status TEXT DEFAULT 'open', decision_note TEXT, decided_at TEXT, decided_by INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE provider_payment (provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, method TEXT, data TEXT, status TEXT, updated_at TEXT, PRIMARY KEY (provider_id, method))");
$pdo->exec("CREATE TABLE order_payment (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, method TEXT, provider_reference TEXT, status TEXT,
    amount INTEGER, currency TEXT, created_at TEXT, updated_at TEXT, UNIQUE (method, provider_reference))");
$pdo->exec("ALTER TABLE report ADD COLUMN offer_id INTEGER");
$pdo->exec("ALTER TABLE report ADD COLUMN provider_id INTEGER");
$pdo->exec("CREATE TABLE account_avatar (account_id INTEGER PRIMARY KEY REFERENCES account (id) ON DELETE CASCADE, name TEXT, extension TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE offer_message (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, asker_id INTEGER REFERENCES account (id) ON DELETE CASCADE, author_id INTEGER REFERENCES account (id) ON DELETE SET NULL, body TEXT, created_at TEXT)");
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
