<?php

declare(strict_types=1);

namespace Modulento\Core;

use Modulento\Core\Account\Accounts;
use Modulento\Core\Account\Avatars;
use Modulento\Core\Support\BadWords;
use Modulento\Core\Support\Design;
use Modulento\Core\Support\Branding;
use Modulento\Core\Support\MessageSeen;
use Modulento\Core\Account\LoginTokens;
use Modulento\Core\Account\Preferences;
use Modulento\Core\Account\Roles;
use Modulento\Core\Account\Tokens;
use Modulento\Core\Catalogue\Categories;
use Modulento\Core\Catalogue\OfferImages;
use Modulento\Core\Catalogue\OfferMessages;
use Modulento\Core\Content\HomeLayout;
use Modulento\Core\Catalogue\Offers;
use Modulento\Core\Content\Pages;
use Modulento\Core\Extension\ExtensionManager;
use Modulento\Core\Media\Library;
use Modulento\Core\Order\OrderFiles;
use Modulento\Core\Order\Orders;
use Modulento\Core\Order\Withdrawals;
use Modulento\Core\Report\Reports;
use Modulento\Core\Package\Packages;
use Modulento\Core\Payment\Payments;
use Modulento\Core\Payment\PaypalPayment;
use Modulento\Core\Payment\StripePayment;
use Modulento\Core\Payment\TransferPayment;
use Modulento\Core\Provider\Providers;
use Modulento\Core\Review\Reviews;
use Modulento\Core\Support\Modules;
use Modulento\Core\Support\Auth;
use Modulento\Core\Support\CurlHttpClient;
use Modulento\Core\Support\Events;
use Modulento\Core\Support\Locales;
use Modulento\Core\Support\Mailer;
use Modulento\Core\Support\ReleaseClient;
use Modulento\Core\Support\Router;
use Modulento\Core\Support\Scheduler;
use Modulento\Core\Support\Secrets;
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
    public readonly LoginTokens $loginTokens;
    public readonly Mailer $mailer;
    public readonly Locales $locales;
    public readonly Pages $pages;
    public readonly Providers $providers;
    public readonly Roles $roles;
    public readonly Categories $categories;
    public readonly Offers $offers;
    public readonly OfferImages $offerImages;
    public readonly Orders $orders;
    public readonly OrderFiles $orderFiles;
    public readonly Withdrawals $withdrawals;
    public readonly Reports $reports;
    public readonly Modules $modules;
    public readonly Avatars $avatars;
    public readonly Branding $branding;
    public readonly Library $media;
    public readonly OfferMessages $offerMessages;
    public readonly BadWords $badWords;
    public readonly HomeLayout $homeLayout;
    public readonly Design $design;
    public readonly MessageSeen $messageSeen;
    public readonly Preferences $preferences;
    public readonly Reviews $reviews;
    public readonly Packages $packages;
    public readonly Payments $payments;

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
    /** @var array<int, array{label_key: string, path: string}> */
    private array $navigation = [];
    /** @var array<int, array{template: string, data: ?\Closure}> */
    private array $homeSections = [];
    /** @var array<string, string> permission name => label key */
    private array $permissions = [];

    public function __construct(public readonly array $config, public readonly PDO $db, string $locale = 'en')
    {
        $this->events = new Events();
        $this->scheduler = new Scheduler($db);
        $this->auth = new Auth($db);
        $this->translator = new Translator($locale, $config['app']['name']);
        $root = $config['app']['root'];
        // Tests point these at folders of their own.
        $extensionsDir = $config['app']['extensions'] ?? $root . '/extensions';
        $themesDir = $config['app']['themes'] ?? $root . '/themes';
        $this->extensions = new ExtensionManager($db, $extensionsDir);
        $this->settings = new Settings($db);
        $this->locales = new Locales($this->settings, $config['app']['root'] . '/core/lang');
        $this->pages = new Pages($db, $this->locales);
        $this->providers = new Providers($db, $this->settings, $this->locales);
        $this->roles = new Roles($db);
        $this->categories = new Categories($db, $this->locales);
        $this->offers = new Offers($db, $this->settings, $this->locales);
        $this->orders = new Orders($db);
        $this->payments = new Payments(
            $db,
            $this->settings,
            $this->orders,
            new Secrets($config['app']['secret_key'] ?? $root . '/var/secret.key'),
            // Tests put a stand-in here that answers without any network.
            $config['payment']['http'] ?? new CurlHttpClient()
        );
        $this->orders->registerPaymentMethod(new TransferPayment());
        $this->orders->registerPaymentMethod(new PaypalPayment());
        $this->orders->registerPaymentMethod(new StripePayment());
        $this->withdrawals = new Withdrawals($db);
        $this->reports = new Reports($db);
        $this->modules = new Modules($this->settings);
        $this->badWords = new BadWords($this->settings);
        $this->homeLayout = new HomeLayout($this->settings);
        $this->design = new Design($this->settings);
        $this->messageSeen = new MessageSeen($db);
        $this->reviews = new Reviews($db, $this->badWords);
        $this->orderFiles = new OrderFiles($db, ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/orders');
        $this->offerImages = new OfferImages($db, ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/offers');
        $this->themes = new ThemeManager($themesDir, $this->settings);
        $this->packages = new Packages(
            $db,
            new ReleaseClient($config['update']['token'] ?? ''),
            $extensionsDir,
            $themesDir,
            $config['app']['work'] ?? $root . '/var/updates',
            $config['packages']['sources'] ?? [],
            // VERSION only exists inside a release package.
            is_file($root . '/VERSION') ? (trim((string) file_get_contents($root . '/VERSION')) ?: '0.0.0') : '0.0.0'
        );
        $this->avatars = new Avatars($db, ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/avatars');
        $this->branding = new Branding($this->settings, ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/branding');
        $this->offerMessages = new OfferMessages($db);
        $this->media = new Library($db, ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/media');
        $this->preferences = new Preferences($db);
        $this->accounts = new Accounts($db);
        $this->tokens = new Tokens($db);
        $this->loginTokens = new LoginTokens($db);
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

    /**
     * The sections of the administration's menu, in this order. An entry
     * names its section; one without a known section goes to "more".
     */
    public const ADMIN_GROUPS = ['content', 'marketplace', 'moderation', 'people', 'more', 'system'];

    public function addAdminMenu(string $labelKey, string $path, string $permission, string $group = 'more'): void
    {
        $this->adminMenu[] = [
            'label_key' => $labelKey, 'path' => $path, 'permission' => $permission,
            'group' => in_array($group, self::ADMIN_GROUPS, true) ? $group : 'more',
        ];
    }

    /** @return array<int, array{label_key: string, path: string, permission: string, group: string}> */
    public function adminMenu(): array
    {
        return $this->adminMenu;
    }

    /** An entry of the site's main menu that an extension brings along. */
    public function addNavigation(string $labelKey, string $path): void
    {
        $this->navigation[] = ['label_key' => $labelKey, 'path' => $path];
    }

    /** @return array<int, array{label_key: string, path: string}> */
    public function navigation(): array
    {
        return $this->navigation;
    }

    /**
     * A template an extension wants shown on the home page.
     *
     * @param \Closure(App): array<string, mixed>|null $data what the template gets, fetched only when the home page is shown
     */
    public function addHomeSection(string $template, ?\Closure $data = null): void
    {
        $this->homeSections[] = ['template' => $template, 'data' => $data];
    }

    /** @return array<int, array{template: string, data: array<string, mixed>}> */
    public function homeSections(): array
    {
        return array_map(
            fn (array $section) => ['template' => $section['template'], 'data' => $section['data'] !== null ? ($section['data'])($this) : []],
            $this->homeSections
        );
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
