<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

/**
 * Pending-action counts behind the administration header's own bell icon -
 * open content reports, offers or requests waiting for moderation, and
 * whatever an extension adds through Registrar::adminNotice(). Each item
 * is only shown to an administrator who already has the permission for
 * the section it points to (App::adminNotices()).
 */
final class AdminNoticeController extends Controller
{
    /** The full-page fallback for when the header's dropdown has no script to open. */
    public function index(array $params): void
    {
        $this->render('@admin/notices.twig', ['notices' => $this->app->adminNotices()]);
    }

    /** The same list, as the bare panel the header's dropdown fetches and drops in. */
    public function preview(array $params): void
    {
        $this->render('@admin/_notice_panel.twig', ['notices' => $this->app->adminNotices()]);
    }

    public function count(array $params): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['count' => array_sum(array_column($this->app->adminNotices(), 'count'))]);
    }
}
