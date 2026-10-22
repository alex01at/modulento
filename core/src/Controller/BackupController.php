<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\BackupException;
use Modulento\Core\Support\Session;

final class BackupController extends Controller
{
    public function index(array $params): void
    {
        $this->render('@admin/backup.twig', [
            'backups' => $this->app->backups->list(),
        ]);
    }

    public function create(array $params): void
    {
        @set_time_limit(0);

        try {
            $this->app->backups->create();
            Session::flash('success', $this->trans('core.backup.created'));
        } catch (BackupException $e) {
            Session::flash('error', $this->trans($e->messageKey, $e->params));
        }

        $this->redirect('/admin/backup');
    }

    public function download(array $params): void
    {
        $path = $this->app->backups->path((string) $params['name']);
        if ($path === null) {
            $this->notFound();

            return;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, no-store');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($path);
    }

    public function delete(array $params): void
    {
        $this->app->backups->delete((string) $params['name']);
        Session::flash('success', $this->trans('core.backup.deleted'));
        $this->redirect('/admin/backup');
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
    }
}
