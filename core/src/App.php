<?php

declare(strict_types=1);

namespace Modulento\Core;

use Modulento\Core\Extension\ExtensionManager;
use Modulento\Core\Support\Auth;
use Modulento\Core\Support\Events;
use Modulento\Core\Support\Router;
use Modulento\Core\Support\Scheduler;
use Modulento\Core\Support\Settings;
use Modulento\Core\Support\Translator;
use Modulento\Core\Support\View;
use Modulento\Core\Theme\ThemeManager;
use PDO;

/**
 * The one object handed to controllers, task handlers and extensions. It
 * holds the core services; there is no container and no auto-wiring.
 */
final class App
{
    /**
     * Version of the interface extensions are written against (Registrar,
     * Router, the services on this class). An extension states the version
     * it needs in extension.json and is not loaded on a mismatch.
     */
    public const API_VERSION = 1;

    public readonly Events $events;
    public readonly Scheduler $scheduler;
    public readonly Router $router;
    public readonly Auth $auth;
    public readonly Translator $translator;
    public readonly ExtensionManager $extensions;
    public readonly Settings $settings;
    public readonly ThemeManager $themes;

    private ?View $view = null;
    /** @var array<int, array{label_key: string, path: string, permission: string}> */
    private array $adminMenu = [];
    /** @var array<string, string> permission name => label key */
    private array $permissions = [];

    public function __construct(public readonly array $config, public readonly PDO $db, string $locale)
    {
        $this->events = new Events();
        $this->scheduler = new Scheduler($db);
        $this->auth = new Auth($db);
        $this->translator = new Translator($locale, $config['app']['name']);
        $this->extensions = new ExtensionManager($db, $config['app']['root'] . '/extensions');
        $this->settings = new Settings($db);
        $this->themes = new ThemeManager($config['app']['root'] . '/themes', $this->settings);
        $this->router = new Router($this);
    }

    /**
     * Built on first use, after every extension has been loaded, because
     * the template folders depend on the enabled extensions.
     */
    public function view(): View
    {
        return $this->view ??= new View($this);
    }

    public function addAdminMenu(string $labelKey, string $path, string $permission): void
    {
        $this->adminMenu[] = ['label_key' => $labelKey, 'path' => $path, 'permission' => $permission];
    }

    /** @return array<int, array{label_key: string, path: string, permission: string}> */
    public function adminMenu(): array
    {
        return $this->adminMenu;
    }

    public function addPermission(string $name, string $labelKey): void
    {
        $this->permissions[$name] = $labelKey;
    }

    /** @return array<string, string> permission name => label key */
    public function permissions(): array
    {
        return $this->permissions;
    }
}
