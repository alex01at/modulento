<?php

declare(strict_types=1);

namespace Modulento\Core\Controller\Api;

use Modulento\Core\App;
use Modulento\Core\Extension\Manifest;

/**
 * Everything an app needs to know to build its screens for the logged-in
 * account, in one call: what the account may do, what this installation
 * has switched on, and what it looks like.
 */
final class MetaController extends ApiController
{
    public function index(array $params): void
    {
        $app = $this->app;
        $auth = $app->auth;

        $modules = [];
        // Modules::all() names the stem of a module's texts: ".name" and ".description".
        foreach ($app->modules->all() as $id => $stem) {
            $modules[] = [
                'id' => $id,
                'label_key' => $stem . '.name',
                'label' => $this->trans($stem . '.name'),
                'enabled' => $app->modules->enabled($id),
            ];
        }

        $this->data([
            'api_version' => App::API_VERSION,
            'core_version' => $app->coreVersion(),
            'account' => self::accountData($auth->account()),
            'permissions' => array_values(array_filter(array_keys($app->permissions()), fn (string $name) => $auth->can($name))),
            'modules' => $modules,
            'extensions' => array_values(array_map(fn (Manifest $manifest) => [
                'id' => $manifest->id,
                'name' => $manifest->name,
                'version' => $manifest->version,
                'type' => $manifest->type,
                'description' => $manifest->description,
            ], $app->extensions->loaded())),
            'admin_menu' => $this->adminMenu(),
            'notices' => array_map(fn (array $notice) => [
                'count' => (int) $notice['count'],
                'label_key' => $notice['label_key'],
                'label' => $this->trans($notice['label_key'], ['count' => $notice['count']]),
                'link' => $notice['link'],
            ], $app->adminNotices()),
            'branding' => ['site_name' => $app->siteName()] + $app->design->values(),
            'languages' => ['default' => $app->locales->default(), 'enabled' => $app->locales->enabled()],
        ]);
    }

    /**
     * The administration's menu as this account sees it: only the entries
     * it holds the permission for, in the fixed order of the sections, and
     * no section without an entry.
     *
     * @return list<array{id: string, label_key: string, label: string, items: list<array{label_key: string, label: string, path: string, permission: string}>}>
     */
    private function adminMenu(): array
    {
        $labels = $this->app->adminMenuGroupLabels();
        $groups = [];

        foreach (App::ADMIN_GROUPS as $group) {
            $items = [];
            foreach ($this->app->adminMenu() as $entry) {
                if ($entry['group'] === $group && $this->app->auth->can($entry['permission'])) {
                    $items[] = [
                        'label_key' => $entry['label_key'],
                        'label' => $this->trans($entry['label_key']),
                        'path' => $entry['path'],
                        'permission' => $entry['permission'],
                    ];
                }
            }
            if ($items !== []) {
                $labelKey = $labels[$group] ?? 'core.admin.group.' . $group;
                $groups[] = ['id' => $group, 'label_key' => $labelKey, 'label' => $this->trans($labelKey), 'items' => $items];
            }
        }

        return $groups;
    }
}
