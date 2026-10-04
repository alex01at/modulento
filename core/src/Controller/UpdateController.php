<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Migrator;
use Modulento\Core\Support\Session;
use Modulento\Core\Support\UpdateException;
use Modulento\Core\Support\Updater;

final class UpdateController extends Controller
{
    public function index(array $params): void
    {
        $updater = $this->updater();
        $available = Session::get('update_available');
        Session::remove('update_available');

        $this->render('@admin/updates.twig', [
            'enabled' => $updater->isEnabled(),
            'dev_checkout' => $updater->isDevelopmentCheckout(),
            'current_version' => $updater->currentVersion(),
            'stale_lock' => $updater->staleLockInfo(),
            'available' => is_array($available) ? $available : null,
        ]);
    }

    public function check(array $params): void
    {
        try {
            $meta = $this->updater()->checkForUpdate();
            // The dashboard shows this result without asking the release server again.
            $this->app->settings->set('core.update_check', json_encode([
                'version' => $meta['version'] ?? '',
                'checked_at' => gmdate('Y-m-d H:i'),
            ], JSON_THROW_ON_ERROR));
            if ($meta === null) {
                Session::flash('success', $this->trans('core.update.up_to_date'));
            } else {
                // Display only. apply() asks the release server again and
                // never takes a version from the session or the request.
                Session::set('update_available', [
                    'version' => $meta['version'],
                    'published_at' => $meta['published_at'],
                    'changelog' => $meta['changelog'],
                ]);
            }
        } catch (UpdateException $e) {
            Session::flash('error', $this->trans($e->messageKey, $e->params));
        }

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
            $config['update']['repo'],
            $config['update']['token'],
            static function () use ($db, $root): void {
                Migrator::runAll($db, $root);
            }
        );
    }
}
