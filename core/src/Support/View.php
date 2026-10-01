<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use Modulento\Core\App;
use Modulento\Core\Controller\AssetController;
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
        ]);

        $translator = $app->translator;
        $auth = $app->auth;

        $this->twig->addGlobal('flashes', Session::pullFlashes());
        $this->twig->addGlobal('account', $auth->account());
        $this->twig->addGlobal('locale', $translator->locale());
        $this->twig->addGlobal('site_name', $app->config['app']['name']);
        $this->twig->addGlobal('admin_menu', array_values(array_filter(
            $app->adminMenu(),
            fn (array $item) => $auth->can($item['permission'])
        )));

        $this->twig->addFunction(new TwigFunction(
            'trans',
            fn (string $key, array $replacements = []) => $translator->trans($key, $replacements)
        ));
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
        // carry the file's change time, so a changed file is fetched anew
        // although assets are served with a long cache lifetime.
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

    private static function assetUrl(string $prefix, string $path, ?string $file): string
    {
        $url = $prefix . implode('/', array_map('rawurlencode', explode('/', $path)));

        return $file !== null ? $url . '?v=' . filemtime($file) : $url;
    }
}
