<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Branding;
use Modulento\Core\Support\Session;
use Modulento\Core\Support\UpdateChecks;
use PDO;

final class AdminController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $stats = $this->stats();

        $this->render('@admin/index.twig', [
            'update' => $this->updateStatus(),
            'action_queue' => array_values(array_filter($stats, fn (array $stat) => $stat['pending'] !== null && $stat['pending'] > 0)),
            'recent_orders' => $app->auth->can('core.orders.manage')
                ? array_map(
                    fn (array $order) => $order + ['path' => '/admin/orders/' . $order['id']],
                    $app->orders->list('admin', null, null, 1, 5)['rows']
                )
                : [],
            'quick_actions' => array_values(array_filter([
                ['label_key' => 'core.admin.dashboard.quick.page', 'path' => '/admin/pages/new', 'permission' => 'core.pages.manage'],
                ['label_key' => 'core.admin.dashboard.quick.account', 'path' => '/admin/accounts/new', 'permission' => 'core.accounts.manage'],
                ['label_key' => 'core.admin.dashboard.quick.media', 'path' => '/admin/media', 'permission' => 'core.media.manage'],
                ['label_key' => 'core.admin.dashboard.quick.settings', 'path' => '/admin/settings', 'permission' => 'core.settings.manage'],
            ], fn (array $action) => $app->auth->can($action['permission']))),
            'setup_steps' => $this->setupSteps(),
            'system_health' => $this->systemHealth(),
        ]);
    }

    /**
     * A short, ordered first-run checklist (design, home page, pages, menu) -
     * shown only until every step the account may do is actually done, so
     * it guides a fresh install without lingering once the site is set up.
     *
     * @return list<array{label_key: string, path: string, done: bool}>
     */
    private function setupSteps(): array
    {
        $app = $this->app;
        $steps = array_values(array_filter([
            ['label_key' => 'core.admin.dashboard.setup.design', 'path' => '/admin/design', 'permission' => 'core.settings.manage', 'done' => $app->design->isCustomized()],
            ['label_key' => 'core.admin.dashboard.setup.home', 'path' => '/admin/home', 'permission' => 'core.settings.manage', 'done' => $app->homeLayout->isCustomized()],
            ['label_key' => 'core.admin.dashboard.setup.pages', 'path' => '/admin/pages', 'permission' => 'core.pages.manage', 'done' => $app->pages->all() !== []],
            ['label_key' => 'core.admin.dashboard.setup.menu', 'path' => '/admin/menu', 'permission' => 'core.settings.manage', 'done' => $app->customMenu->isCustomized()],
        ], fn (array $step) => $app->auth->can($step['permission'])));

        return array_filter($steps, fn (array $step) => !$step['done']) !== [] ? $steps : [];
    }

    /** @return array{version: string, account_count: int, apps: list<string>, addons: list<string>, cron_last: ?string} */
    private function systemHealth(): array
    {
        $app = $this->app;
        $discovered = $app->extensions->discover();
        $apps = [];
        $addons = [];
        foreach ($app->extensions->enabledIds() as $id) {
            if (($discovered[$id]->type ?? 'app') === 'addon') {
                $addons[] = $id;
            } else {
                $apps[] = $id;
            }
        }

        return [
            'version' => $app->coreVersion(),
            'account_count' => $app->accounts->list('', 1, 1)['total'],
            'apps' => $apps,
            'addons' => $addons,
            'cron_last' => $app->db->query('SELECT MAX(last_finished_at) FROM task_run')->fetchColumn() ?: null,
        ];
    }

    public function modules(array $params): void
    {
        $modules = $this->app->modules->all();
        $this->render('@admin/modules.twig', ['modules' => array_map(
            fn (string $id, string $key) => ['id' => $id, 'key' => $key, 'enabled' => $this->app->modules->enabled($id)],
            array_keys($modules),
            $modules
        )]);
    }

    public function saveModules(array $params): void
    {
        $chosen = is_array($_POST['modules'] ?? null) ? array_filter($_POST['modules'], 'is_string') : [];
        $this->app->modules->save(array_values(array_intersect(array_keys($this->app->modules->all()), $chosen)));
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

    /**
     * How many components have a newer release than the one installed, from
     * the last check (see UpdateChecks). Null without the permission.
     *
     * @return array{count: int, names: list<string>, checked_at: ?string, enabled: bool}|null
     */
    private function updateStatus(): ?array
    {
        if (!$this->app->auth->can('core.update.manage')) {
            return null;
        }

        $enabled = ($this->app->config['update']['repo'] ?? '') !== '';
        $checks = new UpdateChecks($this->app);
        if ($enabled && $checks->isStale()) {
            // Best-effort and silent: the dashboard must never hang on a
            // slow or unreachable release server, and a transient failure
            // here is not worth a flash message on a page nobody asked to
            // check anything on. The manual check on /admin/updates still
            // reports its own problems the normal way.
            $checks->refresh();
        }
        $available = $checks->available();

        return [
            'count' => count($available),
            'names' => array_map(fn (array $component) => ($component['kind'] === 'core' ? 'Modulento' : $component['id']) . ' ' . $component['latest'], $available),
            'checked_at' => $checks->checkedAt(),
            'enabled' => $enabled,
        ];
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
        if ($app->modules->enabled('requests') && $app->auth->can('core.requests.manage')) {
            $counts = $app->requests->counts();
            $stats[] = ['id' => 'requests', 'label_key' => 'core.admin.menu.requests', 'path' => '/admin/requests',
                'total' => array_sum($counts), 'pending' => $counts['pending'] ?? 0];
        }
        if ($app->auth->can('core.orders.manage')) {
            $stats[] = ['id' => 'orders', 'label_key' => 'core.admin.menu.orders', 'path' => '/admin/orders',
                'total' => array_sum($app->orders->counts()), 'pending' => null];
        }
        if ($app->auth->can('core.messages.manage')) {
            $stats[] = ['id' => 'messages', 'label_key' => 'core.admin.menu.messages', 'path' => '/admin/messages',
                'total' => $app->orders->messageCount() + $app->offerMessages->messageCount(),
                'pending' => $app->orders->flaggedMessageCount() + $app->offerMessages->flaggedCount()];
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
        $before = $this->app->themes->active();
        if ($this->app->themes->activate($params['id'])) {
            // Back to whichever home page the new theme brings (its own
            // home-layout.json, or the core's own default) - an operator
            // switching themes almost always wants the new theme's own
            // home page, not a leftover customization built for the
            // previous one. A page never actually hand-edited was already
            // showing the new theme's content on its own either way (see
            // HomeLayout::isCustomized()); this only matters once it was.
            if ($before !== $params['id']) {
                $this->app->homeLayout->reset();
            }
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
