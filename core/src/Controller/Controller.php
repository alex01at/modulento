<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\App;

/**
 * Optional base class for core and extension controllers. Access control
 * and CSRF are already done by the Router when an action runs.
 */
abstract class Controller
{
    public function __construct(protected App $app)
    {
    }

    protected function render(string $template, array $data = []): void
    {
        echo $this->app->view()->render($template, $data);
    }

    /** @param string $path a path of this site without language prefix; the prefix is added here */
    protected function redirect(string $path, ?string $locale = null): void
    {
        header('Location: ' . $this->app->url($path, $locale));
    }

    protected function trans(string $key, array $replacements = []): string
    {
        return $this->app->translator->trans($key, $replacements);
    }
}
