<?php

declare(strict_types=1);

namespace Modulento\Example;

use Modulento\Core\App;
use Modulento\Core\Event\AccountExport;
use Modulento\Core\Event\AccountLoggedIn;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Router;

/**
 * Reference extension: uses every registration point once, so it doubles as
 * the template for a new extension and as the end-to-end check that the
 * interface works. It records logins in its own table and shows them in
 * the administration.
 */
final class Extension implements ExtensionContract
{
    public function register(Registrar $registrar): void
    {
        $registrar->permission('example.logins.view', 'example.permission.logins_view');

        $registrar->routes(function (Router $router): void {
            $router->get('/example', [ExampleController::class, 'index'], Router::PUBLIC);
            $router->get('/admin/example', [ExampleController::class, 'logins'], 'example.logins.view');
        });

        $registrar->adminMenu('example.admin.menu', '/admin/example', 'example.logins.view');

        $registrar->listen(AccountLoggedIn::class, function (AccountLoggedIn $event, App $app): void {
            $stmt = $app->db->prepare('INSERT INTO x_example_login (account_id, logged_in_at) VALUES (:id, :now)');
            $stmt->execute(['id' => $event->accountId, 'now' => Clock::now()]);
        });

        $registrar->listen(AccountExport::class, function (AccountExport $event, App $app): void {
            $stmt = $app->db->prepare('SELECT logged_in_at FROM x_example_login WHERE account_id = :id ORDER BY id');
            $stmt->execute(['id' => $event->accountId]);
            $event->add('example', ['logins' => $stmt->fetchAll(\PDO::FETCH_COLUMN)]);
        });

        $registrar->task('example.prune-logins', 1440, function (App $app): void {
            $stmt = $app->db->prepare('DELETE FROM x_example_login WHERE logged_in_at < :before');
            $stmt->execute(['before' => Clock::now(-30 * 86400)]);
        });
    }
}
