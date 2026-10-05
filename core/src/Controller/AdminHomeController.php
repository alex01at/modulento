<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Content\HomeLayout;
use Modulento\Core\Media\Library;
use Modulento\Core\Support\Session;

/**
 * The editor of the home page. One form holds every block; the buttons next
 * to a block (move, remove) and "add" save the form first, then do their step.
 */
final class AdminHomeController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $layout = $app->homeLayout;

        $this->render('@admin/home_edit.twig', [
            'blocks' => $this->blocksForForm($layout->blocks()),
            'types' => HomeLayout::TYPES,
            'fields' => HomeLayout::TEXT_FIELDS,
            'locales' => $app->locales->enabled(),
            'default_locale' => $app->locales->default(),
            'pictures' => array_map(fn (array $row) => ['url' => Library::url($row['file']), 'title' => $row['title']],
                $app->media->list(1, 200)['rows']),
            'customized' => $layout->isCustomized(),
        ]);
    }

    public function save(array $params): void
    {
        $layout = $this->app->homeLayout;
        $blocks = $this->fromForm($layout->blocks());
        $action = (string) ($_POST['action'] ?? 'save');

        if ($action === 'reset') {
            $layout->reset();
            Session::flash('success', $this->trans('core.home.reset_done'));
            $this->redirect('/admin/home');
            return;
        }

        if ($action === 'add') {
            $type = (string) ($_POST['add_type'] ?? '');
            if (in_array($type, HomeLayout::TYPES, true)) {
                $blocks[] = HomeLayout::newBlock($type) + ($type === 'offers' ? ['settings' => ['count' => 6]] : []);
            }
        } elseif (preg_match('/^(up|down|delete):([a-z0-9]{1,16})$/', $action, $match) === 1) {
            $blocks = $this->step($blocks, $match[1], $match[2]);
        }

        $layout->save($blocks);
        Session::flash('success', $this->trans($action === 'save' ? 'core.home.saved' : 'core.home.changed'));
        $this->redirect('/admin/home');
    }

    /** @param list<array<string, mixed>> $blocks */
    private function blocksForForm(array $blocks): array
    {
        return array_map(fn (array $block) => $block + ['label_key' => 'core.home.type.' . $block['type']], $blocks);
    }

    /**
     * The stored blocks with the values of the form on them. A block that the
     * form does not know (added elsewhere meanwhile) is kept as it is.
     *
     * @param list<array<string, mixed>> $stored
     * @return list<array<string, mixed>>
     */
    private function fromForm(array $stored): array
    {
        $posted = is_array($_POST['blocks'] ?? null) ? $_POST['blocks'] : [];
        $blocks = [];

        foreach ($stored as $block) {
            $id = $block['id'];
            if (!isset($posted[$id]) || !is_array($posted[$id])) {
                $blocks[] = $block;
                continue;
            }
            $input = $posted[$id];
            $type = $block['type'];

            $texts = [];
            foreach ($this->app->locales->enabled() as $locale) {
                foreach (HomeLayout::TEXT_FIELDS[$type] as $field) {
                    $value = is_string($input['texts'][$locale][$field] ?? null) ? $input['texts'][$locale][$field] : '';
                    $texts[$locale][$field] = HomeLayout::clean($type, $field, $value);
                }
            }

            $settings = [];
            if ($type === 'offers' || $type === 'providers') {
                $settings['count'] = max(1, min(12, (int) ($input['settings']['count'] ?? 6)));
            }
            if ($type === 'image') {
                $settings['media'] = HomeLayout::cleanMedia((string) ($input['settings']['media'] ?? ''));
            }

            $blocks[] = [
                'id' => $id,
                'type' => $type,
                'enabled' => isset($input['enabled']),
                'texts' => $texts,
                'settings' => $settings,
            ];
        }

        return $blocks;
    }

    /**
     * Moves a block one place up or down, or removes it.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function step(array $blocks, string $direction, string $id): array
    {
        $index = null;
        foreach ($blocks as $i => $block) {
            if ($block['id'] === $id) {
                $index = $i;
            }
        }
        if ($index === null) {
            return $blocks;
        }

        if ($direction === 'delete') {
            array_splice($blocks, $index, 1);
        } else {
            $target = $direction === 'up' ? $index - 1 : $index + 1;
            if ($target >= 0 && $target < count($blocks)) {
                [$blocks[$index], $blocks[$target]] = [$blocks[$target], $blocks[$index]];
            }
        }

        return $blocks;
    }
}
