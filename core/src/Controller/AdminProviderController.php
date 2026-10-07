<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\App;
use Modulento\Core\Event\ProviderStatusChanged;
use Modulento\Core\Provider\Providers;
use Modulento\Core\Support\Session;

final class AdminProviderController extends Controller
{
    private const PER_PAGE = 50;
    /** Form action => resulting status. */
    private const DECISIONS = ['approve' => 'approved', 'reject' => 'rejected', 'suspend' => 'suspended'];

    public function index(array $params): void
    {
        $status = in_array($_GET['status'] ?? '', Providers::STATUSES, true) ? $_GET['status'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = $this->app->providers->list($status, $page, self::PER_PAGE);

        $this->render('@admin/providers.twig', [
            'providers' => $list['rows'],
            'counts' => $this->app->providers->counts(),
            'status' => $status,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
            'approval_required' => $this->app->providers->approvalRequired(),
        ]);
    }

    public function show(array $params): void
    {
        $provider = $this->app->providers->find((int) $params['id']);
        if ($provider === null) {
            $this->redirect('/admin/providers');
            return;
        }

        $this->render('@admin/provider.twig', ['provider' => $provider]);
    }

    public function decide(array $params): void
    {
        $provider = $this->app->providers->find((int) $params['id']);
        $status = self::DECISIONS[$_POST['decision'] ?? ''] ?? null;
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($provider === null || $status === null) {
            $this->redirect('/admin/providers');
            return;
        }

        // Whoever is refused or taken offline is told why.
        if ($status !== 'approved' && $note === '') {
            Session::flash('error', $this->trans('core.admin.providers.error.note'));
            $this->redirect('/admin/providers/' . $provider['id']);
            return;
        }

        $this->app->providers->setStatus((int) $provider['id'], $status, $status === 'approved' ? null : $note, $this->app->auth->account()['id']);
        self::announce($this->app, $provider, $status, $note);

        Session::flash('success', $this->trans('core.admin.providers.decided.' . $status, ['name' => $provider['name']]));
        $this->redirect('/admin/providers/' . $provider['id']);
    }

    /** Tells extensions and, by mail in their own language, the provider. */
    public static function announce(App $app, array $provider, string $status, string $note = ''): void
    {
        $app->events->dispatch(new ProviderStatusChanged((int) $provider['id'], (int) $provider['account_id'], $provider['status'], $status));

        $locale = $app->locales->isEnabled($provider['account_locale']) ? $provider['account_locale'] : $app->locales->default();
        $app->mailer->send($provider['account_email'], 'emails/provider_' . $status . '.txt.twig', [
            'name' => $provider['name'],
            'note' => $note,
            'profile_link' => $app->url('/account/provider', $locale, true),
            'public_link' => $app->url('/providers/' . $provider['slug'], $locale, true),
        ], $locale);
        // Reuses the existing, already-written status sentence as the notification text.
        $app->notifications->create((int) $provider['account_id'], 'provider_status', 'core.provider.status.' . $status, [], '/account/provider');
    }
}
