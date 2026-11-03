<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Session;

/**
 * Lets an administrator reword single texts (core, an extension's or the
 * active theme's) without touching PHP files - writes land in this
 * installation's own lang/ folder (Translator::setOverride()), which
 * updates never touch.
 */
final class TranslationController extends Controller
{
    private const PER_PAGE = 50;

    public function index(array $params): void
    {
        $app = $this->app;
        $locale = $this->locale();
        $search = trim((string) ($_GET['q'] ?? ''));

        $texts = $app->translator->texts($locale);
        $total = count($texts);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $texts = array_filter(
                $texts,
                static fn (string $value, string $key): bool => str_contains(mb_strtolower($key), $needle) || str_contains(mb_strtolower($value), $needle),
                ARRAY_FILTER_USE_BOTH
            );
        }
        ksort($texts);

        $pages = max(1, (int) ceil(count($texts) / self::PER_PAGE));
        $page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
        $texts = array_slice($texts, (int) (($page - 1) * self::PER_PAGE), self::PER_PAGE, true);

        $this->render('@admin/translations.twig', [
            'locale' => $locale,
            'locales' => $app->locales->available(),
            'q' => $search,
            'texts' => $texts,
            'overridden' => $app->translator->overrides($locale),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    public function save(array $params): void
    {
        $app = $this->app;
        $locale = $this->locale();
        $key = (string) ($_POST['key'] ?? '');
        $value = trim((string) ($_POST['value'] ?? ''));

        if (!array_key_exists($key, $app->translator->texts($locale))) {
            Session::flash('error', $this->trans('core.admin.translations.invalid'));
            $this->redirect($this->returnPath($locale));
            return;
        }

        $app->translator->setOverride($locale, $key, $value);
        Session::flash('success', $this->trans('core.admin.translations.saved'));
        $this->redirect($this->returnPath($locale));
    }

    public function reset(array $params): void
    {
        $app = $this->app;
        $locale = $this->locale();
        $key = (string) ($_POST['key'] ?? '');

        $app->translator->clearOverride($locale, $key);
        Session::flash('success', $this->trans('core.admin.translations.reset_done'));
        $this->redirect($this->returnPath($locale));
    }

    private function locale(): string
    {
        $locale = (string) ($_POST['locale'] ?? $_GET['locale'] ?? '');

        return in_array($locale, $this->app->locales->available(), true) ? $locale : $this->app->locales->default();
    }

    private function returnPath(string $locale): string
    {
        $path = '/admin/translations?locale=' . rawurlencode($locale);
        $q = (string) ($_POST['q'] ?? '');
        if ($q !== '') {
            $path .= '&q=' . rawurlencode($q);
        }

        return $path;
    }
}
