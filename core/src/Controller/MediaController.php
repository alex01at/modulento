<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

/**
 * Serves uploaded pictures from var/uploads, which is outside the web
 * root. Only files the upload code itself created can be requested: the
 * name is 32 random characters, so a picture of an unpublished offer is
 * reachable only by someone who was shown its address.
 */
final class MediaController extends Controller
{
    private const TYPES = ['webp' => 'image/webp', 'jpg' => 'image/jpeg'];

    public function offerImage(array $params): void
    {
        $this->send($this->app->offerImages->path((int) $params['id'], $params['file']));
    }

    public function avatar(array $params): void
    {
        $this->send($this->app->avatars->path($params['file']));
    }

    private function send(?string $file): void
    {
        if ($file === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        header('Content-Type: ' . self::TYPES[pathinfo($file, PATHINFO_EXTENSION)]);
        // The name is random and never reused, so the file never changes.
        header('Cache-Control: public, max-age=31536000, immutable');
        header("Content-Security-Policy: default-src 'none'");
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }
}
