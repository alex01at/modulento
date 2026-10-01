<?php

declare(strict_types=1);

namespace Modulento\Core;

use Modulento\Core\Controller\AccountController;
use Modulento\Core\Controller\AdminAccountController;
use Modulento\Core\Controller\AdminController;
use Modulento\Core\Controller\AdminProviderController;
use Modulento\Core\Controller\AssetController;
use Modulento\Core\Controller\AuthController;
use Modulento\Core\Controller\CronController;
use Modulento\Core\Controller\HomeController;
use Modulento\Core\Controller\PageController;
use Modulento\Core\Controller\ProviderController;
use Modulento\Core\Controller\RegistrationController;
use Modulento\Core\Controller\SettingsController;
use Modulento\Core\Controller\UpdateController;
use Modulento\Core\Support\Database;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Router;
use Modulento\Core\Support\Session;

/**
 * The single bootstrap used by public/index.php, bin/cron.php and the other
 * CLI scripts, so web and command line always see the same routes, tasks
 * and extensions.
 */
final class Kernel
{
    public static function boot(string $root, bool $web): App
    {
        $config = require $root . '/config/config.php';

        if ($config['db']['name'] === '' || $config['db']['user'] === '') {
            throw new NotConfiguredException('Database is not configured - copy .env.example to .env and fill in DB_*');
        }

        $db = Database::connect($config['db']);

        $app = new App($config, $db);
        $app->translator->load($root . '/core/lang', 'core');

        // Core routes are registered first: the first match wins, so an
        // extension can never take over /login or /admin.
        self::registerCore($app);
        $app->extensions->loadEnabled($app);
        self::registerLast($app);
        $app->translator->loadOverrides($root . '/lang');

        if ($web) {
            $app->redirect = self::prepareRequest($app, $_SERVER['REQUEST_URI'] ?? '/');
        } else {
            $app->translator->setLocale($app->locales->default());
        }

        return $app;
    }

    /**
     * Reads the language from the address and starts the session.
     *
     * @return string|null an address to redirect to instead of answering
     *         (the default language requested with its prefix)
     */
    public static function prepareRequest(App $app, string $uri, bool $startSession = true): ?string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $query = parse_url($uri, PHP_URL_QUERY);

        $app->translator->setFallback($app->locales->default());
        $app->translator->setSiteName($app->siteName());

        // Asset and cron requests need neither a login nor a language, so
        // they skip the session: no session file per cron call, and assets
        // load in parallel instead of queueing on the session lock.
        if (preg_match('#^/(assets|cron)/#', $path) === 1) {
            $app->path = $path;
            $app->translator->setLocale($app->locales->default());
            return null;
        }

        if ($startSession) {
            Session::start();
        }

        $route = $app->locales->split($path);
        $app->path = $route['path'];
        $app->translator->setLocale($route['locale']);

        return $route['redirect'] !== null ? $route['redirect'] . ($query ? '?' . $query : '') : null;
    }

    /** Public so the test suite checks the real route table. */
    public static function registerCore(App $app): void
    {
        $router = $app->router;

        $router->get('/', [HomeController::class, 'index'], Router::PUBLIC);

        $router->get('/assets/theme/{path*}', [AssetController::class, 'theme'], Router::PUBLIC);
        $router->get('/assets/admin/{path*}', [AssetController::class, 'admin'], Router::PUBLIC);
        $router->get('/assets/ext/{id}/{path*}', [AssetController::class, 'extension'], Router::PUBLIC);
        $router->get('/cron/{token}', [CronController::class, 'run'], Router::PUBLIC);

        $router->get('/login', [AuthController::class, 'showLogin'], Router::PUBLIC);
        $router->post('/login', [AuthController::class, 'login'], Router::PUBLIC);
        $router->post('/logout', [AuthController::class, 'logout']);

        $router->get('/register', [RegistrationController::class, 'showRegister'], Router::PUBLIC);
        $router->post('/register', [RegistrationController::class, 'register'], Router::PUBLIC);
        $router->get('/verify-email/{token}', [AuthController::class, 'verifyEmail'], Router::PUBLIC);
        $router->post('/verify-email/resend', [AuthController::class, 'resendVerification'], Router::PUBLIC);
        $router->get('/forgot-password', [RegistrationController::class, 'showForgot'], Router::PUBLIC);
        $router->post('/forgot-password', [RegistrationController::class, 'forgot'], Router::PUBLIC);
        $router->get('/reset-password/{token}', [RegistrationController::class, 'showReset'], Router::PUBLIC);
        $router->post('/reset-password/{token}', [RegistrationController::class, 'reset'], Router::PUBLIC);

        $router->get('/account', [AccountController::class, 'index']);
        $router->post('/account/profile', [AccountController::class, 'updateProfile']);
        $router->post('/account/password', [AccountController::class, 'changePassword']);
        $router->post('/account/email', [AccountController::class, 'changeEmail']);
        $router->get('/account/confirm-email/{token}', [AccountController::class, 'confirmEmail']);
        $router->get('/account/export', [AccountController::class, 'export']);
        $router->post('/account/delete', [AccountController::class, 'delete']);
        $router->get('/account/provider', [ProviderController::class, 'edit']);
        $router->post('/account/provider', [ProviderController::class, 'save']);

        $router->get('/providers', [ProviderController::class, 'index'], Router::PUBLIC);
        $router->get('/providers/{slug}', [ProviderController::class, 'show'], Router::PUBLIC);

        $router->get('/admin', [AdminController::class, 'index'], 'core.admin.access');
        $router->get('/admin/extensions', [AdminController::class, 'extensions'], 'core.extensions.manage');
        $router->post('/admin/extensions/{id}/enable', [AdminController::class, 'enableExtension'], 'core.extensions.manage');
        $router->post('/admin/extensions/{id}/disable', [AdminController::class, 'disableExtension'], 'core.extensions.manage');
        $router->get('/admin/tasks', [AdminController::class, 'tasks'], 'core.tasks.view');

        $router->get('/admin/settings', [SettingsController::class, 'index'], 'core.settings.manage');
        $router->post('/admin/settings', [SettingsController::class, 'save'], 'core.settings.manage');

        $router->get('/admin/pages', [PageController::class, 'index'], 'core.pages.manage');
        $router->get('/admin/pages/new', [PageController::class, 'edit'], 'core.pages.manage');
        $router->post('/admin/pages/new', [PageController::class, 'save'], 'core.pages.manage');
        $router->get('/admin/pages/{id}', [PageController::class, 'edit'], 'core.pages.manage');
        $router->post('/admin/pages/{id}', [PageController::class, 'save'], 'core.pages.manage');
        $router->post('/admin/pages/{id}/delete', [PageController::class, 'delete'], 'core.pages.manage');

        $router->get('/admin/providers', [AdminProviderController::class, 'index'], 'core.providers.manage');
        $router->get('/admin/providers/{id}', [AdminProviderController::class, 'show'], 'core.providers.manage');
        $router->post('/admin/providers/{id}/decide', [AdminProviderController::class, 'decide'], 'core.providers.manage');

        $router->get('/admin/accounts', [AdminAccountController::class, 'index'], 'core.accounts.manage');
        $router->get('/admin/accounts/{id}', [AdminAccountController::class, 'show'], 'core.accounts.manage');
        $router->post('/admin/accounts/{id}/block', [AdminAccountController::class, 'block'], 'core.accounts.manage');
        $router->post('/admin/accounts/{id}/unblock', [AdminAccountController::class, 'unblock'], 'core.accounts.manage');
        $router->post('/admin/accounts/{id}/verify', [AdminAccountController::class, 'verify'], 'core.accounts.manage');
        $router->post('/admin/accounts/{id}/reset', [AdminAccountController::class, 'sendReset'], 'core.accounts.manage');
        $router->post('/admin/accounts/{id}/delete', [AdminAccountController::class, 'delete'], 'core.accounts.manage');
        $router->post('/admin/accounts/{id}/roles', [AdminAccountController::class, 'setRoles'], 'core.roles.manage');

        $router->get('/admin/roles', [AdminAccountController::class, 'roles'], 'core.roles.manage');
        $router->get('/admin/roles/new', [AdminAccountController::class, 'editRole'], 'core.roles.manage');
        $router->post('/admin/roles/new', [AdminAccountController::class, 'saveRole'], 'core.roles.manage');
        $router->get('/admin/roles/{id}', [AdminAccountController::class, 'editRole'], 'core.roles.manage');
        $router->post('/admin/roles/{id}', [AdminAccountController::class, 'saveRole'], 'core.roles.manage');
        $router->post('/admin/roles/{id}/delete', [AdminAccountController::class, 'deleteRole'], 'core.roles.manage');

        $router->get('/admin/themes', [AdminController::class, 'themes'], 'core.themes.manage');
        $router->post('/admin/themes/{id}/activate', [AdminController::class, 'activateTheme'], 'core.themes.manage');

        $router->get('/admin/updates', [UpdateController::class, 'index'], 'core.update.manage');
        $router->post('/admin/updates/migrate', [UpdateController::class, 'migrate'], 'core.update.manage');
        $router->post('/admin/updates/check', [UpdateController::class, 'check'], 'core.update.manage');
        $router->post('/admin/updates/apply', [UpdateController::class, 'apply'], 'core.update.manage');

        $app->addPermission('core.admin.access', 'core.permission.admin_access');
        $app->addPermission('core.extensions.manage', 'core.permission.extensions_manage');
        $app->addPermission('core.tasks.view', 'core.permission.tasks_view');
        $app->addPermission('core.themes.manage', 'core.permission.themes_manage');
        $app->addPermission('core.settings.manage', 'core.permission.settings_manage');
        $app->addPermission('core.pages.manage', 'core.permission.pages_manage');
        $app->addPermission('core.providers.manage', 'core.permission.providers_manage');
        $app->addPermission('core.accounts.manage', 'core.permission.accounts_manage');
        $app->addPermission('core.roles.manage', 'core.permission.roles_manage');
        $app->addPermission('core.update.manage', 'core.permission.update_manage');

        $app->addAdminMenu('core.admin.menu.settings', '/admin/settings', 'core.settings.manage');
        $app->addAdminMenu('core.admin.menu.pages', '/admin/pages', 'core.pages.manage');
        $app->addAdminMenu('core.admin.menu.providers', '/admin/providers', 'core.providers.manage');
        $app->addAdminMenu('core.admin.menu.accounts', '/admin/accounts', 'core.accounts.manage');
        $app->addAdminMenu('core.admin.menu.roles', '/admin/roles', 'core.roles.manage');
        $app->addAdminMenu('core.admin.menu.extensions', '/admin/extensions', 'core.extensions.manage');
        $app->addAdminMenu('core.admin.menu.themes', '/admin/themes', 'core.themes.manage');
        $app->addAdminMenu('core.admin.menu.tasks', '/admin/tasks', 'core.tasks.view');
        $app->addAdminMenu('core.admin.menu.updates', '/admin/updates', 'core.update.manage');

        $app->scheduler->register(
            'core.rate-limit-cleanup',
            60,
            fn (App $app) => (new RateLimiter($app->db))->cleanup()
        );
        // Expired mail links, and registrations whose address was never
        // confirmed within a week.
        $app->scheduler->register('core.account-cleanup', 60, function (App $app): void {
            $app->tokens->purgeExpired();
            $app->accounts->deleteUnverifiedOlderThan(7 * 86400);
        });
    }

    /**
     * Content pages take whatever one-segment address is left, so this is
     * registered after the core's and every extension's routes.
     */
    public static function registerLast(App $app): void
    {
        $app->router->get('/{slug}', [PageController::class, 'show'], Router::PUBLIC);
    }
}
