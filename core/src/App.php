<?php

declare(strict_types=1);

namespace Modulento\Core;

use Modulento\Core\Account\Accounts;
use Modulento\Core\Account\Tokens;
use Modulento\Core\Content\Pages;
use Modulento\Core\Extension\ExtensionManager;
use Modulento\Core\Support\Auth;
use Modulento\Core\Support\Events;
use Modulento\Core\Support\Locales;
use Modulento\Core\Support\Mailer;
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
    public readonly Accounts $accounts;
    public readonly Tokens $tokens;
    public readonly Mailer $mailer;
    public readonly Locales $locales;
    public readonly Pages $pages;

    /** The request path without its language prefix - what routes are matched against. */
    public string $path = '/';
    /** Set when the request should be answered with a redirect to its one proper address. */
    public ?string $redirect = null;
    /**
     * The current page's path in other languages, where it differs from
     * $path (a page whose slug is translated). Set by the controller.
     *
     * @var array<string, string> locale => path without prefix
     */
    public array $alternatePaths = [];

    private ?View $view = null;
    /** @var array<int, array{label_key: string, path: string, permission: string}> */
    private array $adminMenu = [];
    /** @var array<string, string> permission name => label key */
    private array $permissions = [];

    public function __construct(public readonly array $config, public readonly PDO $db, string $locale = 'en')
    {
        $this->events = new Events();
        $this->scheduler = new Scheduler($db);
        $this->auth = new Auth($db);
        $this->translator = new Translator($locale, $config['app']['name']);
        $this->extensions = new ExtensionManager($db, $config['app']['root'] . '/extensions');
        $this->settings = new Settings($db);
        $this->locales = new Locales($this->settings, $config['app']['root'] . '/core/lang');
        $this->pages = new Pages($db, $this->locales);
        $this->themes = new ThemeManager($config['app']['root'] . '/themes', $this->settings);
        $this->accounts = new Accounts($db);
        $this->tokens = new Tokens($db);
        $this->mailer = new Mailer($this);
        $this->router = new Router($this);
    }

    /**
     * The address of a path of this site in a language (the current one
     * unless given). Every link, form action and redirect goes through
     * this, which is what keeps a visitor in their language.
     */
    public function url(string $path, ?string $locale = null, bool $absolute = false): string
    {
        $url = $this->locales->prefix($path, $locale ?? $this->translator->locale());

        return $absolute ? $this->config['app']['url'] . $url : $url;
    }

    /** Set in the administration; APP_NAME from .env until then. */
    public function siteName(): string
    {
        return $this->settings->get('core.site_name', $this->config['app']['name']);
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
