<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Content\HomeLayout;
use Modulento\Core\Support\Session;

/**
 * One place to see every widget - shipped and own - and to create a new
 * own one directly, instead of first filling a section on the home page
 * or a content page and saving it as a widget from there (still possible,
 * unchanged, in inline/_block.twig and home_edit.twig).
 */
final class AdminWidgetController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $this->render('@admin/widgets.twig', [
            'widgets' => $app->widgets->all(fn (string $key) => $this->trans($key)),
        ]);
    }

    public function create(array $params): void
    {
        $type = (string) ($_GET['type'] ?? '');
        $this->renderForm(in_array($type, HomeLayout::TYPES, true) ? ['type' => $type] : [], []);
    }

    public function save(array $params): void
    {
        $app = $this->app;
        $type = (string) ($_POST['type'] ?? '');
        $name = (string) ($_POST['name'] ?? '');
        $posted = is_array($_POST['texts'] ?? null) ? $_POST['texts'] : [];
        $postedSettings = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];

        if (!in_array($type, HomeLayout::TYPES, true)) {
            $this->redirect('/admin/widgets/new');
            return;
        }

        $texts = [];
        foreach ($app->locales->enabled() as $locale) {
            foreach (HomeLayout::TEXT_FIELDS[$type] as $field) {
                $value = is_string($posted[$locale][$field] ?? null) ? $posted[$locale][$field] : '';
                $texts[$locale][$field] = HomeLayout::clean($type, $field, $value);
            }
        }

        $settings = [];
        if ($type === 'offers' || $type === 'providers') {
            $settings['count'] = max(1, min(12, (int) ($postedSettings['count'] ?? 6)));
        }
        if ($type === 'image') {
            $settings['media'] = HomeLayout::cleanMedia((string) ($postedSettings['media'] ?? ''));
        }
        $backgroundType = (string) ($postedSettings['background_type'] ?? '');
        if (in_array($backgroundType, ['color', 'gradient', 'image'], true)) {
            $settings['background_type'] = $backgroundType;
            if ($backgroundType !== 'image') {
                $colour = strtolower(trim((string) ($postedSettings['background_color'] ?? '')));
                if (HomeLayout::validColour($colour)) {
                    $settings['background_color'] = $colour;
                }
            }
            if ($backgroundType === 'gradient') {
                $colour2 = strtolower(trim((string) ($postedSettings['background_color2'] ?? '')));
                if (HomeLayout::validColour($colour2)) {
                    $settings['background_color2'] = $colour2;
                }
            }
            if ($backgroundType === 'image') {
                $settings['background_media'] = HomeLayout::cleanMedia((string) ($postedSettings['background_media'] ?? ''));
            }
        }

        $saved = $app->widgets->saveOwn($name, ['type' => $type, 'texts' => $texts, 'settings' => $settings]);
        if (!$saved) {
            $this->renderForm($_POST, [$this->trans('core.widget.invalid_name')]);
            return;
        }

        Session::flash('success', $this->trans('core.widget.saved'));
        $this->redirect('/admin/widgets');
    }

    public function delete(array $params): void
    {
        $this->app->widgets->removeOwn((string) $params['id']);
        Session::flash('success', $this->trans('core.widget.removed'));
        $this->redirect('/admin/widgets');
    }

    /** @param array<string, mixed> $input what was typed, to show again on error - empty for a fresh form */
    private function renderForm(array $input, array $errors): void
    {
        $this->render('@admin/widget_new.twig', [
            'input' => $input,
            'errors' => $errors,
            'types' => HomeLayout::TYPES,
            'fields' => HomeLayout::TEXT_FIELDS,
            'locales' => $this->app->locales->enabled(),
            'default_locale' => $this->app->locales->default(),
        ]);
    }
}
