<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Catalogue\OfferView;
use Modulento\Core\Event\ProviderStatusChanged;
use Modulento\Core\Provider\Providers;
use Modulento\Core\Review\Reviews;
use Modulento\Core\Review\ReviewView;
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
            'providers' => array_map(fn (array $row) => $this->publicView($row), $list['rows']),
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

        $this->render('provider/show.twig', [
            'provider' => $this->publicView($provider),
            'offers' => OfferView::cards($offers['rows'], $this->app),
            'reviews' => ReviewView::all($this->app->reviews->listPublic('provider', (int) $provider['id'], 1, 20)['rows']),
        ]);
    }

    /**
     * What a template may show of a provider. A business is identified
     * with its legal details; of a private person only the name and the
     * place are public.
     */
    private function publicView(array $provider): array
    {
        $text = Providers::text($provider, $this->app->translator->locale(), $this->app->locales->default());
        $isBusiness = $provider['type'] === 'business';

        return [
            'name' => $provider['name'],
            'slug' => $provider['slug'],
            'path' => '/providers/' . $provider['slug'],
            'type' => $provider['type'],
            'headline' => $text['headline'] ?? '',
            'description' => $text['description'] ?? '',
            'city' => $provider['city'],
            'country' => $provider['country'],
            'rating' => Reviews::summary($provider),
            'legal' => $isBusiness ? [
                'legal_name' => $provider['legal_name'],
                'street' => $provider['street'],
                'postal_code' => $provider['postal_code'],
                'contact_email' => $provider['contact_email'],
                'phone' => $provider['phone'],
                'vat_id' => $provider['vat_id'],
                'company_register' => $provider['company_register'],
            ] : null,
        ];
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
