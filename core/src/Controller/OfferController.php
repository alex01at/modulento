<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Catalogue\OfferImages;
use Modulento\Core\Catalogue\Offers;
use Modulento\Core\Catalogue\OfferView;
use Modulento\Core\Event\OfferStatusChanged;
use Modulento\Core\Review\Reviews;
use Modulento\Core\Review\ReviewView;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;

/** The catalogue as visitors see it, and a provider's own offers. */
final class OfferController extends Controller
{
    private const PER_PAGE = 24;

    // --- Visitors ----------------------------------------------------------

    public function index(array $params): void
    {
        // A search form with a category select sends its id; the category
        // then has its own address, so the visitor is sent there.
        $category = isset($_GET['category']) ? $this->app->categories->find((int) $_GET['category']) : null;
        $view = $category !== null ? $this->app->categories->view($category, $this->app->translator->locale()) : null;

        if ($view !== null) {
            $query = http_build_query(array_filter(['q' => trim((string) ($_GET['q'] ?? ''))]));
            header('Location: ' . $this->app->url($view['path']) . ($query !== '' ? '?' . $query : ''));
            return;
        }

        $this->renderList(null);
    }

    public function category(array $params): void
    {
        $locale = $this->app->translator->locale();
        $category = $this->app->categories->findBySlug($locale, $params['slug']);

        if ($category === null) {
            $this->notFound();
            return;
        }

        foreach ($this->app->locales->enabled() as $other) {
            $view = $this->app->categories->view($category, $other);
            if ($view !== null) {
                $this->app->alternatePaths[$other] = $view['path'];
            }
        }

        $this->renderList($category);
    }

    private function renderList(?array $category): void
    {
        $locale = $this->app->translator->locale();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = in_array($_GET['sort'] ?? '', Offers::SORTS, true) ? $_GET['sort'] : 'newest';

        $list = $this->app->offers->listPublic([
            'search' => $search,
            'sort' => $sort,
            'category_ids' => $category !== null ? $this->app->categories->withChildren($category['id']) : [],
        ], $locale, $page, self::PER_PAGE);

        $this->render('offer/index.twig', [
            'offers' => OfferView::cards($list['rows'], $this->app),
            'total' => $list['total'],
            'categories' => $this->app->categories->tree($locale),
            'category' => $category !== null ? $this->app->categories->view($category, $locale) : null,
            'search' => $search,
            'sort' => $sort,
            'sorts' => Offers::SORTS,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
        ]);
    }

    public function show(array $params): void
    {
        $app = $this->app;
        $locale = $app->translator->locale();
        $offer = $app->offers->findPublicBySlug($locale, $params['slug']);

        if ($offer === null) {
            $this->notFound();
            return;
        }

        $text = $app->offers->text($offer, $locale);
        foreach ($app->locales->enabled() as $other) {
            $app->alternatePaths[$other] = '/offers/' . ($offer['texts'][$other]['slug'] ?? $text['slug']);
        }

        $type = $app->offers->type($offer['type']);
        $category = $offer['category_id'] !== null ? $app->categories->find($offer['category_id']) : null;
        $account = $app->auth->account();

        $this->render('offer/show.twig', [
            'offer' => [
                'title' => $text['title'],
                'summary' => $text['summary'],
                'description' => $text['description'],
                'path' => '/offers/' . $text['slug'],
                'price_from' => $offer['price_from'],
                'currency' => $offer['currency'],
                'images' => array_map([OfferImages::class, 'urls'], $offer['images']),
                'provider_name' => $offer['provider_name'],
                'provider_path' => '/providers/' . $offer['provider_slug'],
                'category' => $category !== null ? $app->categories->view($category, $locale) : null,
                'is_own' => $account !== null && $account['id'] === $offer['account_id'],
                // Whether an extension registered a way to order this type
                // through the order form.
                'orderable' => $app->orders->flowForOfferType($offer['type'])?->checkout() ?? false,
                'rating' => Reviews::summary($offer),
            ],
            'type_template' => $type->detailTemplate(),
            'type_data' => $type->detailData($offer['id'], $locale, $app),
            'reviews' => ReviewView::all($app->reviews->listPublic('offer', $offer['id'], 1, 20)['rows']),
        ]);
    }

    /** A logged-in visitor writes to the provider; the provider answers by e-mail. */
    public function contact(array $params): void
    {
        $app = $this->app;
        $offer = $app->offers->findPublicBySlug($app->translator->locale(), $params['slug']);
        if ($offer === null) {
            $this->notFound();
            return;
        }

        $account = $app->auth->account();
        $message = trim(str_replace("\r\n", "\n", (string) ($_POST['message'] ?? '')));
        $path = '/offers/' . $params['slug'];

        if ($account['id'] === $offer['account_id'] || mb_strlen($message) < 20 || mb_strlen($message) > 3000) {
            Session::flash('error', $this->trans('core.offer.contact.error'));
            $this->redirect($path);
            return;
        }
        if ((new RateLimiter($app->db))->hit('offer-contact', (string) $account['id'], 5, 3600)) {
            Session::flash('error', $this->trans('core.error.too_many_requests'));
            $this->redirect($path);
            return;
        }

        $locale = $app->locales->isEnabled($offer['account_locale']) ? $offer['account_locale'] : $app->locales->default();
        $text = $app->offers->text($offer, $locale);

        $app->mailer->send($offer['account_email'], 'emails/offer_contact.txt.twig', [
            'title' => $text['title'],
            'link' => $app->url('/offers/' . $text['slug'], $locale, true),
            'sender' => $account['display_name'] ?: $account['email'],
            'sender_email' => $account['email'],
            'message' => $message,
        ], $locale, $account['email']);

        Session::flash('success', $this->trans('core.offer.contact.sent'));
        $this->redirect($path);
    }

    // --- The provider's own offers ------------------------------------------

    public function mine(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }

        $list = $this->app->offers->listAll((int) $provider['id'], null, 1, 200);
        $locale = $this->app->translator->locale();

        $this->render('account/offers.twig', [
            'offers' => array_map(fn (array $offer) => [
                'id' => $offer['id'],
                'title' => $this->app->offers->text($offer, $locale)['title'] ?? '',
                'status' => $offer['status'],
                'status_note' => $offer['status_note'],
                'type_label' => $this->typeLabel($offer['type']),
                'public_path' => $this->app->offers->isPublic($offer) ? '/offers/' . $this->app->offers->text($offer, $locale)['slug'] : null,
            ], $list['rows']),
            'types' => array_map(fn ($type) => ['id' => $type->id(), 'label_key' => $type->labelKey()], array_values($this->app->offers->types())),
            'provider_status' => $provider['status'],
        ]);
    }

    public function create(array $params): void
    {
        $provider = $this->provider();
        $type = $this->app->offers->type((string) ($_GET['type'] ?? ''));

        if ($provider === null) {
            return;
        }
        if ($type === null) {
            $this->redirect('/account/offers');
            return;
        }

        $this->renderForm(null, $type->id(), null, []);
    }

    public function edit(array $params): void
    {
        $offer = $this->ownOffer($params);
        if ($offer === null) {
            return;
        }

        $this->renderForm($offer, $offer['type'], null, []);
    }

    public function save(array $params): void
    {
        $app = $this->app;
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }

        $offer = isset($params['id']) ? $this->ownOffer($params) : null;
        if (isset($params['id']) && $offer === null) {
            return;
        }

        $typeId = $offer['type'] ?? (string) ($_POST['type'] ?? '');
        $type = $app->offers->type($typeId);
        if ($type === null) {
            $this->redirect('/account/offers');
            return;
        }

        $shared = $app->offers->validate($_POST, array_keys($app->categories->all()));
        $specific = $type->validate($_POST, $offer['id'] ?? null, $app);
        $errors = array_merge($shared['errors'], $specific['errors']);

        if ($errors !== []) {
            $this->renderForm($offer, $typeId, $_POST, array_map(fn (string $key) => $this->trans($key), array_unique($errors)));
            return;
        }

        $id = $app->offers->save($offer['id'] ?? null, (int) $provider['id'], $typeId, $shared['category_id'], $shared['texts']);
        $app->offers->setPriceFrom($id, $type->save($id, $specific['values'], $app));

        Session::flash('success', $this->trans($offer === null ? 'core.offer.saved_new' : 'core.offer.saved'));
        $this->redirect('/account/offers/' . $id);
    }

    /** From draft or rejected: asks for publication. */
    public function submit(array $params): void
    {
        $offer = $this->ownOffer($params);
        if ($offer === null) {
            return;
        }

        if (!in_array($offer['status'], ['draft', 'rejected'], true)) {
            $this->redirect('/account/offers/' . $offer['id']);
            return;
        }

        $status = $this->app->offers->approvalRequired() ? 'pending' : 'published';
        $this->changeStatus($offer, $status, $status === 'pending' ? 'core.offer.submitted' : 'core.offer.published');
    }

    public function pause(array $params): void
    {
        $offer = $this->ownOffer($params);
        if ($offer !== null && $offer['status'] === 'published') {
            $this->changeStatus($offer, 'paused', 'core.offer.paused');
        } elseif ($offer !== null) {
            $this->redirect('/account/offers/' . $offer['id']);
        }
    }

    /** Only an offer the provider paused comes back without a new review. */
    public function resume(array $params): void
    {
        $offer = $this->ownOffer($params);
        if ($offer !== null && $offer['status'] === 'paused') {
            $this->changeStatus($offer, 'published', 'core.offer.published');
        } elseif ($offer !== null) {
            $this->redirect('/account/offers/' . $offer['id']);
        }
    }

    public function delete(array $params): void
    {
        $offer = $this->ownOffer($params);
        if ($offer === null) {
            return;
        }

        $this->app->offerImages->deleteAll($offer['id']);
        $this->app->offers->delete($offer['id']);
        $this->app->events->dispatch(new OfferStatusChanged($offer['id'], $offer['provider_id'], $offer['status'], null));

        Session::flash('success', $this->trans('core.offer.deleted'));
        $this->redirect('/account/offers');
    }

    public function uploadImage(array $params): void
    {
        $offer = $this->ownOffer($params);
        if ($offer === null) {
            return;
        }

        $problem = $this->app->offerImages->add($offer['id'], is_array($_FILES['image'] ?? null) ? $_FILES['image'] : []);
        Session::flash($problem === null ? 'success' : 'error', $this->trans($problem ?? 'core.offer.image.added', [
            'max' => OfferImages::MAX_PER_OFFER,
            'megabytes' => intdiv(OfferImages::MAX_BYTES, 1024 * 1024),
        ]));
        $this->redirect('/account/offers/' . $offer['id']);
    }

    public function deleteImage(array $params): void
    {
        $offer = $this->ownOffer($params);
        if ($offer === null) {
            return;
        }

        $this->app->offerImages->delete($offer['id'], (int) $params['image']);
        $this->redirect('/account/offers/' . $offer['id']);
    }

    private function changeStatus(array $offer, string $status, string $messageKey): void
    {
        $this->app->offers->setStatus($offer['id'], $status, null, null);
        $this->app->events->dispatch(new OfferStatusChanged($offer['id'], $offer['provider_id'], $offer['status'], $status));

        Session::flash('success', $this->trans($messageKey));
        $this->redirect('/account/offers/' . $offer['id']);
    }

    /** The logged-in account's provider profile; without one, offers make no sense yet. */
    private function provider(): ?array
    {
        $provider = $this->app->providers->findByAccount($this->app->auth->account()['id']);

        if ($provider === null) {
            Session::flash('error', $this->trans('core.offer.needs_provider'));
            $this->redirect('/account/provider');
        }

        return $provider;
    }

    /** The offer from the address, if it belongs to the logged-in account. Anyone else gets a 404. */
    private function ownOffer(array $params): ?array
    {
        $offer = $this->app->offers->find((int) $params['id']);

        if ($offer === null || $offer['account_id'] !== $this->app->auth->account()['id']) {
            $this->notFound();
            return null;
        }

        return $offer;
    }

    private function typeLabel(string $typeId): string
    {
        $type = $this->app->offers->type($typeId);

        return $type !== null ? $this->trans($type->labelKey()) : $typeId;
    }

    /** @param array<string, mixed>|null $typed the form as sent, after a failed validation */
    private function renderForm(?array $offer, string $typeId, ?array $typed, array $errors): void
    {
        $app = $this->app;
        $type = $app->offers->type($typeId);

        if ($type === null) {
            // The extension of this offer's type is switched off.
            Session::flash('error', $this->trans('core.offer.type_unavailable'));
            $this->redirect('/account/offers');
            return;
        }

        $texts = $offer['texts'] ?? [];
        if ($typed !== null) {
            $texts = is_array($typed['text'] ?? null) ? $typed['text'] : [];
        }

        $this->render('account/offer_edit.twig', [
            'offer' => $offer !== null ? [
                'id' => $offer['id'],
                'status' => $offer['status'],
                'status_note' => $offer['status_note'],
                'images' => array_map([OfferImages::class, 'urls'], $offer['images']),
                'public_path' => $app->offers->isPublic($offer) ? '/offers/' . $app->offers->text($offer, $app->translator->locale())['slug'] : null,
            ] : null,
            'type' => ['id' => $typeId, 'label_key' => $type->labelKey(), 'template' => $type->formTemplate()],
            'type_data' => $type->formData($offer['id'] ?? null, $typed, $app),
            'texts' => $texts,
            'category_id' => (int) ($typed['category_id'] ?? $offer['category_id'] ?? 0),
            'categories' => $app->categories->tree($app->translator->locale()),
            'locales' => $app->locales->enabled(),
            'errors' => $errors,
            'approval_required' => $app->offers->approvalRequired(),
            'images_available' => OfferImages::available(),
            'max_images' => OfferImages::MAX_PER_OFFER,
            'currency' => $app->offers->currency(),
        ]);
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
    }
}
