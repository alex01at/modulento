<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * The site's own look in place of the defaults: a logo (for a light and,
 * optionally, a separate dark background) and a favicon. Like offer
 * pictures they are decoded and written anew - never stored as uploaded -
 * and kept outside the web root under a random name. Which file belongs to
 * which kind is recorded in Settings ("core.<kind>"), not in a table of
 * its own: there is at most one of each.
 */
final class Branding
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const KINDS = ['logo_light', 'logo_dark', 'favicon'];

    private const MAX_PIXELS = 20_000_000;
    private const LOGO_MAX_EDGE = 600;
    private const FAVICON_EDGE = 128;
    private const ACCEPTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(private Settings $settings, private string $uploadDir)
    {
    }

    /**
     * @param string $kind one of self::KINDS
     * @param array{tmp_name?: string, error?: int} $upload one entry of $_FILES
     * @return string|null language key of the problem, null on success
     */
    public function set(string $kind, array $upload): ?string
    {
        if (!in_array($kind, self::KINDS, true)) {
            return 'core.offer.image.error.type';
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
            return 'core.offer.image.error.unavailable';
        }
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'] ?? '')) {
            return in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'core.offer.image.error.too_large'
                : 'core.offer.image.error.upload';
        }
        if (filesize($upload['tmp_name']) > self::MAX_BYTES) {
            return 'core.offer.image.error.too_large';
        }

        // What the file is is read from its content, never from the name
        // or the type the browser claims.
        $info = @getimagesize($upload['tmp_name']);
        if ($info === false || !in_array($info[2], self::ACCEPTED, true)) {
            return 'core.offer.image.error.type';
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return 'core.offer.image.error.dimensions';
        }

        $source = @imagecreatefromstring((string) file_get_contents($upload['tmp_name']));
        if ($source === false) {
            return 'core.offer.image.error.type';
        }

        // A favicon is square; a logo keeps its own shape. Neither is
        // forced onto a solid background - transparency survives, so a
        // logo drawn for a dark header does not arrive on a white square.
        $target = $kind === 'favicon'
            ? $this->croppedSquare($source, self::FAVICON_EDGE)
            : $this->resized($source, self::LOGO_MAX_EDGE);

        $extension = function_exists('imagewebp') ? 'webp' : 'png';
        $name = bin2hex(random_bytes(16));
        if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
            return 'core.offer.image.error.storage';
        }
        $path = "{$this->uploadDir}/{$name}.{$extension}";
        $written = $extension === 'webp' ? imagewebp($target, $path, 90) : imagepng($target, $path, 6);
        imagedestroy($source);
        imagedestroy($target);

        if (!$written) {
            @unlink($path);

            return 'core.offer.image.error.storage';
        }

        $this->delete($kind);
        $this->settings->set("core.{$kind}", "{$name}.{$extension}");

        return null;
    }

    /** Removes a kind's file and setting, if there is one. */
    public function delete(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            return;
        }

        $value = $this->settings->get("core.{$kind}");
        if ($value === '') {
            return;
        }

        @unlink("{$this->uploadDir}/{$value}");
        $this->settings->set("core.{$kind}", '');
    }

    /** Path (below /media) of a kind's file, or null without one. */
    public function url(string $kind): ?string
    {
        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }

        $value = $this->settings->get("core.{$kind}");

        return $value !== '' ? "/media/branding/{$value}" : null;
    }

    /** The file behind a requested name, if the upload code created it. */
    public function path(string $file): ?string
    {
        if (preg_match('/^[a-f0-9]{32}\.(webp|png)$/', $file) !== 1) {
            return null;
        }
        $path = $this->uploadDir . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /** Scaled down to fit within $maxEdge, kept proportional, transparent canvas. */
    private function resized(\GdImage $source, int $maxEdge): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxEdge / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = $this->transparentCanvas($newWidth, $newHeight);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $target;
    }

    /** A square cut from the middle, scaled to $edge, transparent canvas. */
    private function croppedSquare(\GdImage $source, int $edge): \GdImage
    {
        $side = min(imagesx($source), imagesy($source));
        $target = $this->transparentCanvas($edge, $edge);
        imagecopyresampled(
            $target, $source, 0, 0,
            intdiv(imagesx($source) - $side, 2), intdiv(imagesy($source) - $side, 2),
            $edge, $edge, $side, $side
        );

        return $target;
    }

    private function transparentCanvas(int $width, int $height): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        return $canvas;
    }
}
