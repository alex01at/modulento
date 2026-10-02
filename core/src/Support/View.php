<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use Modulento\Core\App;
use Modulento\Core\Catalogue\OfferView;
use Modulento\Core\Controller\AssetController;
use Modulento\Core\Provider\ProviderView;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Where a template name is looked up (first match wins):
 *
 *   "home.twig"          active site theme, then themes/default
 *   "@admin/x.twig"      themes/admin
 *   "@<ext id>/x.twig"   <site theme>/extensions/<ext id>/, then the
 *                        extension's own templates/ folder
 *
 * What controllers hand to templates - the globals and functions below and
 * the variables documented per template - is the contract between code and
 * themes. Templates get data, never services or database access.
 */
final class View
{
    private Environment $twig;

    public function __construct(App $app)
    {
        $root = $app->config['app']['root'];
        $debug = $app->config['app']['env'] === 'dev';
        $themes = $app->themes;

        $loader = new FilesystemLoader();
        foreach ($themes->siteDirs('templates') as $dir) {
            $loader->addPath($dir);
        }
        $loader->addPath($themes->adminDir('templates'), 'admin');

        foreach ($app->extensions->loaded() as $manifest) {
            foreach ($themes->siteDirs('extensions/' . $manifest->id) as $dir) {
                $loader->addPath($dir, $manifest->id);
            }
            if (is_dir($manifest->dir . '/templates')) {
                $loader->addPath($manifest->dir . '/templates', $manifest->id);
            }
        }

        $this->twig = new Environment($loader, [
            'cache' => $debug ? false : $root . '/var/cache/twig',
            'debug' => $debug,
            // Without this, production never re-checks whether a template
            // changed and keeps serving the compiled copy after a deploy.
            'auto_reload' => true,
            // HTML escaping for every template except "*.txt.twig" - the
            // plain-text e-mails, where "&amp;" would be wrong.
            'autoescape' => 'name',
        ]);

        $translator = $app->translator;
        $auth = $app->auth;

        $this->twig->addGlobal('flashes', Session::pullFlashes());
        $this->twig->addGlobal('account', $auth->account());
        $this->twig->addGlobal('site_name', $app->siteName());
        $this->twig->addGlobal('admin_menu', array_values(array_filter(
            $app->adminMenu(),
            fn (array $item) => $auth->can($item['permission'])
        )));

        $this->twig->addFunction(new TwigFunction(
            'trans',
            fn (string $key, array $replacements = []) => $translator->trans($key, $replacements)
        ));
        // The language can change while rendering (an e-mail in the
        // recipient's language), so these are functions, not fixed values.
        $this->twig->addFunction(new TwigFunction('locale', fn () => $translator->locale()));
        // The request path without its language prefix, e.g. to mark the
        // menu entry of the current page.
        $this->twig->addFunction(new TwigFunction('current_path', fn () => $app->path));
        $this->twig->addFunction(new TwigFunction(
            'url',
            fn (string $path, ?string $locale = null) => $app->url($path, $locale)
        ));
        // The current page in every enabled language, for the language
        // menu and for <link rel="alternate" hreflang>.
        $this->twig->addFunction(new TwigFunction('locale_urls', function () use ($app): array {
            $urls = [];
            foreach ($app->locales->enabled() as $locale) {
                $urls[] = [
                    'locale' => $locale,
                    'name' => $app->locales->name($locale),
                    'url' => $app->url($app->alternatePaths[$locale] ?? $app->path, $locale),
                    'absolute_url' => $app->url($app->alternatePaths[$locale] ?? $app->path, $locale, true),
                    'current' => $locale === $app->translator->locale(),
                ];
            }

            return $urls;
        }));
        // Published pages for a menu: "header", "footer" (includes the
        // legal pages) or a role such as "terms".
        $this->twig->addFunction(new TwigFunction('page_links', function (string $where) use ($app): array {
            return array_map(
                fn (array $link) => ['title' => $link['title'], 'url' => $app->url($link['path']), 'role' => $link['role']],
                $app->pages->links($where, $app->translator->locale())
            );
        }));
        // The newest public offers as cards, e.g. for the home page.
        $this->twig->addFunction(new TwigFunction('latest_offers', function (int $limit = 6) use ($app): array {
            $list = $app->offers->listPublic([], $app->translator->locale(), 1, max(1, min(48, $limit)));

            return OfferView::cards($list['rows'], $app);
        }));
        // The category tree in the current language; each entry carries
        // "offer_count" (a top category counts its subcategories too).
        $this->twig->addFunction(new TwigFunction('categories', function () use ($app): array {
            $counts = $app->offers->publicCountsByCategory();
            $tree = $app->categories->tree($app->translator->locale());

            foreach ($tree as &$top) {
                $top['offer_count'] = $counts[$top['id']] ?? 0;
                foreach ($top['children'] as &$child) {
                    $child['offer_count'] = $counts[$child['id']] ?? 0;
                    $top['offer_count'] += $child['offer_count'];
                }
            }

            return $tree;
        }));
        // Public providers for a showcase, best rated first.
        $this->twig->addFunction(new TwigFunction(
            'top_providers',
            fn (int $limit = 3) => ProviderView::all($app->providers->top($limit), $app)
        ));
        // The logged-in account's provider profile: its status, or null
        // without one. For menus that show a provider's own pages.
        $this->twig->addFunction(new TwigFunction('provider_status', function () use ($app, $auth): ?string {
            static $status = false;
            if ($status === false) {
                $account = $auth->account();
                $status = $account !== null ? ($app->providers->findByAccount($account['id'])['status'] ?? null) : null;
            }

            return $status;
        }));
        $this->twig->addFunction(new TwigFunction('registration_open', fn () => $app->settings->get('core.registration', 'open') === 'open'));
        $this->twig->addFunction(new TwigFunction('locale_name', fn (string $locale) => $app->locales->name($locale)));
        $this->twig->addFunction(new TwigFunction('can', fn (string $permission) => $auth->can($permission)));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn () => Csrf::token()));
        $this->twig->addFunction(new TwigFunction(
            'csrf_field',
            fn () => '<input type="hidden" name="_csrf" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') . '">',
            ['is_safe' => ['html']]
        ));
        $this->twig->addFunction(new TwigFunction(
            'money',
            fn (int $minorUnits, string $currency = 'EUR') => Money::format($minorUnits, $currency, $translator->locale())
        ));

        // URLs of files in a theme's or an extension's assets/ folder. They
        // carry a stamp of the file, so a changed or different file is
        // fetched anew although assets are served with a long cache lifetime.
        $this->twig->addFunction(new TwigFunction(
            'theme_asset',
            fn (string $path) => self::assetUrl('/assets/theme/', $path, AssetController::locate($themes->siteDirs('assets'), $path))
        ));
        $this->twig->addFunction(new TwigFunction(
            'admin_asset',
            fn (string $path) => self::assetUrl('/assets/admin/', $path, AssetController::locate([$themes->adminDir('assets')], $path))
        ));
        $this->twig->addFunction(new TwigFunction(
            'ext_asset',
            function (string $extensionId, string $path) use ($app): string {
                $manifest = $app->extensions->loaded()[$extensionId] ?? null;
                $file = $manifest !== null ? AssetController::locate([$manifest->dir . '/assets'], $path) : null;

                return self::assetUrl('/assets/ext/' . rawurlencode($extensionId) . '/', $path, $file);
            }
        ));
    }

    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    /** @param array<string, mixed> $data */
    public function renderBlock(string $template, string $block, array $data = []): string
    {
        return $this->twig->load($template)->renderBlock($block, $data);
    }

    private static function assetUrl(string $prefix, string $path, ?string $file): string
    {
        $url = $prefix . implode('/', array_map('rawurlencode', explode('/', $path)));

        if ($file === null) {
            return $url;
        }

        // The stamp names the file itself, not only its change time: two
        // themes have the same URL for "theme.css", and after an update
        // their files carry the same time. With the time alone, switching
        // themes would leave browsers showing the previous theme's cached
        // stylesheet.
        return $url . '?v=' . substr(md5($file . '|' . filemtime($file) . '|' . filesize($file)), 0, 12);
    }
}
