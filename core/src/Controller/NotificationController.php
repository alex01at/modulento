<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

/**
 * Every notification of the logged-in account - order state, provider and
 * offer decisions, new messages, outbid. Opening the page marks them all
 * read, the same way opening an order marks its messages read
 * (Support\MessageSeen::markOrder()).
 */
final class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    public function index(array $params): void
    {
        $app = $this->app;
        $accountId = (int) $app->auth->account()['id'];
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $list = $app->notifications->list($accountId, $page, self::PER_PAGE);

        $this->render('account/notifications.twig', [
            'notifications' => $list['rows'],
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
        ]);

        $app->notifications->markAllRead($accountId);
    }

    public function unread(array $params): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['count' => $this->app->notifications->unreadCount((int) $this->app->auth->account()['id'])]);
    }
}
