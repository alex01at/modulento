<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\App;
use Modulento\Core\Support\Branding;
use Modulento\Core\Support\Modules;
use Modulento\Core\Support\Session;
use Modulento\Core\Support\Updater;
use PDO;
use Throwable;

final class AdminController extends Controller
{
    public function index(array $params): void
    {
        $this->render('@admin/index.twig', ['stats' => $this->stats(), 'update' => $this->updateStatus()]);
    }

    /**
     * The installed version and what the last update check found. The check
     * itself asks the release server, so it only runs on the Updates page.
     * Null without the permission or without an update repository.
     *
     * @return array{current: string, available: ?string, checked_at: ?string}|null
     */
    private function updateStatus(): ?array
    {
        $config = $this->app->config;
        if (!$this->app->auth->can('core.update.manage') || ($config['update']['repo'] ?? '') === '') {
            return null;
        }

        $check = json_decode($this->app->settings->get('core.update_check'), true);
        $check = is_array($check) ? $check : [];
        $current = Updater::installedVersion($config['app']['root']);
        $found = (string) ($check['version'] ?? '');

        return [
            'current' => $current,
            'available' => $found !== '' && version_compare($found, $current, '>') ? $found : null,
            'checked_at' => $check['checked_at'] ?? null,
        ];
    }

    public function modules(array $params): void
    {
        $this->render('@admin/modules.twig', ['modules' => array_map(
            fn (string $id, string $key) => ['id' => $id, 'key' => $key, 'enabled' => $this->app->modules->enabled($id)],
            array_keys(Modules::ALL),
            Modules::ALL
        )]);
    }

    public function saveModules(array $params): void
    {
        $chosen = is_array($_POST['modules'] ?? null) ? array_filter($_POST['modules'], 'is_string') : [];
        $this->app->modules->save(array_values(array_intersect(array_keys(Modules::ALL), $chosen)));
        Session::flash('success', $this->trans('core.admin.modules.saved'));
        $this->redirect('/admin/modules');
    }

    /**
     * One section of the menu as tiles: where the top-level entries of the
     * header layout lead. A section the account sees nothing of does not
     * exist for it.
     */
    public function section(array $params): void
    {
        $items = array_values(array_filter(
            $this->app->adminMenu(),
            fn (array $item) => $item['group'] === $params['id'] && $this->app->auth->can($item['permission'])
        ));

        if ($items === []) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $this->render('@admin/section.twig', [
            'group' => ['id' => $params['id'], 'label_key' => 'core.admin.group.' . $params['id']],
            'items' => $items,
        ]);
    }

    /** How themes and extensions are built, for whoever administers the site. */
    public function docs(array $params): void
    {
        $this->render('@admin/docs.twig');
    }

    /**
     * Figures for the dashboard, one entry per area the account may manage.
     * "pending" is null where nothing waits for a decision.
     *
     * @return array<int, array{id: string, label_key: string, path: string, total: int, pending: ?int}>
     */
    private function stats(): array
    {
        $app = $this->app;
        $stats = [];

        if ($app->auth->can('core.providers.manage')) {
            $counts = $app->providers->counts();
            $stats[] = ['id' => 'providers', 'label_key' => 'core.admin.menu.providers', 'path' => '/admin/providers',
                'total' => array_sum($counts), 'pending' => $counts['pending'] ?? 0];
        }
        if ($app->auth->can('core.offers.manage')) {
            $counts = $app->offers->counts();
            $stats[] = ['id' => 'offers', 'label_key' => 'core.admin.menu.offers', 'path' => '/admin/offers',
                'total' => array_sum($counts), 'pending' => $counts['pending'] ?? 0];
        }
        if ($app->auth->can('core.orders.manage')) {
            $stats[] = ['id' => 'orders', 'label_key' => 'core.admin.menu.orders', 'path' => '/admin/orders',
                'total' => array_sum($app->orders->counts()), 'pending' => null];
        }
        if ($app->auth->can('core.media.manage')) {
            $stats[] = ['id' => 'media', 'label_key' => 'core.admin.menu.media', 'path' => '/admin/media',
                'total' => $app->media->list(1, 1)['total'], 'pending' => null];
        }
        if ($app->auth->can('core.accounts.manage')) {
            $stats[] = ['id' => 'accounts', 'label_key' => 'core.admin.menu.accounts', 'path' => '/admin/accounts',
                'total' => $app->accounts->list('', 1, 1)['total'], 'pending' => null];
        }
        if ($app->modules->enabled('reviews') && $app->auth->can('core.reviews.manage')) {
            $stats[] = ['id' => 'reviews', 'label_key' => 'core.admin.menu.reviews', 'path' => '/admin/reviews',
                'total' => $app->reviews->listAll(null, 1, 1)['total'], 'pending' => null];
        }
        if ($app->modules->enabled('reports') && $app->auth->can('core.reports.manage')) {
            $stats[] = ['id' => 'reports', 'label_key' => 'core.admin.menu.reports', 'path' => '/admin/reports',
                'total' => $app->reports->list(1, 1)['total'], 'pending' => $app->reports->openCount()];
        }

        return $stats;
    }

    public function extensions(array $params): void
    {
        $manager = $this->app->extensions;
        $enabled = $manager->enabledIds();
        $rows = [];

        foreach ($manager->discover() as $id => $manifest) {
            $rows[] = [
                'id' => $id,
                'name' => $manifest->name,
                'version' => $manifest->version,
                'api' => $manifest->api,
                'compatible' => $manifest->api === App::API_VERSION,
                'enabled' => in_array($id, $enabled, true),
            ];
        }

        $this->render('@admin/extensions.twig', [
            'extensions' => $rows,
            'invalid' => $manager->invalid(),
            'missing' => array_values(array_diff($enabled, array_keys($manager->discover()))),
            'api_version' => App::API_VERSION,
        ]);
    }

    public function enableExtension(array $params): void
    {
        try {
            $this->app->extensions->enable($params['id']);
            Session::flash('success', $this->trans('core.admin.extensions.enabled', ['id' => $params['id']]));
        } catch (Throwable $e) {
            error_log('Enabling extension ' . $params['id'] . ' failed: ' . $e);
            Session::flash('error', $this->trans('core.admin.extensions.enable_failed', ['id' => $params['id'], 'reason' => $e->getMessage()]));
        }

        $this->redirect('/admin/extensions');
    }

    public function disableExtension(array $params): void
    {
        $this->app->extensions->disable($params['id']);
        Session::flash('success', $this->trans('core.admin.extensions.disabled', ['id' => $params['id']]));
        $this->redirect('/admin/extensions');
    }

    public function tasks(array $params): void
    {
        $runs = $this->app->db
            ->query('SELECT name, last_started_at, last_finished_at, last_status, last_error FROM task_run')
            ->fetchAll(PDO::FETCH_UNIQUE);

        $tasks = [];
        foreach ($this->app->scheduler->taskNames() as $name) {
            $tasks[] = ['name' => $name] + ($runs[$name] ?? [
                'last_started_at' => null, 'last_finished_at' => null, 'last_status' => null, 'last_error' => null,
            ]);
        }

        $config = $this->app->config['app'];

        $this->render('@admin/tasks.twig', [
            'tasks' => $tasks,
            // The script is not executable by itself (an upload does not keep
            // that flag), it has to be handed to PHP. PHP_BINDIR is where
            // the PHP version serving this page keeps its command line
            // binary, which avoids an older default "php" on the host.
            'cron_command' => PHP_BINDIR . '/php ' . $config['root'] . '/bin/cron.php',
            'cron_url' => $config['cron_token'] !== '' ? $config['url'] . '/cron/' . $config['cron_token'] : null,
        ]);
    }

    public function themes(array $params): void
    {
        $this->render('@admin/themes.twig', [
            'themes' => array_values($this->app->themes->siteThemes()),
            'active' => $this->app->themes->active(),
            // Each kind's own file, without site_logo()'s fallback to the
            // light logo: here it matters whether one was uploaded for it.
            'branding' => array_combine(Branding::KINDS, array_map(
                fn (string $kind) => $this->app->branding->url($kind),
                Branding::KINDS
            )),
        ]);
    }

    public function activateTheme(array $params): void
    {
        if ($this->app->themes->activate($params['id'])) {
            Session::flash('success', $this->trans('core.admin.themes.activated', ['id' => $params['id']]));
        }

        $this->redirect('/admin/themes');
    }

    /** A logo or favicon of the site's own, in place of the default look. */
    public function saveBranding(array $params): void
    {
        $kind = (string) $params['kind'];
        if (!in_array($kind, Branding::KINDS, true)) {
            $this->redirect('/admin/themes');
            return;
        }

        $problem = $this->app->branding->set($kind, is_array($_FILES['file'] ?? null) ? $_FILES['file'] : []);
        Session::flash($problem === null ? 'success' : 'error', $this->trans($problem ?? 'core.admin.branding.saved', [
            'megabytes' => intdiv(Branding::MAX_BYTES, 1024 * 1024),
        ]));
        $this->redirect('/admin/themes');
    }

    public function deleteBranding(array $params): void
    {
        $kind = (string) $params['kind'];
        if (in_array($kind, Branding::KINDS, true)) {
            $this->app->branding->delete($kind);
            Session::flash('success', $this->trans('core.admin.branding.deleted'));
        }

        $this->redirect('/admin/themes');
    }
}
