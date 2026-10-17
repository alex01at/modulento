<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Content\CustomMenu;
use Modulento\Core\Support\Session;

/**
 * The editor of the operator's own entries in the site's main (header) menu
 * - next to whatever an installed extension already adds there. One form
 * holds every entry; the buttons next to one (move, remove) and "add" save
 * the form first, then do their step - the same pattern AdminHomeController
 * uses for the home page's own blocks.
 */
final class AdminMenuController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;

        $this->render('@admin/menu_edit.twig', [
            'locale' => $app->translator->locale(),
            'default_locale' => $app->locales->default(),
            'items' => $app->customMenu->items(),
        ]);
    }

    public function save(array $params): void
    {
        $menu = $this->app->customMenu;
        $items = $this->fromForm($menu->items());
        $action = (string) ($_POST['action'] ?? 'save');

        if ($action === 'add') {
            $items[] = CustomMenu::newItem();
        } elseif (preg_match('/^(up|down|delete):([a-f0-9]{1,16})$/', $action, $match) === 1) {
            $items = $this->step($items, $match[1], $match[2]);
        }

        $menu->save($items);
        Session::flash('success', $this->trans($action === 'save' ? 'core.menu.saved' : 'core.menu.changed'));
        $this->redirect($this->safeReturn('/admin/menu'));
    }

    /**
     * The stored entries with the values of the form on them. An entry the
     * form does not know (added elsewhere meanwhile) is kept as it is.
     *
     * @param list<array<string, mixed>> $stored
     * @return list<array<string, mixed>>
     */
    private function fromForm(array $stored): array
    {
        $posted = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
        $locale = $this->app->translator->locale();
        $items = [];

        foreach ($stored as $item) {
            $id = $item['id'];
            if (!isset($posted[$id]) || !is_array($posted[$id])) {
                $items[] = $item;
                continue;
            }
            $input = $posted[$id];

            // Only the language the administration is in is edited here; the labels of the others stay.
            $texts = $item['texts'];
            $label = is_string($input['label'] ?? null) ? $input['label'] : '';
            $texts[$locale]['label'] = CustomMenu::cleanLabel($label);

            $path = is_string($input['path'] ?? null) ? trim($input['path']) : '';
            $items[] = [
                'id' => $id,
                'enabled' => isset($input['enabled']),
                'path' => CustomMenu::validPath($path) ? $path : '',
                'texts' => $texts,
            ];
        }

        return $items;
    }

    /**
     * Moves an entry one place up or down, or removes it.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function step(array $items, string $direction, string $id): array
    {
        $index = null;
        foreach ($items as $i => $item) {
            if ($item['id'] === $id) {
                $index = $i;
            }
        }
        if ($index === null) {
            return $items;
        }

        if ($direction === 'delete') {
            array_splice($items, $index, 1);
        } else {
            $target = $direction === 'up' ? $index - 1 : $index + 1;
            if ($target >= 0 && $target < count($items)) {
                [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
            }
        }

        return $items;
    }
}
