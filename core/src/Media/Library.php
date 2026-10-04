<?php

declare(strict_types=1);

namespace Modulento\Core\Media;

use GdImage;
use PDO;

/**
 * The media library: pictures an administrator uploads for use across the
 * site. Every upload is decoded and written again, so what is stored is a
 * picture and nothing that rode along with the file.
 */
final class Library
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_PIXELS = 25_000_000;
    /** Larger pictures are scaled down; the file keeps its proportions. */
    private const MAX_EDGE = 2400;
    private const TITLE_LENGTH = 200;
    private const ACCEPTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(private PDO $db, private string $uploadDir)
    {
    }

    /**
     * @param array{tmp_name?: string, error?: int} $upload one entry of $_FILES
     * @return string|null language key of the problem, null on success
     */
    public function add(array $upload, string $title): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
            return 'core.media.error.unavailable';
        }
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'] ?? '')) {
            return in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'core.media.error.too_large'
                : 'core.media.error.upload';
        }
        if (filesize($upload['tmp_name']) > self::MAX_BYTES) {
            return 'core.media.error.too_large';
        }

        // The kind of file is read from its content, never from the name.
        $info = @getimagesize($upload['tmp_name']);
        if ($info === false || !in_array($info[2], self::ACCEPTED, true)) {
            return 'core.media.error.type';
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return 'core.media.error.dimensions';
        }

        $source = @imagecreatefromstring((string) file_get_contents($upload['tmp_name']));
        if ($source === false) {
            return 'core.media.error.type';
        }
        $target = $this->scaled($source);

        $extension = function_exists('imagewebp') ? 'webp' : 'png';
        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
            return 'core.media.error.storage';
        }
        $path = $this->uploadDir . '/' . $name;
        $written = $extension === 'webp' ? imagewebp($target, $path, 90) : imagepng($target, $path, 6);
        $width = imagesx($target);
        $height = imagesy($target);
        imagedestroy($source);
        imagedestroy($target);

        if (!$written) {
            @unlink($path);

            return 'core.media.error.storage';
        }

        $this->db->prepare(
            'INSERT INTO media (file, title, width, height, bytes, created_at) VALUES (:file, :title, :width, :height, :bytes, :created_at)'
        )->execute([
            'file' => $name,
            'title' => mb_substr(trim($title), 0, self::TITLE_LENGTH),
            'width' => $width,
            'height' => $height,
            'bytes' => (int) filesize($path),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return null;
    }

    /** @return array{rows: array<int, array<string, mixed>>, total: int} newest first */
    public function list(int $page, int $perPage): array
    {
        $total = (int) $this->db->query('SELECT COUNT(*) FROM media')->fetchColumn();
        $stmt = $this->db->prepare('SELECT * FROM media ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('SELECT file FROM media WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $file = $stmt->fetchColumn();
        if ($file === false) {
            return;
        }

        $this->db->prepare('DELETE FROM media WHERE id = :id')->execute(['id' => $id]);
        @unlink($this->uploadDir . '/' . $file);
    }

    /** Path of a picture the library created, or null. Only the random names it hands out are accepted. */
    public function path(string $file): ?string
    {
        if (preg_match('/^[a-f0-9]{32}\.(webp|png)$/', $file) !== 1) {
            return null;
        }
        $path = $this->uploadDir . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /** Address of a picture on the site, for a page to show it. */
    public static function url(string $file): string
    {
        return '/media/library/' . $file;
    }

    /** Scaled down to the largest edge allowed, transparency kept. */
    private function scaled(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $factor = min(1, self::MAX_EDGE / max($width, $height));
        $newWidth = max(1, (int) round($width * $factor));
        $newHeight = max(1, (int) round($height * $factor));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $target;
    }
}
