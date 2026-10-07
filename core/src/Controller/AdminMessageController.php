<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Session;

/**
 * Every message on the platform, for an administrator - order messages and
 * offer questions each have their own tab, since they are different things
 * (Order\Orders and Catalogue\OfferMessages). The word filter no longer
 * refuses a message; it flags it here instead, and an administrator decides
 * whether to dismiss the flag or hide the message from the two parties.
 */
final class AdminMessageController extends Controller
{
    private const PER_PAGE = 50;
    private const KINDS = ['order', 'offer'];

    public function index(array $params): void
    {
        $app = $this->app;
        $kind = in_array($_GET['tab'] ?? '', self::KINDS, true) ? $_GET['tab'] : 'order';
        // Reuses the dashboard card's fixed "?status=pending" link (see
        // themes/admin/templates/index.twig) as "flagged and not decided".
        $flaggedOnly = ($_GET['status'] ?? '') === 'pending';
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $list = $kind === 'order'
            ? $app->orders->listMessages($flaggedOnly, $page, self::PER_PAGE)
            : $app->offerMessages->listAll($flaggedOnly, $page, self::PER_PAGE);

        $rows = $kind === 'order'
            ? array_map(fn (array $row) => [
                'id' => (int) $row['id'],
                'sender' => $row['display_name'] ?: $row['email'] ?: $this->trans('core.account_rating.anonymous'),
                'body' => $row['body'],
                'created_at' => $row['created_at'],
                'flagged_word' => $row['flagged_word'],
                'status' => $row['status'],
                'pending' => $row['flagged_word'] !== null && $row['decided_at'] === null,
                'context' => '#' . $row['order_number'] . ' – ' . $row['offer_title'],
                'context_path' => '/admin/orders/' . $row['order_id'],
            ], $list['rows'])
            : array_map(fn (array $row) => [
                'id' => (int) $row['id'],
                'sender' => $row['display_name'] ?: $row['email'] ?: $this->trans('core.account_rating.anonymous'),
                'body' => $row['body'],
                'created_at' => $row['created_at'],
                'flagged_word' => $row['flagged_word'],
                'status' => $row['status'],
                'pending' => $row['flagged_word'] !== null && $row['decided_at'] === null,
                'context' => $this->offerTitle((int) $row['offer_id']),
                'context_path' => '/admin/offers/' . $row['offer_id'],
            ], $list['rows']);

        $this->render('@admin/messages.twig', [
            'tab' => $kind,
            'flagged_only' => $flaggedOnly,
            'messages' => $rows,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
        ]);
    }

    public function hide(array $params): void
    {
        $this->decide($params, 'hidden');
    }

    public function show(array $params): void
    {
        $this->decide($params, 'visible');
    }

    public function dismiss(array $params): void
    {
        $app = $this->app;
        $kind = $params['kind'];
        if (!in_array($kind, self::KINDS, true)) {
            $this->redirect('/admin/messages');
            return;
        }

        $adminId = (int) $app->auth->account()['id'];
        $ok = $kind === 'order'
            ? $app->orders->dismissMessageFlag((int) $params['id'], $adminId)
            : $app->offerMessages->dismissFlag((int) $params['id'], $adminId);

        Session::flash($ok ? 'success' : 'error', $this->trans($ok ? 'core.admin.messages.dismissed' : 'core.error.not_found'));
        $this->redirect('/admin/messages?tab=' . $kind);
    }

    private function decide(array $params, string $status): void
    {
        $app = $this->app;
        $kind = $params['kind'];
        if (!in_array($kind, self::KINDS, true)) {
            $this->redirect('/admin/messages');
            return;
        }

        $adminId = (int) $app->auth->account()['id'];
        $ok = $kind === 'order'
            ? $app->orders->setMessageStatus((int) $params['id'], $status, $adminId)
            : $app->offerMessages->setStatus((int) $params['id'], $status, $adminId);

        Session::flash($ok ? 'success' : 'error', $this->trans($ok ? ('core.admin.messages.' . $status) : 'core.error.not_found'));
        $this->redirect('/admin/messages?tab=' . $kind);
    }

    private function offerTitle(int $offerId): string
    {
        $offer = $this->app->offers->find($offerId);

        return $offer !== null ? ($this->app->offers->text($offer, $this->app->translator->locale())['title'] ?? '') : '';
    }
}
