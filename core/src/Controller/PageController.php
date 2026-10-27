<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Content\HomeLayout;
use Modulento\Core\Content\Pages;
use Modulento\Core\Support\Session;

final class PageController extends Controller
{
    /** The block types a page may use - the same catalogue as the home page, plus whatever extensions registered. */
    private function blockTypes(): array
    {
        return HomeLayout::types($this->app->pageBlockTypes());
    }

    /** A title or body of a page saved from the page itself (edit mode): the cleaned value comes back. */
    public function field(array $params): void
    {
        $data = json_decode((string) file_get_contents('php://input'), true);
        $locale = is_array($data) && is_string($data['locale'] ?? null) ? $data['locale'] : '';
        if (!is_array($data) || !$this->app->locales->isEnabled($locale) || !is_string($data['field'] ?? null) || !is_string($data['value'] ?? null)) {
            $this->json(['ok' => false], 422);
            return;
        }
        $clean = $this->app->pages->setText((int) $params['id'], $locale, $data['field'], $data['value']);
        if ($clean === null) {
            $this->json(['ok' => false], 422);
            return;
        }
        $this->json(['ok' => true, 'value' => $clean]);
    }

    /** Registered last of all routes: any one-segment address nothing else claimed. */
    public function show(array $params): void
    {
        $locale = $this->app->translator->locale();
        $found = $this->app->pages->findPublishedBySlug($locale, $params['slug']);

        if ($found === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        // Where this page lives in the other languages, for the language
        // menu and hreflang: its own slug there, or this text's slug where
        // a language has none.
        foreach ($this->app->locales->enabled() as $other) {
            $text = $found['page']['translations'][$other] ?? $found['text'];
            $this->app->alternatePaths[$other] = '/' . $text['slug'];
        }

        $this->render('page.twig', [
            'page' => [
                'id' => $found['page']['id'],
                'title' => $found['text']['title'],
                'meta_description' => $found['text']['meta_description'],
                'locale' => $found['text_locale'],
                'role' => $found['page']['role'],
            ],
        ]);
    }

    public function index(array $params): void
    {
        $this->render('@admin/pages.twig', [
            'pages' => $this->app->pages->tree(),
            'default_locale' => $this->app->locales->default(),
        ]);
    }

    public function edit(array $params): void
    {
        $page = isset($params['id']) ? $this->app->pages->find((int) $params['id']) : null;
        if (isset($params['id']) && $page === null) {
            $this->redirect('/admin/pages');
            return;
        }

        $this->renderForm($page, []);
    }

    public function save(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : null;
        $role = (string) ($_POST['role'] ?? '');

        $parentId = (int) ($_POST['parent_id'] ?? 0);

        $fields = [
            'status' => (string) ($_POST['status'] ?? 'draft'),
            'role' => in_array($role, Pages::ROLES, true) ? $role : null,
            'parent_id' => $parentId > 0 ? $parentId : null,
            'in_header' => isset($_POST['in_header']),
            'in_footer' => isset($_POST['in_footer']),
            'position' => (int) ($_POST['position'] ?? 0),
        ];

        // Only the language the form is in is actually edited (see page_edit.twig);
        // every other enabled language keeps its existing text untouched, instead
        // of being wiped because the form never carried its fields at all.
        $existing = $id !== null ? $this->app->pages->find($id) : null;
        $currentLocale = $this->app->translator->locale();

        $translations = [];
        foreach ($this->app->locales->enabled() as $locale) {
            if ($locale === $currentLocale) {
                $input = is_array($_POST['text'][$locale] ?? null) ? $_POST['text'][$locale] : [];
                $translations[$locale] = [
                    'title' => (string) ($input['title'] ?? ''),
                    'slug' => (string) ($input['slug'] ?? ''),
                    'meta_description' => (string) ($input['meta_description'] ?? ''),
                ];
                continue;
            }
            $existingText = $existing['translations'][$locale] ?? null;
            if ($existingText !== null) {
                $translations[$locale] = [
                    'title' => $existingText['title'],
                    'slug' => $existingText['slug'],
                    'meta_description' => (string) ($existingText['meta_description'] ?? ''),
                ];
            }
        }

        $result = $this->app->pages->save($id, $fields, $translations);

        if ($result['errors'] !== []) {
            // Show the form again with what was typed, not what is stored.
            $this->renderForm(
                ['id' => $id] + $fields + ['translations' => $translations],
                array_map(fn (array $error) => $this->trans($error['key'], $error['params']), $result['errors'])
            );
            return;
        }

        // A new page defaults to "draft" on the form (no option is marked as
        // chosen), which is easy to save without noticing - say which one it is.
        Session::flash('success', $this->trans($fields['status'] === 'published' ? 'core.admin.pages.saved_published' : 'core.admin.pages.saved_draft'));
        $this->redirect('/admin/pages/' . $result['id']);
    }

    public function delete(array $params): void
    {
        $this->app->pages->delete((int) $params['id']);
        Session::flash('success', $this->trans('core.admin.pages.deleted'));
        $this->redirect('/admin/pages');
    }

    /**
     * The content editor of a page, reached from the page itself in edit
     * mode - one form for every block, exactly like AdminHomeController::save(),
     * just scoped to this page's own blocks instead of the site-wide ones.
     */
    public function blocks(array $params): void
    {
        $id = (int) $params['id'];
        $pages = $this->app->pages;
        $extra = $this->app->pageBlockTypes();
        $blocks = $this->fromForm($pages->blocksFor($id, $extra));
        $action = (string) ($_POST['action'] ?? 'save');

        if ($action === 'add') {
            $type = (string) ($_POST['add_type'] ?? '');
            if (in_array($type, $this->blockTypes(), true)) {
                $blocks[] = HomeLayout::newBlock($type) + ($type === 'offers' ? ['settings' => ['count' => 6]] : []);
            }
        } elseif (preg_match('/^insert:([a-z0-9-]{1,32})$/', $action, $match) === 1) {
            $choice = (string) ($_POST['add_type'] ?? '');
            if (str_starts_with($choice, 'widget:')) {
                $widget = $this->app->widgets->block(substr($choice, 7));
                if ($widget !== null) {
                    $blocks = HomeLayout::placeAfter($blocks, $match[1], $widget);
                }
            } elseif (in_array($choice, $this->blockTypes(), true)) {
                $blocks = HomeLayout::insertAfter($blocks, $match[1], $choice);
            }
        } elseif (preg_match('/^savewidget:([a-z0-9-]{1,32})$/', $action, $match) === 1) {
            foreach ($blocks as $block) {
                if ($block['id'] === $match[1]) {
                    $saved = $this->app->widgets->saveOwn((string) ($_POST['widget_name'] ?? ''), $block);
                    Session::flash($saved ? 'success' : 'error', $this->trans($saved ? 'core.widget.saved' : 'core.widget.invalid_name'));
                    break;
                }
            }
        } elseif (preg_match('/^removewidget:([a-z0-9]{1,16})$/', $action, $match) === 1) {
            $this->app->widgets->removeOwn($match[1]);
            Session::flash('success', $this->trans('core.widget.removed'));
        } elseif (preg_match('/^(up|down|delete|toggle|duplicate):([a-z0-9-]{1,32})$/', $action, $match) === 1) {
            $blocks = $this->step($blocks, $match[1], $match[2]);
        }

        $pages->saveBlocks($id, $blocks, $extra);
        if (!str_contains($action, 'widget')) {
            Session::flash('success', $this->trans($action === 'save' ? 'core.admin.pages.content_saved' : 'core.home.changed'));
        }
        $this->redirect($this->safeReturn('/admin/pages/' . $id . '?edit=1'));
    }

    /** The order of a page's blocks as dragged, with JavaScript - mirrors AdminHomeController::order(). */
    public function blockOrder(array $params): void
    {
        $id = (int) $params['id'];
        $data = json_decode((string) file_get_contents('php://input'), true);
        $ids = is_array($data) && is_array($data['order'] ?? null) ? $data['order'] : null;
        if ($ids === null || array_filter($ids, fn ($i) => !is_string($i) || preg_match('/^[a-z0-9-]{1,32}$/', $i) !== 1) !== []) {
            $this->json(['ok' => false], 422);
            return;
        }

        $extra = $this->app->pageBlockTypes();
        $this->app->pages->saveBlocks($id, HomeLayout::ordered($this->app->pages->blocksFor($id, $extra), $ids), $extra);
        $this->json(['ok' => true]);
    }

    /** A text field of a block, saved from the page itself with JavaScript - mirrors AdminHomeController::field(). */
    public function blockField(array $params): void
    {
        $data = json_decode((string) file_get_contents('php://input'), true);
        $locale = is_array($data) && is_string($data['locale'] ?? null) ? $data['locale'] : '';
        if (!is_array($data) || !$this->app->locales->isEnabled($locale) || !is_string($data['block'] ?? null)
            || !is_string($data['field'] ?? null) || !is_string($data['value'] ?? null)) {
            $this->json(['ok' => false], 422);
            return;
        }

        $clean = $this->app->pages->setBlockField((int) $params['id'], $data['block'], $locale, $data['field'], $data['value'], $this->app->pageBlockTypes());
        if ($clean === null) {
            $this->json(['ok' => false], 422);
            return;
        }
        $this->json(['ok' => true, 'value' => $clean]);
    }

    /**
     * The stored blocks with the values of the form on them - identical to
     * AdminHomeController::fromForm(), see there for the field-by-field comment.
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

            $locale = $this->app->translator->locale();
            $texts = $block['texts'];
            foreach (HomeLayout::textFields($this->app->pageBlockTypes())[$type] as $field) {
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

            $backgroundType = (string) ($input['settings']['background_type'] ?? '');
            if (in_array($backgroundType, ['color', 'gradient', 'image'], true)) {
                $settings['background_type'] = $backgroundType;
                if ($backgroundType !== 'image') {
                    $colour = strtolower(trim((string) ($input['settings']['background_color'] ?? '')));
                    if (HomeLayout::validColour($colour)) {
                        $settings['background_color'] = $colour;
                    }
                }
                if ($backgroundType === 'gradient') {
                    $colour2 = strtolower(trim((string) ($input['settings']['background_color2'] ?? '')));
                    if (HomeLayout::validColour($colour2)) {
                        $settings['background_color2'] = $colour2;
                    }
                }
                if ($backgroundType === 'image') {
                    $settings['background_media'] = HomeLayout::cleanMedia((string) ($input['settings']['background_media'] ?? ''));
                }
            }

            $blocks[] = ['id' => $id, 'type' => $type, 'enabled' => isset($input['enabled']), 'texts' => $texts, 'settings' => $settings];
        }

        return $blocks;
    }

    /**
     * Moves a block one place up or down, or removes it - identical to
     * AdminHomeController::step().
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

    private function renderForm(?array $page, array $errors): void
    {
        $excludedId = $page['id'] ?? null;
        $excluded = $excludedId !== null ? [$excludedId, ...$this->app->pages->descendantIds((int) $excludedId)] : [];

        // Flattened in tree order (not plain position/id order), so siblings and their
        // children stay visually together in the "parent" dropdown's indentation.
        $flatten = function (array $nodes) use (&$flatten, $excluded): array {
            $out = [];
            foreach ($nodes as $node) {
                if (!in_array($node['id'], $excluded, true)) {
                    $out[] = $node;
                    $out = [...$out, ...$flatten($node['children'])];
                }
            }

            return $out;
        };

        $this->render('@admin/page_edit.twig', [
            'page' => $page,
            'errors' => $errors,
            'locales' => $this->app->locales->enabled(),
            'default_locale' => $this->app->locales->default(),
            'roles' => Pages::ROLES,
            'parent_options' => array_map(
                fn (array $candidate) => $candidate + ['indent' => str_repeat('— ', $this->app->pages->depth((int) $candidate['id']))],
                $flatten($this->app->pages->tree())
            ),
        ]);
    }
}
