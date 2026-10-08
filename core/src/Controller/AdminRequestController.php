<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\App;
use Modulento\Core\Request\Requests;
use Modulento\Core\Support\Session;

/** Moderation of requests - 1:1 the pattern of AdminCatalogueController, kept separate since it is tied to its own table. */
final class AdminRequestController extends Controller
{
    private const PER_PAGE = 50;
    /** Form action => resulting status. */
    private const DECISIONS = ['approve' => 'published', 'reject' => 'rejected'];

    public function index(array $params): void
    {
        $status = in_array($_GET['status'] ?? '', Requests::STATUSES, true) ? $_GET['status'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = $this->app->requests->listAll(null, $status, $page, self::PER_PAGE);

        $this->render('@admin/requests.twig', [
            'requests' => $list['rows'],
            'counts' => $this->app->requests->counts(),
            'status' => $status,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
            'approval_required' => $this->app->requests->approvalRequired(),
        ]);
    }

    public function request(array $params): void
    {
        $app = $this->app;
        $request = $app->requests->find((int) $params['id']);
        if ($request === null) {
            $this->redirect('/admin/requests');
            return;
        }

        $category = $request['category_id'] !== null ? $app->categories->find($request['category_id']) : null;
        $locale = $app->translator->locale();

        $this->render('@admin/request.twig', [
            'request' => $request + [
                'category' => $category !== null ? $app->categories->view($category, $locale) : null,
                'changed_since_decision' => $request['decided_at'] !== null && $request['updated_at'] > $request['decided_at'],
            ],
            'applications' => $app->requestApplications->forRequest($request['id']),
        ]);
    }

    public function decide(array $params): void
    {
        $request = $this->app->requests->find((int) $params['id']);
        $status = self::DECISIONS[$_POST['decision'] ?? ''] ?? null;
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($request === null || $status === null) {
            $this->redirect('/admin/requests');
            return;
        }
        if ($status === 'rejected' && $note === '') {
            Session::flash('error', $this->trans('core.admin.providers.error.note'));
            $this->redirect('/admin/requests/' . $request['id']);
            return;
        }

        $this->app->requests->setStatus($request['id'], $status, $status === 'rejected' ? $note : null, $this->app->auth->account()['id']);
        self::announce($this->app, $request, $status, $note);

        Session::flash('success', $this->trans('core.admin.requests.decided.' . $status));
        $this->redirect('/admin/requests/' . $request['id']);
    }

    /** By mail, in the buyer's own language, and as a notification. */
    public static function announce(App $app, array $request, string $status, string $note = ''): void
    {
        $locale = $app->locales->isEnabled($request['account_locale']) ? $request['account_locale'] : $app->locales->default();

        $app->mailer->send($request['account_email'], 'emails/request_' . $status . '.txt.twig', [
            'title' => $request['title'],
            'note' => $note,
            'edit_link' => $app->url('/account/requests/' . $request['id'], $locale, true),
            'public_link' => $app->url('/requests/' . $request['slug'], $locale, true),
        ], $locale);
        $app->notifications->create((int) $request['account_id'], 'request_status', 'core.notification.request_status', [
            'title' => $request['title'],
            'status' => $app->translator->trans('core.request.status.' . $status, [], $locale),
        ], '/account/requests/' . $request['id']);
    }
}
