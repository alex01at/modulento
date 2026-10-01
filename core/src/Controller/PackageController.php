<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Migrator;
use Modulento\Core\Support\Session;
use Modulento\Core\Support\UpdateException;

/** Extensions and themes installed from their own repositories. */
final class PackageController extends Controller
{
    public function index(array $params): void
    {
        $latest = Session::get('package_versions');
        Session::remove('package_versions');
        $latest = is_array($latest) ? $latest : [];

        $this->render('@admin/packages.twig', [
            'packages' => array_map(fn (array $package) => $package + [
                'latest' => $latest[$package['kind'] . ':' . $package['id']] ?? null,
                'in_use' => $this->inUse($package['kind'], $package['id']),
            ], $this->app->packages->installed()),
            'sources' => $this->app->packages->allowedSources(),
            'checked' => $latest !== [],
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

        // From the repository it was installed from, never one a request names.
        $this->installFrom($package['repo']);
    }

    /** Asks every package's repository for its newest version. */
    public function check(array $params): void
    {
        $versions = [];
        foreach ($this->app->packages->installed() as $package) {
            try {
                $versions[$package['kind'] . ':' . $package['id']] = $this->app->packages->latestVersion($package['repo']);
            } catch (UpdateException $e) {
                Session::flash('error', $package['repo'] . ': ' . $this->trans($e->messageKey, $e->params));
            }
        }

        // Display only; an update asks the repository again.
        Session::set('package_versions', $versions);
        if ($versions === []) {
            Session::flash('success', $this->trans('core.package.none_installed'));
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

    private function installFrom(string $repo): void
    {
        @set_time_limit(0);

        try {
            $result = $this->app->packages->install($repo);

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
