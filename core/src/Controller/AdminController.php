<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\App;
use Modulento\Core\Support\Session;
use PDO;
use Throwable;

final class AdminController extends Controller
{
    public function index(array $params): void
    {
        $this->render('@admin/index.twig');
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
            'cron_script' => $config['root'] . '/bin/cron.php',
            'cron_url' => $config['cron_token'] !== '' ? $config['url'] . '/cron/' . $config['cron_token'] : null,
        ]);
    }

    public function themes(array $params): void
    {
        $this->render('@admin/themes.twig', [
            'themes' => array_values($this->app->themes->siteThemes()),
            'active' => $this->app->themes->active(),
        ]);
    }

    public function activateTheme(array $params): void
    {
        if ($this->app->themes->activate($params['id'])) {
            Session::flash('success', $this->trans('core.admin.themes.activated', ['id' => $params['id']]));
        }

        $this->redirect('/admin/themes');
    }
}
