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
        $canPackages = $this->app->auth->can('core.packages.manage');
        $available = $checks->available();

        $this->render('@admin/updates.twig', [
            'enabled' => $updater->isEnabled(),
            'dev_checkout' => $updater->isDevelopmentCheckout(),
            'current_version' => $updater->currentVersion(),
            'stale_lock' => $updater->staleLockInfo(),
            'components' => $checks->components(),
            'checked_at' => $checks->checkedAt(),
            'can_packages' => $canPackages,
            'available_count' => count($available),
            'actionable_count' => count($this->actionable($available, $updater, $canPackages)),
        ]);
    }

    /**
     * Asks every component's release server once; the answers are kept for
     * the dashboard and this page. Also reachable from the dashboard's own
     * "System & Status" card, which the 24-hour cache (UpdateChecks::isStale())
     * would otherwise leave showing a stale answer right after a fresh
     * release - that form sends "return=/admin" to come straight back to it.
     */
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

        $this->redirect($this->safeReturn('/admin/updates'));
    }

    public function apply(array $params): void
    {
        $result = $this->updater()->applyUpdate();

        Session::flash($result['success'] ? 'success' : 'error', $this->trans($result['message_key'], $result['params']));
        $this->redirect('/admin/updates');
    }

    /**
     * Every component with a newer release, one after another: the core
     * through its own backup-and-migrate path, each package through
     * Packages::install() - a package that fails (refused source, a
     * checksum that no longer matches, …) does not stop the rest; its
     * message is reported alongside whatever did succeed.
     */
    public function applyAll(array $params): void
    {
        @set_time_limit(0);

        $checks = new UpdateChecks($this->app);
        $updater = $this->updater();
        $canPackages = $this->app->auth->can('core.packages.manage');
        $updated = 0;
        $errors = [];

        foreach ($checks->available() as $component) {
            if ($component['kind'] === 'core') {
                if (!$updater->isEnabled() || $updater->isDevelopmentCheckout()) {
                    continue;
                }
                $result = $updater->applyUpdate();
                if ($result['success']) {
                    $updated++;
                } elseif ($result['stage'] !== 'up_to_date') {
                    $errors[] = $this->trans('core.update.core') . ': ' . $this->trans($result['message_key'], $result['params']);
                }
                continue;
            }

            if (!$canPackages) {
                continue;
            }

            try {
                $this->app->packages->install($component['repo']);
                $updated++;
            } catch (UpdateException $e) {
                $errors[] = $component['id'] . ': ' . $this->trans($e->messageKey, $e->params);
            }
        }

        // A package's migrations, if any - the core already ran its own above.
        if ($updated > 0) {
            Migrator::runAll($this->app->db, $this->app->config['app']['root']);
        }

        foreach ($errors as $error) {
            Session::flash('error', $error);
        }
        if ($updated > 0) {
            Session::flash('success', $this->trans('core.update.applied_all', ['count' => $updated]));
        } elseif ($errors === []) {
            Session::flash('success', $this->trans('core.update.nothing_to_apply'));
        }

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

    /**
     * Of the components with a newer release, the ones "Update all" would
     * actually touch - the core only with updates enabled and not a git
     * checkout, a package only with the separate permission for it.
     *
     * @param list<array{kind: string, id: string, installed: string, latest: ?string, newer: bool, action: string, repo: string}> $available
     * @return list<array{kind: string, id: string, installed: string, latest: ?string, newer: bool, action: string, repo: string}>
     */
    private function actionable(array $available, Updater $updater, bool $canPackages): array
    {
        return array_values(array_filter($available, static fn (array $component) => $component['kind'] === 'core'
            ? ($updater->isEnabled() && !$updater->isDevelopmentCheckout())
            : $canPackages));
    }

    private function updater(): Updater
    {
        $config = $this->app->config;
        $db = $this->app->db;
        $root = $config['app']['root'];

        return new Updater(
            $root,
            $config['update']['repo'] ?? '',
            $this->app->releaseSource,
            static function () use ($db, $root): void {
                Migrator::runAll($db, $root);
            }
        );
    }
}
