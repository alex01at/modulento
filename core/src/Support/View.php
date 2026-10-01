<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use Modulento\Core\App;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Core templates live in the main Twig namespace, an extension's templates
 * in "@<extension id>/...". A theme can override both: themes/<theme>/
 * templates/ shadows core templates, themes/<theme>/extensions/<id>/
 * shadows that extension's templates.
 */
final class View
{
    private Environment $twig;

    public function __construct(App $app)
    {
        $root = $app->config['app']['root'];
        $debug = $app->config['app']['env'] === 'dev';
        $themeDir = $app->config['app']['theme'] !== ''
            ? $root . '/themes/' . basename($app->config['app']['theme'])
            : null;

        $loader = new FilesystemLoader();
        if ($themeDir !== null && is_dir($themeDir . '/templates')) {
            $loader->addPath($themeDir . '/templates');
        }
        $loader->addPath($root . '/core/templates');

        foreach ($app->extensions->loaded() as $manifest) {
            if ($themeDir !== null && is_dir($themeDir . '/extensions/' . $manifest->id)) {
                $loader->addPath($themeDir . '/extensions/' . $manifest->id, $manifest->id);
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
    }

    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }
}
