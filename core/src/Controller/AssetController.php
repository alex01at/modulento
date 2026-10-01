<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

/**
 * Serves the assets/ folder of the active theme, of the admin theme and of
 * enabled extensions. Themes and extensions live outside the web root, so
 * installing one is nothing more than uploading its folder - no symlink,
 * no copy step and no build, which is what makes them usable without
 * shell access.
 */
final class AssetController extends Controller
{
    // Only static file types. Nothing a browser would execute as a page
    // (no .html) and no source files; .svg is sent with a policy that
    // forbids scripts inside it.
    private const TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
    ];

    public function theme(array $params): void
    {
        $this->serve($this->app->themes->siteDirs('assets'), $params['path']);
    }

    public function admin(array $params): void
    {
        $this->serve([$this->app->themes->adminDir('assets')], $params['path']);
    }

    public function extension(array $params): void
    {
        $manifest = $this->app->extensions->loaded()[$params['id']] ?? null;
        $this->serve($manifest !== null ? [$manifest->dir . '/assets'] : [], $params['path']);
    }

    /**
     * The first of $dirs that contains $path, as a real file inside that
     * directory - a path that climbs out of it ("../") or a symlink that
     * points elsewhere resolves outside and is not found.
     *
     * @param string[] $dirs
     */
    public static function locate(array $dirs, string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0") || !isset(self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) {
            return null;
        }

        foreach ($dirs as $dir) {
            $base = realpath($dir);
            $file = $base !== false ? realpath($base . '/' . $path) : false;

            if ($file !== false && is_file($file) && str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
                return $file;
            }
        }

        return null;
    }

    /** @param string[] $dirs */
    private function serve(array $dirs, string $path): void
    {
        $file = self::locate($dirs, $path);

        if ($file === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        $modified = (int) filemtime($file);
        $etag = '"' . dechex($modified) . '-' . dechex((int) filesize($file)) . '"';

        header('Content-Type: ' . self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))]);
        // Asset URLs carry ?v=<change time> (see View), so a long lifetime
        // is safe.
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");

        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }

        header('Content-Length: ' . filesize($file));
        readfile($file);
    }
}
