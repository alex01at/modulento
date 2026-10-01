<?php

declare(strict_types=1);

namespace Modulento\Core;

use Modulento\Core\Controller\AdminController;
use Modulento\Core\Controller\AuthController;
use Modulento\Core\Controller\HomeController;
use Modulento\Core\Controller\UpdateController;
use Modulento\Core\Support\Database;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Router;
use Modulento\Core\Support\Session;
use Modulento\Core\Support\Translator;

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

        $locale = 'en';
        if ($web) {
            Session::start();
            $locale = Session::get('locale');
            if (!in_array($locale, Translator::SUPPORTED_LOCALES, true)) {
                $locale = Translator::detectLocale($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
                Session::set('locale', $locale);
            }
        }

        $app = new App($config, $db, $locale);
        $app->translator->load($root . '/core/lang', 'core');

        // Core routes are registered first: the first match wins, so an
        // extension can never take over /login or /admin.
        self::registerCore($app);
        $app->extensions->loadEnabled($app);

        return $app;
    }

    private static function registerCore(App $app): void
    {
        $router = $app->router;

        $router->get('/', [HomeController::class, 'index'], Router::PUBLIC);
        $router->post('/locale', [HomeController::class, 'switchLocale'], Router::PUBLIC);

        $router->get('/login', [AuthController::class, 'showLogin'], Router::PUBLIC);
        $router->post('/login', [AuthController::class, 'login'], Router::PUBLIC);
        $router->post('/logout', [AuthController::class, 'logout']);

        $router->get('/admin', [AdminController::class, 'index'], 'core.admin.access');
        $router->get('/admin/extensions', [AdminController::class, 'extensions'], 'core.extensions.manage');
        $router->post('/admin/extensions/{id}/enable', [AdminController::class, 'enableExtension'], 'core.extensions.manage');
        $router->post('/admin/extensions/{id}/disable', [AdminController::class, 'disableExtension'], 'core.extensions.manage');
        $router->get('/admin/tasks', [AdminController::class, 'tasks'], 'core.tasks.view');

        $router->get('/admin/updates', [UpdateController::class, 'index'], 'core.update.manage');
        $router->post('/admin/updates/check', [UpdateController::class, 'check'], 'core.update.manage');
        $router->post('/admin/updates/apply', [UpdateController::class, 'apply'], 'core.update.manage');

        $app->addPermission('core.admin.access', 'core.permission.admin_access');
        $app->addPermission('core.extensions.manage', 'core.permission.extensions_manage');
        $app->addPermission('core.tasks.view', 'core.permission.tasks_view');

        $app->addPermission('core.update.manage', 'core.permission.update_manage');

        $app->addAdminMenu('core.admin.menu.extensions', '/admin/extensions', 'core.extensions.manage');
        $app->addAdminMenu('core.admin.menu.tasks', '/admin/tasks', 'core.tasks.view');
        $app->addAdminMenu('core.admin.menu.updates', '/admin/updates', 'core.update.manage');

        $app->scheduler->register(
            'core.rate-limit-cleanup',
            60,
            fn (App $app) => (new RateLimiter($app->db))->cleanup()
        );
    }
}
