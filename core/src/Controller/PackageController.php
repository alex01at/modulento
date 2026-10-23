<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Closure;
use Modulento\Core\App;
use Modulento\Core\Package\Packages;
use Modulento\Core\Support\Migrator;
use Modulento\Core\Support\Session;
use Modulento\Core\Support\UpdateChecks;
use Modulento\Core\Support\UpdateException;
use Throwable;

/**
 * Administration -> Extensions and themes: one tile per discovered
 * extension or theme, whether or not it is tracked as a package installed
 * from a repository - what used to be two separate pages ("Erweiterungen"
 * and "Pakete") before an operator ever needed both to decide the same
 * thing: "what do I have, is it on, where did it come from".
 */
final class PackageController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $installedByKey = [];
        foreach ($app->packages->installed() as $package) {
            $installedByKey[$package['kind'] . ':' . $package['id']] = $package;
        }

        $projectOwner = strstr((string) ($app->config['update']['repo'] ?? ''), '/', true) ?: '';
        $owner = $projectOwner ?: 'owner';
        $enabledExtensions = $app->extensions->enabledIds();
        $activeTheme = $app->themes->active();

        $items = [];
        foreach ($app->extensions->discover() as $id => $manifest) {
            $tracked = $installedByKey['extension:' . $id] ?? null;
            $items[] = [
                'kind' => 'extension',
                'id' => $id,
                'name' => $manifest->name,
                'version' => $tracked['version'] ?? $manifest->version,
                'description' => $manifest->description,
                'type' => $manifest->type,
                'api' => $manifest->api,
                'compatible' => $manifest->api === App::API_VERSION,
                'in_use' => in_array($id, $enabledExtensions, true),
                'tracked' => $tracked !== null,
                'shipped' => Packages::isShipped('extension', $id),
                'repo' => $tracked['repo'] ?? ($owner . '/modulento-ext-' . $id),
                'latest' => $tracked !== null ? (new UpdateChecks($app))->latest('extension', $id) : null,
            ];
        }
        foreach ($app->themes->siteThemes() as $id => $theme) {
            $tracked = $installedByKey['theme:' . $id] ?? null;
            $items[] = [
                'kind' => 'theme',
                'id' => $id,
                'name' => $theme['name'],
                'version' => $tracked['version'] ?? $theme['version'],
                'description' => $theme['description'],
                'type' => 'theme',
                'api' => null,
                'compatible' => true,
                'in_use' => $activeTheme === $id,
                'tracked' => $tracked !== null,
                'shipped' => Packages::isShipped('theme', $id),
                'repo' => $tracked['repo'] ?? ($owner . '/modulento-theme-' . $id),
                'latest' => $tracked !== null ? (new UpdateChecks($app))->latest('theme', $id) : null,
            ];
        }

        // The project's own packages that are not here yet. Built from
        // names alone: opening the page must not ask GitHub anything.
        // Left out where installing would be refused anyway.
        $present = array_map(fn (array $item) => $item['kind'] . ':' . $item['id'], $items);
        $official = [];
        foreach (Packages::OFFICIAL as $item) {
            $repo = $projectOwner . '/' . $item['name'];
            if ($projectOwner !== '' && !in_array($item['kind'] . ':' . $item['id'], $present, true) && $app->packages->isAllowed($repo)) {
                $official[] = $item + ['repo' => $repo];
            }
        }

        $this->render('@admin/extensions.twig', [
            'items' => $items,
            'invalid' => $app->extensions->invalid(),
            'missing' => array_values(array_diff($enabledExtensions, array_keys($app->extensions->discover()))),
            'api_version' => App::API_VERSION,
            'official' => $official,
            'sources' => $app->packages->allowedSources(),
            'license_keys' => $app->licenseKeys->all(),
        ]);
    }

    /** Stores (or replaces) the key for a product sold under its own license. */
    public function saveLicense(array $params): void
    {
        $productId = trim((string) ($_POST['product_id'] ?? ''));
        $key = trim((string) ($_POST['license_key'] ?? ''));

        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $productId) !== 1 || $key === '') {
            Session::flash('error', $this->trans('core.license.error.invalid'));
        } else {
            $this->app->licenseKeys->save($productId, $key);
            Session::flash('success', $this->trans('core.license.saved', ['product' => $productId]));
        }

        $this->redirect('/admin/extensions');
    }

    public function removeLicense(array $params): void
    {
        $this->app->licenseKeys->remove($params['product']);
        Session::flash('success', $this->trans('core.license.removed', ['product' => $params['product']]));
        $this->redirect('/admin/extensions');
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
            $this->redirect('/admin/extensions');
            return;
        }
        if (filesize($file['tmp_name']) > Packages::MAX_UPLOAD_BYTES) {
            Session::flash('error', $this->trans('core.package.error.upload_size'));
            $this->redirect('/admin/extensions');
            return;
        }

        $this->runInstall(fn () => $this->app->packages->installUpload($file['tmp_name']));
    }

    public function update(array $params): void
    {
        $package = $this->app->packages->find($params['kind'], $params['id']);
        if ($package === null) {
            $this->redirect('/admin/extensions');
            return;
        }

        try {
            if ($this->app->packages->latestVersion($package['repo']) === $package['version']) {
                Session::flash('success', $this->trans('core.package.is_current', ['id' => $package['id'], 'version' => $package['version']]));
                $this->redirect('/admin/extensions');
                return;
            }
        } catch (UpdateException $e) {
            Session::flash('error', $this->trans($e->messageKey, $e->params));
            $this->redirect('/admin/extensions');
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

        if (!$app->auth->can('core.packages.manage')) {
            $this->respondToSwitch(false, $id, [], $this->trans('core.error.forbidden'));
            return;
        }

        if ($kind === 'extension' && isset($app->extensions->discover()[$id])) {
            try {
                $switchedOff = $on ? $app->extensions->enable($id) : [];
                if (!$on) {
                    $app->extensions->disable($id);
                }
                Session::flash('success', $this->trans($on ? 'core.admin.extensions.enabled' : 'core.admin.extensions.disabled', ['id' => $id]));
                foreach ($switchedOff as $other) {
                    Session::flash('success', $this->trans('core.admin.extensions.switched_off', ['id' => $other]));
                }
                $this->respondToSwitch(true, $id, $switchedOff);
            } catch (Throwable $e) {
                error_log('Enabling extension ' . $id . ' failed: ' . $e);
                $this->respondToSwitch(false, $id, [], $this->trans('core.admin.extensions.enable_failed', ['id' => $id, 'reason' => $e->getMessage()]));
            }

            return;
        }

        if ($kind === 'theme' && isset($app->themes->siteThemes()[$id])) {
            $before = $app->themes->active();
            $target = $on ? $id : 'default';
            if (($on || $before === $id) && $app->themes->activate($target)) {
                // See AdminController::activateTheme()'s own comment: back
                // to whichever home page the newly active theme brings.
                if ($before !== $target) {
                    $app->homeLayout->reset();
                }
                Session::flash('success', $this->trans('core.admin.themes.activated', ['id' => $target]));
                // The one other theme that was active a moment ago, so the
                // caller's tile for it can be switched off too, without a
                // reload - the same single-active-thing update an App
                // switching another App off already needs.
                $switchedOff = $before !== $target && $before !== '' ? [$before] : [];
                $this->respondToSwitch(true, $id, $switchedOff);

                return;
            }
        }

        $this->respondToSwitch(false, $id, []);
    }

    /** @param list<string> $switchedOff */
    private function respondToSwitch(bool $ok, string $id, array $switchedOff, ?string $error = null): void
    {
        if ($this->wantsJson()) {
            $this->json($ok ? ['ok' => true, 'id' => $id, 'switched_off' => $switchedOff] : ['ok' => false, 'error' => $error ?? $this->trans('core.admin.extensions.toggle_failed')]);
            return;
        }

        $this->redirect('/admin/extensions');
    }

    /** Same detection a fetch() call with Accept: application/json (this project's own scripts) or an XMLHttpRequest sets off. */
    private function wantsJson(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    public function remove(array $params): void
    {
        if ($this->inUse($params['kind'], $params['id'])) {
            Session::flash('error', $this->trans('core.package.error.in_use'));
        } else {
            $this->app->packages->remove($params['kind'], $params['id']);
            Session::flash('success', $this->trans('core.package.removed', ['id' => $params['id']]));
        }

        $this->redirect('/admin/extensions');
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

        $this->redirect('/admin/extensions');
    }

    /** An enabled extension or the active theme cannot be removed. */
    private function inUse(string $kind, string $id): bool
    {
        return $kind === 'extension'
            ? in_array($id, $this->app->extensions->enabledIds(), true)
            : $this->app->themes->active() === $id;
    }
}
