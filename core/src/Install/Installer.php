<?php

declare(strict_types=1);

namespace Modulento\Core\Install;

use Modulento\Core\Support\AdminAccount;
use Modulento\Core\Support\Csrf;
use Modulento\Core\Support\Database;
use Modulento\Core\Support\Migrator;
use Modulento\Core\Support\PasswordPolicy;
use Modulento\Core\Support\Session;
use Modulento\Core\Support\Translator;
use PDOException;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Setup in the browser, for hosts without shell access. public/index.php
 * hands every request to it for as long as .env holds no database
 * settings.
 *
 * Invariant: .env is written as the very last step. Everything before it
 * (connection test, migrations, admin account) uses its own connection
 * built from the submitted form, so a failure at any point leaves the
 * installation unconfigured and the form can simply be sent again - and
 * once .env exists, the installer is unreachable because the gate in
 * Kernel::boot() no longer throws.
 *
 * It has its own templates in core/install/ rather than using a theme: it
 * must work before any theme setting can be read from the database.
 */
final class Installer
{
    private const FIELDS = ['site_name', 'db_host', 'db_port', 'db_name', 'db_user', 'admin_email'];

    private Translator $translator;

    public function __construct(private string $root)
    {
    }

    public function handle(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        if ($path === '/install.css') {
            header('Content-Type: text/css; charset=utf-8');
            readfile($this->root . '/core/install/install.css');
            return;
        }

        Session::start();
        $locale = Translator::detectLocale($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
        $this->translator = new Translator($locale, 'Modulento');
        $this->translator->load($this->root . '/core/lang', 'core');

        header('Cache-Control: no-store');

        $values = ['site_name' => 'Modulento', 'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'admin_email' => ''];
        $errors = [];

        if ($method === 'POST') {
            foreach (self::FIELDS as $field) {
                $values[$field] = trim((string) ($_POST[$field] ?? ''));
            }

            $errors = $this->requirementErrors();
            if (!Csrf::verify($_POST['_csrf'] ?? null)) {
                $errors[] = $this->translator->trans('core.error.csrf');
            }
            if ($errors === []) {
                $errors = $this->install($values, (string) ($_POST['db_pass'] ?? ''), (string) ($_POST['admin_password'] ?? ''));
            }
            if ($errors === []) {
                Session::flash('success', $this->translator->trans('core.install.done'));
                header('Location: /login');
                return;
            }
        }

        $twig = new Environment(new FilesystemLoader($this->root . '/core/install'), ['cache' => false]);
        echo $twig->render('install.twig', [
            'locale' => $locale,
            't' => array_filter(
                require $this->root . '/core/lang/' . $locale . '.php',
                fn (string $key) => str_starts_with($key, 'core.install.'),
                ARRAY_FILTER_USE_KEY
            ),
            'requirements' => $this->requirements(),
            'values' => $values,
            'errors' => $errors,
            'csrf' => Csrf::token(),
        ]);
    }

    /** @return array<int, array{label: string, ok: bool}> */
    public function requirements(): array
    {
        $checks = [
            ['PHP ' . PHP_VERSION . ' (>= 8.3)', PHP_VERSION_ID >= 80300],
        ];
        foreach (['pdo_mysql', 'curl', 'zip', 'mbstring', 'ctype'] as $extension) {
            $checks[] = ['PHP: ' . $extension, extension_loaded($extension)];
        }
        // .env is created in the installation folder; var/ holds the
        // template cache, logs and update backups.
        foreach (['' => $this->root, 'var/' => $this->root . '/var', 'var/cache/twig/' => $this->root . '/var/cache/twig', 'var/log/' => $this->root . '/var/log'] as $label => $dir) {
            $checks[] = [$this->translator->trans('core.install.writable', ['path' => $label !== '' ? $label : basename($this->root) . '/']), is_dir($dir) && is_writable($dir)];
        }

        return array_map(fn (array $check) => ['label' => $check[0], 'ok' => $check[1]], $checks);
    }

    /** @return string[] */
    private function requirementErrors(): array
    {
        foreach ($this->requirements() as $requirement) {
            if (!$requirement['ok']) {
                return [$this->translator->trans('core.install.requirements_failed')];
            }
        }

        return [];
    }

    /**
     * @param array<string, string> $values
     * @return string[] error messages; empty means the installation is complete
     */
    private function install(array $values, string $dbPass, string $adminPassword): array
    {
        $t = $this->translator;
        $errors = [];

        if ($values['site_name'] === '') {
            $errors[] = $t->trans('core.install.error.site_name');
        }
        if ($values['db_host'] === '' || $values['db_name'] === '' || $values['db_user'] === '' || !ctype_digit($values['db_port'])) {
            $errors[] = $t->trans('core.install.error.db_fields');
        }
        if (filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = $t->trans('core.install.error.admin_email');
        }
        if (strlen($adminPassword) < PasswordPolicy::MIN_LENGTH) {
            $errors[] = $t->trans('core.install.error.admin_password');
        } elseif ($adminPassword !== (string) ($_POST['admin_password_repeat'] ?? '')) {
            $errors[] = $t->trans('core.install.error.admin_password_repeat');
        }
        if ($errors !== []) {
            return $errors;
        }

        try {
            $db = Database::connect([
                'host' => $values['db_host'],
                'port' => $values['db_port'],
                'name' => $values['db_name'],
                'user' => $values['db_user'],
                'pass' => $dbPass,
            ]);
        } catch (PDOException $e) {
            // The driver's message says whether it is the host, the
            // name or the password - what the person at the form needs.
            return [$t->trans('core.install.error.db_connect', ['reason' => $e->getMessage()])];
        }

        try {
            Migrator::run($db, 'core', $this->root . '/core/migrations');
            AdminAccount::create($db, $values['admin_email'], $adminPassword);
        } catch (Throwable $e) {
            error_log('Installer: ' . $e);

            return [$t->trans('core.install.error.setup', ['reason' => $e->getMessage()])];
        }

        $env = self::envFile([
            'APP_ENV' => 'prod',
            'APP_URL' => self::requestOrigin(),
            'APP_NAME' => $values['site_name'],
            'CRON_TOKEN' => bin2hex(random_bytes(24)),
            'DB_HOST' => $values['db_host'],
            'DB_PORT' => $values['db_port'],
            'DB_NAME' => $values['db_name'],
            'DB_USER' => $values['db_user'],
            'DB_PASS' => $dbPass,
            'UPDATE_REPO' => 'alex01at/modulento',
            'UPDATE_TOKEN' => '',
        ]);

        $tmp = $this->root . '/.env.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $env) === false || !rename($tmp, $this->root . '/.env')) {
            @unlink($tmp);

            return [$t->trans('core.install.error.env')];
        }

        Session::regenerate();

        return [];
    }

    /**
     * Every value is double-quoted and escaped: the dotenv parser fails on
     * an unquoted value with a space, and inside double quotes it would
     * otherwise read "$" as a variable and "\" as an escape - both common
     * in generated database passwords.
     *
     * @param array<string, string> $values
     */
    public static function envFile(array $values): string
    {
        $lines = [];
        foreach ($values as $name => $value) {
            $value = str_replace(["\r", "\n"], '', $value);
            $lines[] = $name . '="' . strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']) . '"';
        }

        return implode("\n", $lines) . "\n";
    }

    private static function requestOrigin(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host) !== 1) {
            $host = 'localhost';
        }

        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $host;
    }
}
