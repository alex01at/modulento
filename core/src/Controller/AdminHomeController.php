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
            'locale' => $app->translator->locale(),
            'default_locale' => $app->locales->default(),
            'blocks' => $this->blocksForForm($layout->blocks()),
            'types' => HomeLayout::TYPES,
            'fields' => HomeLayout::TEXT_FIELDS,
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
            $this->redirect($this->safeReturn('/admin/home'));
            return;
        }

        if ($action === 'add') {
            $type = (string) ($_POST['add_type'] ?? '');
            if (in_array($type, HomeLayout::TYPES, true)) {
                $blocks[] = HomeLayout::newBlock($type) + ($type === 'offers' ? ['settings' => ['count' => 6]] : []);
            }
        } elseif (preg_match('/^insert:([a-z0-9-]{1,32})$/', $action, $match) === 1) {
            $type = (string) ($_POST['add_type'] ?? '');
            if (in_array($type, HomeLayout::TYPES, true)) {
                $blocks = HomeLayout::insertAfter($blocks, $match[1], $type);
            }
        } elseif (preg_match('/^(up|down|delete|toggle|duplicate):([a-z0-9-]{1,32})$/', $action, $match) === 1) {
            $blocks = $this->step($blocks, $match[1], $match[2]);
        }

        $layout->save($blocks);
        Session::flash('success', $this->trans($action === 'save' ? 'core.home.saved' : 'core.home.changed'));
        $this->redirect($this->safeReturn('/admin/home'));
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

            // Only the language the administration is in is edited here; the texts of the others stay.
            $locale = $this->app->translator->locale();
            $texts = $block['texts'];
            foreach (HomeLayout::TEXT_FIELDS[$type] as $field) {
                $value = is_string($input['texts'][$locale][$field] ?? null) ? $input['texts'][$locale][$field] : '';
                $texts[$locale][$field] = HomeLayout::clean($type, $field, $value);
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

        if ($direction === 'duplicate') {
            return HomeLayout::duplicateAfter($blocks, $id);
        }

        if ($direction === 'toggle') {
            $blocks[$index]['enabled'] = !$blocks[$index]['enabled'];

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

    /** A text field saved from the page itself, with JavaScript: the cleaned value comes back. */
    public function field(array $params): void
    {
        $app = $this->app;
        $data = json_decode((string) file_get_contents('php://input'), true);
        $locale = is_array($data) && is_string($data['locale'] ?? null) ? $data['locale'] : '';
        if (!is_array($data) || !$app->locales->isEnabled($locale) || !is_string($data['block'] ?? null)
            || !is_string($data['field'] ?? null) || !is_string($data['value'] ?? null)) {
            $this->json(['ok' => false], 422);
            return;
        }

        $clean = $app->homeLayout->setField($data['block'], $locale, $data['field'], $data['value']);
        if ($clean === null) {
            $this->json(['ok' => false], 422);
            return;
        }
        $this->json(['ok' => true, 'value' => $clean]);
    }
}
