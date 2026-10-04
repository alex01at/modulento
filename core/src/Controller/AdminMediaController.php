<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Media\Library;
use Modulento\Core\Support\Session;

/** The media library in the administration: upload, look at and remove pictures. */
final class AdminMediaController extends Controller
{
    private const PER_PAGE = 24;

    public function index(array $params): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = $this->app->media->list($page, self::PER_PAGE);

        $this->render('@admin/media.twig', [
            'items' => array_map(fn (array $row) => [
                'id' => $row['id'],
                'url' => Library::url($row['file']),
                'title' => $row['title'],
                'width' => $row['width'],
                'height' => $row['height'],
                'kilobytes' => (int) ceil($row['bytes'] / 1024),
                'created_at' => $row['created_at'],
            ], $result['rows']),
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'accepted' => 'image/jpeg,image/png,image/webp',
        ]);
    }

    public function upload(array $params): void
    {
        $title = (string) ($_POST['title'] ?? '');
        $problem = $this->app->media->add(is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [], $title);

        if ($problem !== null) {
            Session::flash('error', $this->trans($problem));
        } else {
            Session::flash('success', $this->trans('core.media.uploaded'));
        }
        $this->redirect('/admin/media');
    }

    public function delete(array $params): void
    {
        $this->app->media->delete((int) $params['id']);
        Session::flash('success', $this->trans('core.media.deleted'));
        $this->redirect('/admin/media');
    }
}
