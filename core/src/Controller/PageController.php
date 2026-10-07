<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Content\Pages;
use Modulento\Core\Media\Library;
use Modulento\Core\Support\Session;

final class PageController extends Controller
{
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
                'body' => $found['text']['body'],
                'meta_description' => $found['text']['meta_description'],
                'locale' => $found['text_locale'],
                'role' => $found['page']['role'],
            ],
        ]);
    }

    public function index(array $params): void
    {
        $this->render('@admin/pages.twig', [
            'pages' => array_values($this->app->pages->all()),
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

        $fields = [
            'status' => (string) ($_POST['status'] ?? 'draft'),
            'role' => in_array($role, Pages::ROLES, true) ? $role : null,
            'in_header' => isset($_POST['in_header']),
            'in_footer' => isset($_POST['in_footer']),
            'position' => (int) ($_POST['position'] ?? 0),
        ];

        $translations = [];
        foreach ($this->app->locales->enabled() as $locale) {
            $input = is_array($_POST['text'][$locale] ?? null) ? $_POST['text'][$locale] : [];
            $translations[$locale] = [
                'title' => (string) ($input['title'] ?? ''),
                'slug' => (string) ($input['slug'] ?? ''),
                'meta_description' => (string) ($input['meta_description'] ?? ''),
                'body' => (string) ($input['body'] ?? ''),
            ];
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

    private function renderForm(?array $page, array $errors): void
    {
        $this->render('@admin/page_edit.twig', [
            'page' => $page,
            'errors' => $errors,
            'locales' => $this->app->locales->enabled(),
            'roles' => Pages::ROLES,
            // The pictures a text can show, for the picker next to each text field.
            'media' => $this->app->auth->can('core.media.manage')
                ? array_map(fn (array $row) => ['url' => Library::url($row['file']), 'title' => $row['title'], 'width' => $row['width'], 'height' => $row['height']],
                    $this->app->media->list(1, 60)['rows'])
                : [],
        ]);
    }
}
