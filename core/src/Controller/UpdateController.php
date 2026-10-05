<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Migrator;
use Modulento\Core\Support\Session;
use Modulento\Core\Support\UpdateChecks;
use Modulento\Core\Support\UpdateException;
use Modulento\Core\Support\Updater;

final class UpdateController extends Controller
{
    /** Every component in one list: the core, its extensions and themes. */
    public function index(array $params): void
    {
        $updater = $this->updater();
        $checks = new UpdateChecks($this->app);

        $this->render('@admin/updates.twig', [
            'enabled' => $updater->isEnabled(),
            'dev_checkout' => $updater->isDevelopmentCheckout(),
            'current_version' => $updater->currentVersion(),
            'stale_lock' => $updater->staleLockInfo(),
            'components' => $checks->components(),
            'checked_at' => $checks->checkedAt(),
            'can_packages' => $this->app->auth->can('core.packages.manage'),
        ]);
    }

    /** Asks every component's release server once; the answers are kept for the dashboard and this page. */
    public function check(array $params): void
    {
        $checks = new UpdateChecks($this->app);
        foreach ($checks->refresh() as $problem) {
            Session::flash('error', $problem);
        }
        $count = count($checks->available());
        Session::flash('success', $count > 0
            ? $this->trans('core.update.checked_some', ['count' => $count])
            : $this->trans('core.update.checked_none'));

        $this->redirect('/admin/updates');
    }

    public function apply(array $params): void
    {
        $result = $this->updater()->applyUpdate();

        Session::flash($result['success'] ? 'success' : 'error', $this->trans($result['message_key'], $result['params']));
        $this->redirect('/admin/updates');
    }

    /**
     * For changes that did not come through an update: an extension that
     * was replaced by a newer copy over FTP brings new migration files
     * that nothing else would run without shell access.
     */
    public function migrate(array $params): void
    {
        $applied = Migrator::runAll($this->app->db, $this->app->config['app']['root']);
        $count = array_sum(array_map('count', $applied));

        Session::flash('success', $this->trans($count > 0 ? 'core.update.migrations_applied' : 'core.update.migrations_none', ['count' => $count]));
        $this->redirect('/admin/updates');
    }

    private function updater(): Updater
    {
        $config = $this->app->config;
        $db = $this->app->db;
        $root = $config['app']['root'];

        return new Updater(
            $root,
            $config['update']['repo'] ?? '',
            $config['update']['token'] ?? '',
            static function () use ($db, $root): void {
                Migrator::runAll($db, $root);
            }
        );
    }
}
