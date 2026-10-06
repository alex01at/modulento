<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Closure;
use Modulento\Core\Package\Packages;
use Modulento\Core\Support\Migrator;
use Modulento\Core\Support\Session;
use Modulento\Core\Support\UpdateChecks;
use Modulento\Core\Support\UpdateException;
use Throwable;

/** Extensions and themes installed from their own repositories. */
final class PackageController extends Controller
{
    /** How long the newest versions found by a check stay on the page. */

    public function index(array $params): void
    {
        $app = $this->app;
        $installed = $app->packages->installed();

        // Folders that are there without being a package: uploaded by hand,
        // or left from a time when the core brought them along. Installing
        // their repository's release takes them over.
        $known = array_map(fn (array $package) => $package['kind'] . ':' . $package['id'], $installed);
        $projectOwner = strstr((string) ($app->config['update']['repo'] ?? ''), '/', true) ?: '';
        $owner = $projectOwner ?: 'owner';
        $present = $known;
        $unmanaged = [];
        foreach (['theme' => $app->themes->siteThemes(), 'extension' => $app->extensions->discover()] as $kind => $found) {
            foreach (array_keys($found) as $id) {
                $present[] = $kind . ':' . $id;
                if (!Packages::isShipped($kind, $id) && !in_array($kind . ':' . $id, $known, true)) {
                    $unmanaged[] = ['kind' => $kind, 'id' => $id, 'in_use' => $this->inUse($kind, $id), 'repo' => $owner . '/modulento-' . ($kind === 'theme' ? 'theme-' : 'ext-') . $id];
                }
            }
        }

        // The project's own packages that are not here yet. Built from
        // names alone: opening the page must not ask GitHub anything.
        // Left out where installing would be refused anyway.
        $official = [];
        foreach (Packages::OFFICIAL as $item) {
            $repo = $projectOwner . '/' . $item['name'];
            if ($projectOwner !== '' && !in_array($item['kind'] . ':' . $item['id'], $present, true) && $app->packages->isAllowed($repo)) {
                $official[] = $item + ['repo' => $repo];
            }
        }

        $this->render('@admin/packages.twig', [
            'packages' => array_map(fn (array $package) => $package + [
                'latest' => (new UpdateChecks($app))->latest($package['kind'], $package['id']),
                'in_use' => $this->inUse($package['kind'], $package['id']),
            ], $installed),
            'unmanaged' => $unmanaged,
            'official' => $official,
            'sources' => $app->packages->allowedSources(),
        ]);
    }

    public function install(array $params): void
    {
        // A name such as owner/name, or the address of the repository on GitHub.
        $repo = Packages::repoFromInput((string) ($_POST['repo'] ?? ''));
        $this->runInstall(fn () => $this->app->packages->install($repo));
    }

    /** A zip file the administrator chose, for packages that are not on GitHub. */
    public function upload(array $params): void
    {
        $file = is_array($_FILES['package'] ?? null) ? $_FILES['package'] : [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($file['tmp_name'] ?? '')) {
            Session::flash('error', $this->trans('core.package.error.upload'));
            $this->redirect('/admin/packages');
            return;
        }
        if (filesize($file['tmp_name']) > Packages::MAX_UPLOAD_BYTES) {
            Session::flash('error', $this->trans('core.package.error.upload_size'));
            $this->redirect('/admin/packages');
            return;
        }

        $this->runInstall(fn () => $this->app->packages->installUpload($file['tmp_name']));
    }

    public function update(array $params): void
    {
        $package = $this->app->packages->find($params['kind'], $params['id']);
        if ($package === null) {
            $this->redirect('/admin/packages');
            return;
        }

        try {
            if ($this->app->packages->latestVersion($package['repo']) === $package['version']) {
                Session::flash('success', $this->trans('core.package.is_current', ['id' => $package['id'], 'version' => $package['version']]));
                $this->redirect('/admin/packages');
                return;
            }
        } catch (UpdateException $e) {
            Session::flash('error', $this->trans($e->messageKey, $e->params));
            $this->redirect('/admin/packages');
            return;
        }

        // From the repository it was installed from, never one a request names.
        $repo = $package['repo'];
        $this->runInstall(fn () => $this->app->packages->install($repo));
    }

    /** Switches an extension on, or makes a theme the site's theme, without leaving this page. */
    public function enable(array $params): void
    {
        $this->switch($params['kind'], $params['id'], true);
    }

    /** Switches an extension off; for the active theme, back to the default theme. */
    public function disable(array $params): void
    {
        $this->switch($params['kind'], $params['id'], false);
    }

    private function switch(string $kind, string $id, bool $on): void
    {
        $app = $this->app;
        // The same right as on the page this action belongs to.
        $allowed = $kind === 'extension' ? $app->auth->can('core.extensions.manage') : $app->auth->can('core.themes.manage');

        if (!$allowed) {
            Session::flash('error', $this->trans('core.error.forbidden'));
        } elseif ($kind === 'extension' && isset($app->extensions->discover()[$id])) {
            try {
                $switchedOff = $on ? $app->extensions->enable($id) : [];
                if (!$on) {
                    $app->extensions->disable($id);
                }
                Session::flash('success', $this->trans($on ? 'core.admin.extensions.enabled' : 'core.admin.extensions.disabled', ['id' => $id]));
                foreach ($switchedOff as $other) {
                    Session::flash('success', $this->trans('core.admin.extensions.switched_off', ['id' => $other]));
                }
            } catch (Throwable $e) {
                error_log('Enabling extension ' . $id . ' failed: ' . $e);
                Session::flash('error', $this->trans('core.admin.extensions.enable_failed', ['id' => $id, 'reason' => $e->getMessage()]));
            }
        } elseif ($kind === 'theme' && isset($app->themes->siteThemes()[$id])) {
            $target = $on ? $id : 'default';
            if (($on || $app->themes->active() === $id) && $app->themes->activate($target)) {
                Session::flash('success', $this->trans('core.admin.themes.activated', ['id' => $target]));
            }
        }

        $this->redirect('/admin/packages');
    }

    public function remove(array $params): void
    {
        if ($this->inUse($params['kind'], $params['id'])) {
            Session::flash('error', $this->trans('core.package.error.in_use'));
        } else {
            $this->app->packages->remove($params['kind'], $params['id']);
            Session::flash('success', $this->trans('core.package.removed', ['id' => $params['id']]));
        }

        $this->redirect('/admin/packages');
    }

    /** @param Closure(): array{kind: string, id: string, version: string, updated: bool} $install */
    private function runInstall(Closure $install): void
    {
        @set_time_limit(0);

        try {
            $result = $install();

            // An enabled extension's new migrations run right away.
            if ($result['kind'] === 'extension') {
                Migrator::runAll($this->app->db, $this->app->config['app']['root']);
            }

            Session::flash('success', $this->trans(
                $result['updated'] ? 'core.package.updated' : 'core.package.installed.' . $result['kind'],
                ['id' => $result['id'], 'version' => $result['version']]
            ));
        } catch (UpdateException $e) {
            Session::flash('error', $this->trans($e->messageKey, $e->params));
        }

        $this->redirect('/admin/packages');
    }

    /** An enabled extension or the active theme cannot be removed. */
    private function inUse(string $kind, string $id): bool
    {
        return $kind === 'extension'
            ? in_array($id, $this->app->extensions->enabledIds(), true)
            : $this->app->themes->active() === $id;
    }
}
