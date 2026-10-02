<?php

declare(strict_types=1);

namespace Modulento\Core;

use Modulento\Core\Controller\AccountController;
use Modulento\Core\Controller\AdminAccountController;
use Modulento\Core\Controller\AdminCatalogueController;
use Modulento\Core\Controller\AdminController;
use Modulento\Core\Controller\AdminOrderController;
use Modulento\Core\Controller\AdminProviderController;
use Modulento\Core\Controller\AssetController;
use Modulento\Core\Controller\AuthController;
use Modulento\Core\Controller\CronController;
use Modulento\Core\Controller\HomeController;
use Modulento\Core\Controller\MediaController;
use Modulento\Core\Controller\OfferController;
use Modulento\Core\Controller\OrderController;
use Modulento\Core\Controller\PackageController;
use Modulento\Core\Controller\PageController;
use Modulento\Core\Controller\ProviderController;
use Modulento\Core\Controller\RegistrationController;
use Modulento\Core\Controller\ReviewController;
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
        self::loadThemeTexts($app);
        $app->translator->loadOverrides($root . '/lang');

        if ($web) {
            $app->redirect = self::prepareRequest($app, $_SERVER['REQUEST_URI'] ?? '/');
        } else {
            $app->translator->setLocale($app->locales->default());
        }

        return $app;
    }

    /**
     * A site theme can bring texts of its own (a slogan, the steps on its
     * home page) in themes/<id>/lang/<locale>.php, with keys starting
     * "theme.". Only the active theme's are loaded.
     */
    public static function loadThemeTexts(App $app): void
    {
        foreach ($app->themes->siteDirs('lang') as $dir) {
            // siteDirs() lists the active theme first; "default" has no
            // texts of its own, and only one theme may define "theme.".
            $app->translator->load($dir, 'theme');
            break;
        }
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
        if (preg_match('#^/(assets|cron|media)/#', $path) === 1) {
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
        $router->get('/media/offers/{id}/{file}', [MediaController::class, 'offerImage'], Router::PUBLIC);
        $router->get('/cron/{token}', [CronController::class, 'run'], Router::PUBLIC);

        $router->get('/login', [AuthController::class, 'showLogin'], Router::PUBLIC);
        $router->post('/login', [AuthController::class, 'login'], Router::PUBLIC);
        $router->post('/logout', [AuthController::class, 'logout']);

        $router->get('/register', [RegistrationController::class, 'showRegister'], Router::PUBLIC);
        $router->post('/register', [RegistrationController::class, 'register'], Router::PUBLIC);
        $router->get('/verify-email/{token}', [AuthController::class, 'showVerifyEmail'], Router::PUBLIC);
        $router->post('/verify-email/resend', [AuthController::class, 'resendVerification'], Router::PUBLIC);
        $router->post('/verify-email/{token}', [AuthController::class, 'verifyEmail'], Router::PUBLIC);
        $router->get('/forgot-password', [RegistrationController::class, 'showForgot'], Router::PUBLIC);
        $router->post('/forgot-password', [RegistrationController::class, 'forgot'], Router::PUBLIC);
        $router->get('/reset-password/{token}', [RegistrationController::class, 'showReset'], Router::PUBLIC);
        $router->post('/reset-password/{token}', [RegistrationController::class, 'reset'], Router::PUBLIC);

        $router->get('/account', [AccountController::class, 'dashboard']);
        $router->get('/account/settings', [AccountController::class, 'index']);
        $router->post('/account/profile', [AccountController::class, 'updateProfile']);
        $router->post('/account/password', [AccountController::class, 'changePassword']);
        $router->post('/account/email', [AccountController::class, 'changeEmail']);
        $router->get('/account/confirm-email/{token}', [AccountController::class, 'confirmEmail']);
        $router->get('/account/export', [AccountController::class, 'export']);
        $router->post('/account/delete', [AccountController::class, 'delete']);
        $router->get('/account/provider', [ProviderController::class, 'edit']);
        $router->post('/account/provider', [ProviderController::class, 'save']);

        $router->get('/account/offers', [OfferController::class, 'mine']);
        $router->get('/account/offers/new', [OfferController::class, 'create']);
        $router->post('/account/offers/new', [OfferController::class, 'save']);
        $router->get('/account/offers/{id}', [OfferController::class, 'edit']);
        $router->post('/account/offers/{id}', [OfferController::class, 'save']);
        $router->post('/account/offers/{id}/submit', [OfferController::class, 'submit']);
        $router->post('/account/offers/{id}/pause', [OfferController::class, 'pause']);
        $router->post('/account/offers/{id}/resume', [OfferController::class, 'resume']);
        $router->post('/account/offers/{id}/delete', [OfferController::class, 'delete']);
        $router->post('/account/offers/{id}/images', [OfferController::class, 'uploadImage']);
        $router->post('/account/offers/{id}/images/{image}/delete', [OfferController::class, 'deleteImage']);

        $router->get('/offers', [OfferController::class, 'index'], Router::PUBLIC);
        $router->get('/offers/{slug}', [OfferController::class, 'show'], Router::PUBLIC);
        $router->post('/offers/{slug}/contact', [OfferController::class, 'contact']);
        $router->get('/offers/{slug}/order', [OrderController::class, 'form']);
        $router->post('/offers/{slug}/order', [OrderController::class, 'place']);

        $router->get('/account/orders', [OrderController::class, 'purchases']);
        $router->get('/account/sales', [OrderController::class, 'sales']);
        $router->get('/orders/{id}', [OrderController::class, 'show']);
        $router->post('/orders/{id}/transition', [OrderController::class, 'transition']);
        $router->post('/orders/{id}/message', [OrderController::class, 'message']);
        $router->post('/orders/{id}/paid', [OrderController::class, 'markPaid']);
        $router->get('/orders/{id}/files/{file}', [OrderController::class, 'download']);
        $router->post('/orders/{id}/review', [ReviewController::class, 'create']);
        $router->post('/orders/{id}/review/reply', [ReviewController::class, 'reply']);
        $router->get('/categories/{slug}', [OfferController::class, 'category'], Router::PUBLIC);

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

        $router->get('/admin/orders', [AdminOrderController::class, 'index'], 'core.orders.manage');
        $router->get('/admin/orders/{id}', [AdminOrderController::class, 'show'], 'core.orders.manage');
        $router->post('/admin/orders/{id}/transition', [AdminOrderController::class, 'transition'], 'core.orders.manage');
        $router->get('/admin/orders/{id}/files/{file}', [AdminOrderController::class, 'download'], 'core.orders.manage');

        $router->get('/admin/reviews', [ReviewController::class, 'index'], 'core.reviews.manage');
        $router->post('/admin/reviews/{id}/hide', [ReviewController::class, 'hide'], 'core.reviews.manage');
        $router->post('/admin/reviews/{id}/show', [ReviewController::class, 'show'], 'core.reviews.manage');

        $router->get('/admin/offers', [AdminCatalogueController::class, 'offers'], 'core.offers.manage');
        $router->get('/admin/offers/{id}', [AdminCatalogueController::class, 'offer'], 'core.offers.manage');
        $router->post('/admin/offers/{id}/decide', [AdminCatalogueController::class, 'decide'], 'core.offers.manage');

        $router->get('/admin/categories', [AdminCatalogueController::class, 'categories'], 'core.categories.manage');
        $router->get('/admin/categories/new', [AdminCatalogueController::class, 'editCategory'], 'core.categories.manage');
        $router->post('/admin/categories/new', [AdminCatalogueController::class, 'saveCategory'], 'core.categories.manage');
        $router->get('/admin/categories/{id}', [AdminCatalogueController::class, 'editCategory'], 'core.categories.manage');
        $router->post('/admin/categories/{id}', [AdminCatalogueController::class, 'saveCategory'], 'core.categories.manage');
        $router->post('/admin/categories/{id}/delete', [AdminCatalogueController::class, 'deleteCategory'], 'core.categories.manage');

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

        $router->get('/admin/packages', [PackageController::class, 'index'], 'core.packages.manage');
        $router->post('/admin/packages/install', [PackageController::class, 'install'], 'core.packages.manage');
        $router->post('/admin/packages/check', [PackageController::class, 'check'], 'core.packages.manage');
        $router->post('/admin/packages/{kind}/{id}/update', [PackageController::class, 'update'], 'core.packages.manage');
        $router->post('/admin/packages/{kind}/{id}/remove', [PackageController::class, 'remove'], 'core.packages.manage');

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
        $app->addPermission('core.orders.manage', 'core.permission.orders_manage');
        $app->addPermission('core.reviews.manage', 'core.permission.reviews_manage');
        $app->addPermission('core.offers.manage', 'core.permission.offers_manage');
        $app->addPermission('core.categories.manage', 'core.permission.categories_manage');
        $app->addPermission('core.providers.manage', 'core.permission.providers_manage');
        $app->addPermission('core.accounts.manage', 'core.permission.accounts_manage');
        $app->addPermission('core.roles.manage', 'core.permission.roles_manage');
        $app->addPermission('core.update.manage', 'core.permission.update_manage');
        $app->addPermission('core.packages.manage', 'core.permission.packages_manage');

        $app->addAdminMenu('core.admin.menu.settings', '/admin/settings', 'core.settings.manage');
        $app->addAdminMenu('core.admin.menu.pages', '/admin/pages', 'core.pages.manage');
        $app->addAdminMenu('core.admin.menu.orders', '/admin/orders', 'core.orders.manage');
        $app->addAdminMenu('core.admin.menu.reviews', '/admin/reviews', 'core.reviews.manage');
        $app->addAdminMenu('core.admin.menu.offers', '/admin/offers', 'core.offers.manage');
        $app->addAdminMenu('core.admin.menu.categories', '/admin/categories', 'core.categories.manage');
        $app->addAdminMenu('core.admin.menu.providers', '/admin/providers', 'core.providers.manage');
        $app->addAdminMenu('core.admin.menu.accounts', '/admin/accounts', 'core.accounts.manage');
        $app->addAdminMenu('core.admin.menu.roles', '/admin/roles', 'core.roles.manage');
        $app->addAdminMenu('core.admin.menu.extensions', '/admin/extensions', 'core.extensions.manage');
        $app->addAdminMenu('core.admin.menu.themes', '/admin/themes', 'core.themes.manage');
        $app->addAdminMenu('core.admin.menu.packages', '/admin/packages', 'core.packages.manage');
        $app->addAdminMenu('core.admin.menu.tasks', '/admin/tasks', 'core.tasks.view');
        $app->addAdminMenu('core.admin.menu.updates', '/admin/updates', 'core.update.manage');

        $app->scheduler->register(
            'core.rate-limit-cleanup',
            60,
            fn (App $app) => (new RateLimiter($app->db))->cleanup()
        );
        // Deadlines of orders: what happens when nobody acts in time.
        $app->scheduler->register('core.order-deadlines', 5, fn (App $app) => $app->orders->runDeadlines($app));
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
