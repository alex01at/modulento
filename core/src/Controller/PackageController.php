<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Package\Packages;
use Modulento\Core\Support\Migrator;
use Modulento\Core\Support\Session;
use Modulento\Core\Support\UpdateException;

/** Extensions and themes installed from their own repositories. */
final class PackageController extends Controller
{
    /** How long the newest versions found by a check stay on the page. */
    private const SHOW_CHECK_SECONDS = 900;

    public function index(array $params): void
    {
        $app = $this->app;
        $latest = $this->latestVersions(false);
        $installed = $app->packages->installed();

        // Folders that are there without being a package: uploaded by hand,
        // or left from a time when the core brought them along. Installing
        // their repository's release takes them over.
        $known = array_map(fn (array $package) => $package['kind'] . ':' . $package['id'], $installed);
        $owner = strstr((string) ($app->config['update']['repo'] ?? ''), '/', true) ?: 'owner';
        $unmanaged = [];
        foreach (['theme' => $app->themes->siteThemes(), 'extension' => $app->extensions->discover()] as $kind => $found) {
            foreach (array_keys($found) as $id) {
                if (!Packages::isShipped($kind, $id) && !in_array($kind . ':' . $id, $known, true)) {
                    $unmanaged[] = ['kind' => $kind, 'id' => $id, 'repo' => $owner . '/modulento-' . ($kind === 'theme' ? 'theme-' : 'ext-') . $id];
                }
            }
        }

        $this->render('@admin/packages.twig', [
            'packages' => array_map(fn (array $package) => $package + [
                'latest' => $latest[$package['kind'] . ':' . $package['id']] ?? null,
                'in_use' => $this->inUse($package['kind'], $package['id']),
            ], $installed),
            'unmanaged' => $unmanaged,
            'sources' => $app->packages->allowedSources(),
        ]);
    }

    public function install(array $params): void
    {
        $this->installFrom(trim((string) ($_POST['repo'] ?? '')));
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
        $this->installFrom($package['repo']);
    }

    /** Asks every package's repository for its newest version, now. */
    public function check(array $params): void
    {
        $this->latestVersions(true);
        if ($this->app->packages->installed() === []) {
            Session::flash('success', $this->trans('core.package.none_installed'));
        }
        $this->redirect('/admin/packages');
    }

    /**
     * The newest version of every package as the last check found it.
     * Opening the page never asks GitHub by itself; "check" and "update"
     * do. For display only: an update asks the repository again.
     *
     * @return array<string, string> "kind:id" => version
     */
    private function latestVersions(bool $refresh): array
    {
        if (!$refresh) {
            $cached = Session::get('package_versions');

            return is_array($cached) && time() - (int) ($cached['at'] ?? 0) < self::SHOW_CHECK_SECONDS ? $cached['versions'] : [];
        }

        $versions = [];
        foreach ($this->app->packages->installed() as $package) {
            try {
                $versions[$package['kind'] . ':' . $package['id']] = $this->app->packages->latestVersion($package['repo']);
            } catch (UpdateException $e) {
                Session::flash('error', $package['repo'] . ': ' . $this->trans($e->messageKey, $e->params));
            }
        }
        Session::set('package_versions', ['at' => time(), 'versions' => $versions]);

        return $versions;
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

    private function installFrom(string $repo): void
    {
        @set_time_limit(0);

        try {
            $result = $this->app->packages->install($repo);
            Session::remove('package_versions');

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
