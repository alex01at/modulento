<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Catalogue\OfferView;
use Modulento\Core\Event\ProviderStatusChanged;
use Modulento\Core\Provider\ProviderView;
use Modulento\Core\Review\AccountRatingView;
use Modulento\Core\Review\ReviewView;
use Modulento\Core\Review\Reviews;
use Modulento\Core\Support\Countries;
use Modulento\Core\Support\Session;

final class ProviderController extends Controller
{
    private const PER_PAGE = 24;

    /** The logged-in account's own provider profile. */
    public function edit(array $params): void
    {
        $this->renderForm($this->app->providers->findByAccount($this->app->auth->account()['id']), []);
    }

    public function save(array $params): void
    {
        $providers = $this->app->providers;
        $accountId = $this->app->auth->account()['id'];
        $existing = $providers->findByAccount($accountId);

        $result = $providers->validate($_POST, alreadyCertified: ($existing['self_certified_at'] ?? null) !== null);

        if ($result['errors'] !== []) {
            // Show what was typed again, on top of what is stored.
            $typed = ['texts' => $result['texts']] + $result['values'] + ($existing ?? []);
            $this->renderForm($typed, array_map(fn (string $key) => $this->trans($key), $result['errors']), $existing);
            return;
        }

        $saved = $providers->save($accountId, $result['values'], $result['texts']);

        if ($saved['created'] || $saved['status'] !== ($existing['status'] ?? null)) {
            $this->app->events->dispatch(new ProviderStatusChanged($saved['id'], $accountId, $existing['status'] ?? null, $saved['status']));
        }

        Session::flash('success', $this->trans(match (true) {
            $saved['status'] === 'pending' => 'core.provider.saved_pending',
            default => 'core.provider.saved',
        }));
        $this->redirect('/account/provider');
    }

    /** Public directory of approved providers. */
    public function index(array $params): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = $this->app->providers->list(null, $page, self::PER_PAGE, publicOnly: true);

        $this->render('provider/index.twig', [
            'providers' => ProviderView::all($list['rows'], $this->app),
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
        ]);
    }

    public function show(array $params): void
    {
        $provider = $this->app->providers->findPublicBySlug($params['slug']);

        if ($provider === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $offers = $this->app->offers->listPublic(['provider_id' => (int) $provider['id']], $this->app->translator->locale(), 1, 48);
        // The account behind the profile - a direct rating rates that account, not the provider row.
        $ratedAccount = $this->app->accounts->findById((int) $provider['account_id']);

        $this->render('provider/show.twig', [
            'provider' => ProviderView::of($provider, $this->app),
            'offers' => OfferView::cards($offers['rows'], $this->app),
            'reviews' => ReviewView::all($this->app->reviews->listPublic('provider', (int) $provider['id'], 1, 20)['rows']),
            'account_rating' => Reviews::summary($ratedAccount ?? []),
            'account_ratings' => AccountRatingView::all($this->app->accountRatings->listPublic((int) $provider['account_id'], 1, 20)['rows']),
            // Templates extensions want shown on this provider's profile, e.g. a freelancer's skills and portfolio.
            'extension_blocks' => array_map(
                fn (array $section) => ['template' => $section['template'], 'data' => ($section['data'])($provider, $this->app)],
                $this->app->providerSections()
            ),
        ]);
    }

    private function renderForm(?array $provider, array $errors, ?array $stored = null): void
    {
        $stored ??= $provider;

        $this->render('account/provider.twig', [
            'provider' => $provider,
            'status' => $stored['status'] ?? null,
            'status_note' => $stored['status_note'] ?? null,
            'public_path' => ($stored['status'] ?? null) === 'approved' ? '/providers/' . $stored['slug'] : null,
            'certified' => ($stored['self_certified_at'] ?? null) !== null,
            'errors' => $errors,
            'locales' => $this->app->locales->enabled(),
            'countries' => Countries::CODES,
            'approval_required' => $this->app->providers->approvalRequired(),
        ]);
    }
}
