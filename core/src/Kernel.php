<?php

declare(strict_types=1);

namespace Modulento\Core;

use Modulento\Core\Controller\AccountController;
use Modulento\Core\Controller\AdminAccountController;
use Modulento\Core\Controller\AdminCatalogueController;
use Modulento\Core\Controller\AdminController;
use Modulento\Core\Controller\AdminBadWordsController;
use Modulento\Core\Controller\AdminHomeController;
use Modulento\Core\Controller\AdminMediaController;
use Modulento\Core\Controller\AdminOrderController;
use Modulento\Core\Controller\AdminPaymentController;
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
use Modulento\Core\Controller\PaymentController;
use Modulento\Core\Controller\PaymentSettingsController;
use Modulento\Core\Controller\ProviderController;
use Modulento\Core\Controller\RegistrationController;
use Modulento\Core\Controller\ReviewController;
use Modulento\Core\Controller\SettingsController;
use Modulento\Core\Controller\UpdateController;
use Modulento\Core\Controller\ReportController;
use Modulento\Core\Controller\WithdrawalController;
use Modulento\Core\Event\AccountLoggedIn;
use Modulento\Core\Support\ClientIp;
use Modulento\Core\Support\Database;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\RememberCookie;
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

        // From here on REMOTE_ADDR is the visitor, also behind a proxy.
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $_SERVER['REMOTE_ADDR'] = ClientIp::resolve($_SERVER, $app->config['app']['trusted_proxies'] ?? []);
        }

        $app->translator->setFallback($app->locales->default());
        $app->translator->setSiteName($app->siteName());

        // Asset, cron and webhook requests need neither a login nor a
        // language, so they skip the session: no session file per cron call, and assets
        // load in parallel instead of queueing on the session lock.
        if (preg_match('#^/(assets|cron|media|webhooks)/#', $path) === 1) {
            $app->path = $path;
            $app->translator->setLocale($app->locales->default());
            return null;
        }

        if ($startSession) {
            Session::start();
        }

        // "Stay logged in": the session is gone (the browser was closed),
        // the cookie is still there. Only here, where a session exists to
        // log in to - never for the asset, cron and media requests above.
        if ($app->modules->enabled('remember_login') && RememberCookie::present() && !$app->auth->check()) {
            $accountId = $app->loginTokens->resume();
            if ($accountId !== null) {
                $app->auth->login($accountId);
                $app->events->dispatch(new AccountLoggedIn($accountId));
            }
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
        $router->get('/account/unread', [AccountController::class, 'unread']);
        $router->post('/account/profile', [AccountController::class, 'updateProfile']);
        $router->post('/account/appearance', [AccountController::class, 'updateAppearance']);
        $router->post('/account/admin-layout', [AccountController::class, 'updateAdminLayout'], 'core.admin.access');
        $router->post('/account/password', [AccountController::class, 'changePassword']);
        $router->post('/account/email', [AccountController::class, 'changeEmail']);
        $router->get('/account/confirm-email/{token}', [AccountController::class, 'confirmEmail']);
        $router->get('/account/export', [AccountController::class, 'export']);
        $router->post('/account/delete', [AccountController::class, 'delete']);
        $router->get('/account/provider', [ProviderController::class, 'edit']);
        $router->post('/account/provider', [ProviderController::class, 'save']);

        $router->get('/account/payments', [PaymentSettingsController::class, 'index']);
        $router->post('/account/payments/transfer', [PaymentSettingsController::class, 'saveTransfer']);
        $router->post('/account/payments/paypal', [PaymentSettingsController::class, 'savePaypal']);
        $router->post('/account/payments/paypal/check', [PaymentSettingsController::class, 'checkPaypal']);
        $router->post('/account/payments/stripe/connect', [PaymentSettingsController::class, 'connectStripe']);
        // Stripe sends the provider back to these two with a plain link.
        $router->get('/account/payments/stripe/return', [PaymentSettingsController::class, 'stripeStatus']);
        $router->get('/account/payments/stripe/refresh', [PaymentSettingsController::class, 'resumeStripe']);
        $router->post('/account/payments/stripe/status', [PaymentSettingsController::class, 'stripeStatus']);
        $router->post('/account/payments/{method}/delete', [PaymentSettingsController::class, 'delete']);

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
        $router->get('/offers/{slug}/order', [OrderController::class, 'form']);
        $router->post('/offers/{slug}/order', [OrderController::class, 'place']);

        $router->get('/account/orders', [OrderController::class, 'purchases']);
        $router->get('/account/sales', [OrderController::class, 'sales']);
        $router->get('/orders/{id}', [OrderController::class, 'show']);
        $router->post('/orders/{id}/transition', [OrderController::class, 'transition']);
        $router->post('/orders/{id}/message', [OrderController::class, 'message']);
        $router->post('/orders/{id}/paid', [OrderController::class, 'markPaid']);
        $router->post('/orders/{id}/pay', [PaymentController::class, 'pay']);
        $router->post('/orders/{id}/payment-method', [PaymentController::class, 'choose']);
        $router->get('/orders/{id}/payments/{payment}/return', [PaymentController::class, 'back']);
        // Called by Stripe, not by a browser: no session, no CSRF token.
        // The signature of the request is what authenticates it.
        $router->post('/webhooks/stripe', [PaymentController::class, 'stripeWebhook'], Router::PUBLIC, csrfExempt: true);
        $router->get('/orders/{id}/files/{file}', [OrderController::class, 'download']);
        $router->get('/categories/{slug}', [OfferController::class, 'category'], Router::PUBLIC);


        $router->get('/providers', [ProviderController::class, 'index'], Router::PUBLIC);
        $router->get('/providers/{slug}', [ProviderController::class, 'show'], Router::PUBLIC);

        $router->get('/admin', [AdminController::class, 'index'], 'core.admin.access');
        $router->get('/admin/docs', [AdminController::class, 'docs'], 'core.admin.access');
        $router->get('/admin/section/{id}', [AdminController::class, 'section'], 'core.admin.access');

        // Optional functions (Administration → Modules). A module that is
        // switched off has no routes and no menu entries; its data stays.
        $modules = $app->modules;
        $router->get('/admin/modules', [AdminController::class, 'modules'], 'core.settings.manage');
        $router->post('/admin/modules', [AdminController::class, 'saveModules'], 'core.settings.manage');
        if ($modules->enabled('avatars')) {
            $router->get('/media/avatars/{file}', [MediaController::class, 'avatar'], Router::PUBLIC);
        $router->get('/media/branding/{file}', [MediaController::class, 'branding'], Router::PUBLIC);
        $router->get('/media/library/{file}', [MediaController::class, 'library'], Router::PUBLIC);
            $router->post('/account/avatar', [AccountController::class, 'setAvatar']);
            $router->post('/account/avatar/delete', [AccountController::class, 'deleteAvatar']);
        }
        if ($modules->enabled('remember_login')) {
            $router->post('/account/sessions/revoke', [AccountController::class, 'revokeSessions']);
        }
        if ($modules->enabled('contact')) {
            $router->post('/offers/{slug}/contact', [OfferController::class, 'contact']);
            $router->post('/offers/{slug}/contact/{asker}', [OfferController::class, 'reply']);
        }
        if ($modules->enabled('reviews')) {
            $router->post('/orders/{id}/review', [ReviewController::class, 'create']);
            $router->post('/orders/{id}/review/reply', [ReviewController::class, 'reply']);
            $router->get('/admin/reviews', [ReviewController::class, 'index'], 'core.reviews.manage');
            $router->post('/admin/reviews/{id}/hide', [ReviewController::class, 'hide'], 'core.reviews.manage');
            $router->post('/admin/reviews/{id}/show', [ReviewController::class, 'show'], 'core.reviews.manage');
        }
        if ($modules->enabled('withdrawal')) {
            // The withdrawal form is public on purpose: it has to be reachable
            // for the whole withdrawal period, also by someone who cannot log in.
            $router->get('/withdrawal', [WithdrawalController::class, 'form'], Router::PUBLIC);
            $router->post('/withdrawal', [WithdrawalController::class, 'review'], Router::PUBLIC);
            $router->post('/withdrawal/confirm', [WithdrawalController::class, 'confirm'], Router::PUBLIC);
            $router->get('/withdrawal/done', [WithdrawalController::class, 'done'], Router::PUBLIC);
            $router->get('/admin/withdrawals', [WithdrawalController::class, 'index'], 'core.orders.manage');
            $router->post('/admin/withdrawals/{id}/handled', [WithdrawalController::class, 'handled'], 'core.orders.manage');
        }
        if ($modules->enabled('reports')) {
            // Reporting content needs no account: whoever sees something
            // illegal has to be able to say so.
            $router->get('/report', [ReportController::class, 'form'], Router::PUBLIC);
            $router->post('/report', [ReportController::class, 'send'], Router::PUBLIC);
            $router->get('/report/done', [ReportController::class, 'done'], Router::PUBLIC);
            $router->get('/admin/reports', [ReportController::class, 'index'], 'core.reports.manage');
            $router->post('/admin/reports/{id}/decide', [ReportController::class, 'decide'], 'core.reports.manage');
        }
        $router->get('/admin/extensions', [AdminController::class, 'extensions'], 'core.extensions.manage');
        $router->post('/admin/extensions/{id}/enable', [AdminController::class, 'enableExtension'], 'core.extensions.manage');
        $router->post('/admin/extensions/{id}/disable', [AdminController::class, 'disableExtension'], 'core.extensions.manage');
        $router->get('/admin/tasks', [AdminController::class, 'tasks'], 'core.tasks.view');

        $router->get('/admin/settings', [SettingsController::class, 'index'], 'core.settings.manage');
        $router->post('/admin/settings', [SettingsController::class, 'save'], 'core.settings.manage');
        $router->get('/admin/payments', [AdminPaymentController::class, 'index'], 'core.settings.manage');
        $router->post('/admin/payments', [AdminPaymentController::class, 'save'], 'core.settings.manage');

        $router->get('/admin/home', [AdminHomeController::class, 'index'], 'core.settings.manage');
        $router->post('/admin/home', [AdminHomeController::class, 'save'], 'core.settings.manage');
        $router->get('/admin/badwords', [AdminBadWordsController::class, 'index'], 'core.settings.manage');
        $router->post('/admin/badwords', [AdminBadWordsController::class, 'save'], 'core.settings.manage');
        $router->post('/admin/badwords/reset', [AdminBadWordsController::class, 'reset'], 'core.settings.manage');
        $router->get('/admin/media', [AdminMediaController::class, 'index'], 'core.media.manage');
        $router->post('/admin/media', [AdminMediaController::class, 'upload'], 'core.media.manage');
        $router->post('/admin/media/{id}/delete', [AdminMediaController::class, 'delete'], 'core.media.manage');
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
        $router->post('/admin/branding/{kind}', [AdminController::class, 'saveBranding'], 'core.themes.manage');
        $router->post('/admin/branding/{kind}/delete', [AdminController::class, 'deleteBranding'], 'core.themes.manage');

        $router->get('/admin/packages', [PackageController::class, 'index'], 'core.packages.manage');
        $router->post('/admin/packages/install', [PackageController::class, 'install'], 'core.packages.manage');
        $router->post('/admin/packages/upload', [PackageController::class, 'upload'], 'core.packages.manage');
        $router->post('/admin/packages/{kind}/{id}/update', [PackageController::class, 'update'], 'core.packages.manage');
        $router->post('/admin/packages/{kind}/{id}/remove', [PackageController::class, 'remove'], 'core.packages.manage');
        $router->post('/admin/packages/{kind}/{id}/enable', [PackageController::class, 'enable'], 'core.packages.manage');
        $router->post('/admin/packages/{kind}/{id}/disable', [PackageController::class, 'disable'], 'core.packages.manage');

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
        $app->addPermission('core.media.manage', 'core.permission.media_manage');
        $app->addPermission('core.orders.manage', 'core.permission.orders_manage');
        if ($app->modules->enabled('reviews')) {
            $app->addPermission('core.reviews.manage', 'core.permission.reviews_manage');
        }
        if ($app->modules->enabled('reports')) {
            $app->addPermission('core.reports.manage', 'core.permission.reports_manage');
        }
        $app->addPermission('core.offers.manage', 'core.permission.offers_manage');
        $app->addPermission('core.categories.manage', 'core.permission.categories_manage');
        $app->addPermission('core.providers.manage', 'core.permission.providers_manage');
        $app->addPermission('core.accounts.manage', 'core.permission.accounts_manage');
        $app->addPermission('core.roles.manage', 'core.permission.roles_manage');
        $app->addPermission('core.update.manage', 'core.permission.update_manage');
        $app->addPermission('core.packages.manage', 'core.permission.packages_manage');

        $app->addAdminMenu('core.admin.menu.settings', '/admin/settings', 'core.settings.manage', 'system');
        $app->addAdminMenu('core.admin.menu.payments', '/admin/payments', 'core.settings.manage', 'marketplace');
        $app->addAdminMenu('core.admin.menu.pages', '/admin/pages', 'core.pages.manage', 'content');
        $app->addAdminMenu('core.admin.menu.media', '/admin/media', 'core.media.manage', 'content');
        $app->addAdminMenu('core.admin.menu.home', '/admin/home', 'core.settings.manage', 'content');
        $app->addAdminMenu('core.admin.menu.badwords', '/admin/badwords', 'core.settings.manage', 'moderation');
        $app->addAdminMenu('core.admin.menu.orders', '/admin/orders', 'core.orders.manage', 'marketplace');
        if ($app->modules->enabled('withdrawal')) {
            $app->addAdminMenu('core.admin.menu.withdrawals', '/admin/withdrawals', 'core.orders.manage', 'marketplace');
        }
        if ($app->modules->enabled('reviews')) {
            $app->addAdminMenu('core.admin.menu.reviews', '/admin/reviews', 'core.reviews.manage', 'moderation');
        }
        if ($app->modules->enabled('reports')) {
            $app->addAdminMenu('core.admin.menu.reports', '/admin/reports', 'core.reports.manage', 'moderation');
        }
        $app->addAdminMenu('core.admin.menu.offers', '/admin/offers', 'core.offers.manage', 'marketplace');
        $app->addAdminMenu('core.admin.menu.categories', '/admin/categories', 'core.categories.manage', 'marketplace');
        $app->addAdminMenu('core.admin.menu.providers', '/admin/providers', 'core.providers.manage', 'marketplace');
        $app->addAdminMenu('core.admin.menu.accounts', '/admin/accounts', 'core.accounts.manage', 'people');
        $app->addAdminMenu('core.admin.menu.roles', '/admin/roles', 'core.roles.manage', 'people');
        $app->addAdminMenu('core.admin.menu.extensions', '/admin/extensions', 'core.extensions.manage', 'system');
        $app->addAdminMenu('core.admin.menu.modules', '/admin/modules', 'core.settings.manage', 'system');
        $app->addAdminMenu('core.admin.menu.themes', '/admin/themes', 'core.themes.manage', 'system');
        $app->addAdminMenu('core.admin.menu.packages', '/admin/packages', 'core.packages.manage', 'system');
        $app->addAdminMenu('core.admin.menu.tasks', '/admin/tasks', 'core.tasks.view', 'system');
        $app->addAdminMenu('core.admin.menu.updates', '/admin/updates', 'core.update.manage', 'system');
        $app->addAdminMenu('core.admin.docs.title', '/admin/docs', 'core.admin.access', 'system');

        $app->scheduler->register(
            'core.rate-limit-cleanup',
            60,
            fn (App $app) => (new RateLimiter($app->db))->cleanup()
        );
        // Deadlines of orders: what happens when nobody acts in time.
        $app->scheduler->register('core.order-deadlines', 5, fn (App $app) => $app->orders->runDeadlines($app));
        // Expired mail links and "stay logged in" tokens, and registrations
        // whose address was never confirmed within a week.
        $app->scheduler->register('core.account-cleanup', 60, function (App $app): void {
            $app->tokens->purgeExpired();
            $app->loginTokens->purgeExpired();
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
