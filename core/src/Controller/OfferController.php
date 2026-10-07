<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Catalogue\OfferImages;
use Modulento\Core\Catalogue\OfferType;
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
    private const MAX_OFFERS = 100;
    private const MAX_OFFERS_UNAPPROVED = 3;

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

        // Remembered in the visitor's own session for "viewed recently".
        $recent = array_values(array_filter((array) Session::get('recent_offers', []), fn ($id) => $id !== $offer['id']));
        Session::set('recent_offers', array_slice([$offer['id'], ...$recent], 0, 8));

        $text = $app->offers->text($offer, $locale);
        foreach ($app->locales->enabled() as $other) {
            $app->alternatePaths[$other] = '/offers/' . ($offer['texts'][$other]['slug'] ?? $text['slug']);
        }

        $type = $app->offers->type($offer['type']);
        $category = $offer['category_id'] !== null ? $app->categories->find($offer['category_id']) : null;
        $account = $app->auth->account();
        if ($account !== null) {
            // Opening the offer marks its threads read: the provider's all, a visitor's own.
            if ($account['id'] === $offer['account_id']) {
                foreach ($app->offerMessages->threads($offer['id']) as $thread) {
                    $app->messageSeen->markThread($account['id'], $offer['id'], $thread['asker_id']);
                }
            } else {
                $app->messageSeen->markThread($account['id'], $offer['id'], $account['id']);
            }
        }

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
                'id' => $offer['id'],
                'is_own' => $account !== null && $account['id'] === $offer['account_id'],
                // Whether an extension registered a way to order this type
                // through the order form.
                'orderable' => $app->orders->flowForOfferType($offer['type'])?->checkout() ?? false,
                'rating' => Reviews::summary($offer),
            ],
            'type_template' => $type->detailTemplate(),
            'type_data' => $type->detailData($offer['id'], $locale, $app),
            'reviews' => ReviewView::all($app->reviews->listPublic('offer', $offer['id'], 1, 20)['rows']),
            'messages' => $this->offerMessages($offer, $account),
        ]);
    }

    /**
     * What the visitor may see of the messages about this offer: the provider
     * sees every thread, another logged-in visitor only their own.
     *
     * @return array{threads: list<array<string, mixed>>, thread: list<array<string, string>>}
     */
    private function offerMessages(array $offer, ?array $account): array
    {
        $app = $this->app;
        if ($account === null || !$app->modules->enabled('contact')) {
            return ['threads' => [], 'thread' => []];
        }

        if ($account['id'] === $offer['account_id']) {
            return ['threads' => array_map(fn (array $thread) => [
                'asker_id' => $thread['asker_id'],
                'asker_name' => $thread['asker_name'] ?: $this->trans('core.offer.contact.asker'),
                'messages' => $this->messageViews($thread['messages'], $account['id'], $offer['account_id']),
            ], $app->offerMessages->threads($offer['id'])), 'thread' => []];
        }

        return [
            'threads' => [],
            'thread' => $this->messageViews($app->offerMessages->thread($offer['id'], $account['id']), $account['id'], $offer['account_id']),
        ];
    }

    /** @return list<array{body: string, created_at: string, label: string, mine: bool, hidden: bool}> */
    private function messageViews(array $rows, int $viewer, int $provider): array
    {
        return array_map(fn (array $row) => [
            'body' => $row['body'],
            'created_at' => $row['created_at'],
            'label' => $this->trans(match (true) {
                (int) $row['author_id'] === $viewer => 'core.offer.contact.you',
                (int) $row['author_id'] === $provider => 'core.offer.contact.provider',
                default => 'core.offer.contact.asker',
            }),
            'mine' => (int) $row['author_id'] === $viewer,
            // Hidden by an administrator - see AdminMessageController; the
            // content still exists, just not shown to the two parties.
            'hidden' => ($row['status'] ?? 'visible') === 'hidden',
        ], $rows);
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

        // Kept on the site as well: the provider answers in the thread, and
        // the e-mail tells them. The word filter no longer refuses this -
        // it only flags it for an administrator to decide.
        $app->offerMessages->add($offer['id'], $account['id'], $account['id'], $message, $app->badWords->find($message));
        $locale = $app->locales->isEnabled($offer['account_locale']) ? $offer['account_locale'] : $app->locales->default();
        $text = $app->offers->text($offer, $locale);

        $app->mailer->send($offer['account_email'], 'emails/offer_contact.txt.twig', [
            'title' => $text['title'],
            'link' => $app->url('/offers/' . $text['slug'], $locale, true),
            'sender' => $account['display_name'] ?: $account['email'],
            'sender_email' => $account['email'],
            'message' => $message,
        ], $locale, $account['email']);
        $app->notifications->create((int) $offer['account_id'], 'offer_message', 'core.notification.offer_message', ['title' => $text['title']], '/offers/' . $text['slug']);

        Session::flash('success', $this->trans('core.offer.contact.sent'));
        $this->redirect($path);
    }

    /** The provider answers in the thread of one visitor; the visitor gets an e-mail. */
    public function reply(array $params): void
    {
        $app = $this->app;
        $offer = $app->offers->findPublicBySlug($app->translator->locale(), $params['slug']);
        if ($offer === null) {
            $this->notFound();
            return;
        }

        $account = $app->auth->account();
        $asker = (int) $params['asker'];
        $path = '/offers/' . $params['slug'];
        $message = trim(str_replace("\r\n", "\n", (string) ($_POST['message'] ?? '')));

        if ($account['id'] !== $offer['account_id'] || $app->offerMessages->thread($offer['id'], $asker) === []) {
            Session::flash('error', $this->trans('core.offer.contact.error'));
            $this->redirect($path);
            return;
        }
        if (mb_strlen($message) < 1 || mb_strlen($message) > 3000) {
            Session::flash('error', $this->trans('core.offer.contact.reply_error'));
        } elseif ((new RateLimiter($app->db))->hit('offer-reply', (string) $account['id'], 60, 3600)) {
            Session::flash('error', $this->trans('core.error.too_many_requests'));
        } else {
            // The word filter no longer refuses this - it only flags it.
            $app->offerMessages->add($offer['id'], $asker, $account['id'], $message, $app->badWords->find($message));
            $recipient = $app->accounts->findById($asker);
            if ($recipient !== null) {
                $locale = $app->locales->isEnabled($recipient['locale']) ? $recipient['locale'] : $app->locales->default();
                $text = $app->offers->text($offer, $locale);
                $app->mailer->send($recipient['email'], 'emails/offer_reply.txt.twig', [
                    'title' => $text['title'],
                    'link' => $app->url('/offers/' . $text['slug'], $locale, true),
                    'provider' => $account['display_name'] ?: $offer['provider_name'],
                    'message' => $message,
                ], $locale);
                $app->notifications->create((int) $recipient['id'], 'offer_reply', 'core.notification.offer_reply', ['title' => $text['title']], '/offers/' . $text['slug']);
            }
            Session::flash('success', $this->trans('core.offer.contact.replied'));
        }

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

        // A new offer is made in steps; the first one starts it from the beginning.
        $step = max(1, min(self::WIZARD_STEPS, (int) ($_GET['step'] ?? 1)));
        if ($step === 1) {
            Session::set('offer_wizard', ['type' => $type->id(), 'values' => []]);
        }
        $state = Session::get('offer_wizard');
        if (!is_array($state) || ($state['type'] ?? null) !== $type->id()) {
            $this->redirect('/account/offers/new?type=' . $type->id() . '&step=1');
            return;
        }

        $this->renderWizard($type, $step, $state['values'] ?? [], []);
    }

    /**
     * One step of making a new offer: what it asks is kept in the session. The last
     * step saves everything at once, through the same save as the form for an offer.
     */
    public function wizard(array $params): void
    {
        $app = $this->app;
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }

        $type = $app->offers->type((string) ($_POST['type'] ?? ''));
        if ($type === null) {
            $this->redirect('/account/offers');
            return;
        }
        $state = Session::get('offer_wizard');
        if (!is_array($state) || ($state['type'] ?? null) !== $type->id()) {
            $this->redirect('/account/offers/new?type=' . $type->id() . '&step=1');
            return;
        }

        $step = max(1, min(self::WIZARD_STEPS - 1, (int) ($_POST['wizard_step'] ?? 1)));
        $primary = $app->translator->locale();
        $values = is_array($state['values'] ?? null) ? $state['values'] : [];
        $errors = [];

        switch ($step) {
            case 1:
                $chosen = is_array($_POST['locales'] ?? null) ? array_filter($_POST['locales'], 'is_string') : [];
                $values['locales'] = array_values(array_unique([$primary, ...array_intersect($app->locales->enabled(), $chosen)]));
                break;
            case 2:
                $category = (int) ($_POST['category_id'] ?? 0);
                if (!array_key_exists($category, $app->categories->all())) {
                    $errors[] = 'core.offer.error.category';
                }
                $values['category_id'] = $category;
                break;
            case 3:
            case 4:
                $fields = $step === 3 ? ['title', 'summary'] : ['description'];
                foreach ($values['locales'] ?? [$primary] as $code) {
                    $input = is_array($_POST['text'][$code] ?? null) ? $_POST['text'][$code] : [];
                    foreach ($fields as $field) {
                        $values['text'][$code][$field] = trim(is_string($input[$field] ?? null) ? $input[$field] : '');
                    }
                }
                if ($step === 3 && trim((string) ($values['text'][$primary]['title'] ?? '')) === '') {
                    $errors[] = 'core.offer.error.text';
                }
                break;
            case 5:
                // Whatever the offer's type asks for, under the names its form uses.
                $values['type_fields'] = array_diff_key($_POST, array_flip(['type', 'wizard_step', '_csrf', 'category_id', 'text']));
                break;
        }

        Session::set('offer_wizard', ['type' => $type->id(), 'values' => $values]);
        if ($errors !== []) {
            $this->renderWizard($type, $step, $values, array_map(fn (string $key) => $this->trans($key), array_unique($errors)));
            return;
        }

        $this->redirect('/account/offers/new?type=' . $type->id() . '&step=' . ($step + 1));
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

        // Offers take room and an administrator's time; a profile nobody
        // has looked at yet gets only a few.
        $max = $provider['status'] === 'approved' ? self::MAX_OFFERS : self::MAX_OFFERS_UNAPPROVED;
        $problem = match (true) {
            $offer === null && $app->offers->listAll((int) $provider['id'], null, 1, 1)['total'] >= $max => $this->trans('core.offer.error.too_many', ['max' => $max]),
            (new RateLimiter($app->db))->hit('offer-save', (string) $provider['account_id'], 60, 3600) => $this->trans('core.error.too_many_requests'),
            default => null,
        };
        if ($problem !== null) {
            Session::flash('error', $problem);
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

        if ($offer === null) {
            Session::remove('offer_wizard');
        }
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

        $problem = (new RateLimiter($this->app->db))->hit('offer-image', (string) $offer['account_id'], 60, 3600)
            ? 'core.error.too_many_requests'
            : $this->app->offerImages->add($offer['id'], is_array($_FILES['image'] ?? null) ? $_FILES['image'] : []);
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

    /** The steps of making a new offer: the languages, the category, the texts, the type's own fields, and a review. */
    private const WIZARD_STEPS = 6;

    /** @param array<string, mixed> $values what the steps so far have asked for */
    private function renderWizard(OfferType $type, int $step, array $values, array $errors): void
    {
        $app = $this->app;
        $primary = $app->translator->locale();
        $selected = $values['locales'] ?? [$primary];
        $texts = [];
        foreach ($selected as $code) {
            $texts[$code] = $values['text'][$code] ?? [];
        }

        $this->render('account/offer_wizard.twig', [
            'type' => ['id' => $type->id(), 'label_key' => $type->labelKey(), 'template' => $type->formTemplate()],
            'step' => $step,
            'steps' => self::WIZARD_STEPS,
            'primary' => $primary,
            'locales' => $app->locales->enabled(),
            'selected' => $selected,
            'texts' => $texts,
            'category_id' => (int) ($values['category_id'] ?? 0),
            'categories' => $app->categories->tree($primary),
            'category_name' => $this->categoryName((int) ($values['category_id'] ?? 0), $primary),
            'type_data' => $step === 5 ? $type->formData(null, $values['type_fields'] ?? null, $app) : [],
            // The answers as one form's fields, for the review: the last save reads them as any form.
            'answers' => $step === self::WIZARD_STEPS ? $this->answers($type->id(), $values) : [],
            'errors' => $errors,
        ]);
    }

    private function categoryName(int $id, string $locale): string
    {
        $category = $id > 0 ? $this->app->categories->find($id) : null;

        return $category !== null ? ($this->app->categories->view($category, $locale)['name'] ?? '') : '';
    }

    /** @return list<array{0: string, 1: string}> name and value of each answer, as a form would send them */
    private function answers(string $typeId, array $values): array
    {
        $pairs = [['type', $typeId]];
        if (isset($values['category_id'])) {
            $pairs[] = ['category_id', (string) $values['category_id']];
        }
        foreach ($values['text'] ?? [] as $code => $fields) {
            if (!in_array($code, $values['locales'] ?? [], true)) {
                continue;
            }
            foreach ($fields as $field => $value) {
                $pairs[] = ['text[' . $code . '][' . $field . ']', (string) $value];
            }
        }
        $walk = function (string $name, mixed $value) use (&$walk, &$pairs): void {
            if (is_array($value)) {
                foreach ($value as $key => $inner) {
                    $walk($name . '[' . $key . ']', $inner);
                }
                return;
            }
            $pairs[] = [$name, (string) $value];
        };
        foreach ($values['type_fields'] ?? [] as $key => $value) {
            $walk((string) $key, $value);
        }

        return $pairs;
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
