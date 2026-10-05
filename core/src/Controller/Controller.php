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

    /**
     * The tab of a page with tabs: the one named in the query or the form,
     * the first one when none (or an unknown one) is named.
     *
     * @param list<string> $tabs
     */
    protected function tab(array $tabs): string
    {
        $tab = $_POST['tab'] ?? $_GET['tab'] ?? null;

        return is_string($tab) && in_array($tab, $tabs, true) ? $tab : $tabs[0];
    }

    /**
     * Where a form wants to come back to: a path of this site given in the
     * request, checked; anything else goes to the default.
     */
    protected function safeReturn(string $default): string
    {
        $path = $_POST['return'] ?? $_GET['return'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')
            || str_contains($path, '\\') || preg_match('/[\x00-\x20]/', $path) === 1) {
            return $default;
        }

        return $path;
    }

    protected function trans(string $key, array $replacements = []): string
    {
        return $this->app->translator->trans($key, $replacements);
    }
}
