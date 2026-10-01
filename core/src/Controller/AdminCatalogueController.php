<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\App;
use Modulento\Core\Catalogue\OfferImages;
use Modulento\Core\Catalogue\Offers;
use Modulento\Core\Event\OfferStatusChanged;
use Modulento\Core\Support\Session;

/** Moderation of offers, and the category tree. */
final class AdminCatalogueController extends Controller
{
    private const PER_PAGE = 50;
    /** Form action => resulting status. */
    private const DECISIONS = ['approve' => 'published', 'reject' => 'rejected'];

    public function offers(array $params): void
    {
        $status = in_array($_GET['status'] ?? '', Offers::STATUSES, true) ? $_GET['status'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = $this->app->offers->listAll(null, $status, $page, self::PER_PAGE);
        $locale = $this->app->translator->locale();

        $this->render('@admin/offers.twig', [
            'offers' => array_map(fn (array $offer) => $offer + ['title' => $this->app->offers->text($offer, $locale)['title'] ?? ''], $list['rows']),
            'counts' => $this->app->offers->counts(),
            'status' => $status,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
            'approval_required' => $this->app->offers->approvalRequired(),
        ]);
    }

    public function offer(array $params): void
    {
        $app = $this->app;
        $offer = $app->offers->find((int) $params['id']);
        if ($offer === null) {
            $this->redirect('/admin/offers');
            return;
        }

        $locale = $app->translator->locale();
        $type = $app->offers->type($offer['type']);
        $category = $offer['category_id'] !== null ? $app->categories->find($offer['category_id']) : null;

        $this->render('@admin/offer.twig', [
            'offer' => $offer + [
                'title' => $app->offers->text($offer, $locale)['title'] ?? '',
                'image_urls' => array_map([OfferImages::class, 'urls'], $offer['images']),
                'category' => $category !== null ? $app->categories->view($category, $locale) : null,
                'changed_since_decision' => $offer['decided_at'] !== null && $offer['updated_at'] > $offer['decided_at'],
            ],
            'type_label_key' => $type?->labelKey(),
            'type_template' => $type?->detailTemplate(),
            'type_data' => $type?->detailData($offer['id'], $locale, $app) ?? [],
        ]);
    }

    public function decide(array $params): void
    {
        $offer = $this->app->offers->find((int) $params['id']);
        $status = self::DECISIONS[$_POST['decision'] ?? ''] ?? null;
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($offer === null || $status === null) {
            $this->redirect('/admin/offers');
            return;
        }
        if ($status === 'rejected' && $note === '') {
            Session::flash('error', $this->trans('core.admin.providers.error.note'));
            $this->redirect('/admin/offers/' . $offer['id']);
            return;
        }

        $this->app->offers->setStatus($offer['id'], $status, $status === 'rejected' ? $note : null, $this->app->auth->account()['id']);
        self::announce($this->app, $offer, $status, $note);

        Session::flash('success', $this->trans('core.admin.offers.decided.' . $status));
        $this->redirect('/admin/offers/' . $offer['id']);
    }

    /** Tells extensions and, by mail in their own language, the provider. */
    public static function announce(App $app, array $offer, string $status, string $note = ''): void
    {
        $app->events->dispatch(new OfferStatusChanged($offer['id'], $offer['provider_id'], $offer['status'], $status));

        $locale = $app->locales->isEnabled($offer['account_locale']) ? $offer['account_locale'] : $app->locales->default();
        $text = $app->offers->text($offer, $locale);

        $app->mailer->send($offer['account_email'], 'emails/offer_' . $status . '.txt.twig', [
            'title' => $text['title'] ?? '',
            'note' => $note,
            'edit_link' => $app->url('/account/offers/' . $offer['id'], $locale, true),
            'public_link' => $app->url('/offers/' . ($text['slug'] ?? ''), $locale, true),
        ], $locale);
    }

    // --- Categories -----------------------------------------------------------

    public function categories(array $params): void
    {
        $locale = $this->app->translator->locale();

        $this->render('@admin/categories.twig', ['tree' => $this->app->categories->tree($locale)]);
    }

    public function editCategory(array $params): void
    {
        $category = isset($params['id']) ? $this->app->categories->find((int) $params['id']) : null;
        if (isset($params['id']) && $category === null) {
            $this->redirect('/admin/categories');
            return;
        }

        $this->renderCategory($category, []);
    }

    public function saveCategory(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : null;
        $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;
        $position = (int) ($_POST['position'] ?? 0);

        $translations = [];
        foreach ($this->app->locales->enabled() as $locale) {
            $input = is_array($_POST['text'][$locale] ?? null) ? $_POST['text'][$locale] : [];
            $translations[$locale] = ['name' => (string) ($input['name'] ?? ''), 'slug' => (string) ($input['slug'] ?? '')];
        }

        $result = $this->app->categories->save($id, $parentId, $position, $translations);

        if ($result['errors'] !== []) {
            $this->renderCategory(
                ['id' => $id, 'parent_id' => $parentId, 'position' => $position, 'translations' => $translations],
                array_map(fn (array $error) => $this->trans($error['key'], $error['params']), $result['errors'])
            );
            return;
        }

        Session::flash('success', $this->trans('core.admin.categories.saved'));
        $this->redirect('/admin/categories');
    }

    public function deleteCategory(array $params): void
    {
        $deleted = $this->app->categories->delete((int) $params['id']);
        Session::flash($deleted ? 'success' : 'error', $this->trans($deleted ? 'core.admin.categories.deleted' : 'core.admin.categories.error.has_children'));
        $this->redirect('/admin/categories');
    }

    private function renderCategory(?array $category, array $errors): void
    {
        $locale = $this->app->translator->locale();
        // Only top categories can be parents, and not the category itself.
        $parents = array_filter(
            $this->app->categories->tree($locale),
            fn (array $top) => $top['id'] !== ($category['id'] ?? null)
        );

        $this->render('@admin/category_edit.twig', [
            'category' => $category,
            'parents' => array_values($parents),
            'locales' => $this->app->locales->enabled(),
            'errors' => $errors,
        ]);
    }
}
