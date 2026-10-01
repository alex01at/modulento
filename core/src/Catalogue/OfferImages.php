<?php

declare(strict_types=1);

namespace Modulento\Core\Catalogue;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * Pictures of offers. An upload is never stored as it arrived: it is
 * decoded and written anew in two sizes, which removes embedded data
 * (location in EXIF), anything hidden behind the picture, and oversized
 * originals. The files live outside the web root and are served by
 * MediaController.
 */
final class OfferImages
{
    public const MAX_PER_OFFER = 8;
    public const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_PIXELS = 40_000_000;
    private const LARGE_EDGE = 1600;
    private const THUMB_EDGE = 480;
    private const ACCEPTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(private PDO $db, private string $uploadDir)
    {
    }

    /** Whether this server can process pictures at all (PHP's GD extension). */
    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor');
    }

    /** @return array<int, array> */
    public function ofOffer(int $offerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM offer_image WHERE offer_id = :id ORDER BY position, id');
        $stmt->execute(['id' => $offerId]);

        return $stmt->fetchAll();
    }

    /**
     * @param array{tmp_name?: string, error?: int, size?: int} $upload one entry of $_FILES
     * @return string|null language key of the problem, null on success
     */
    public function add(int $offerId, array $upload): ?string
    {
        if (!self::available()) {
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
        if (count($this->ofOffer($offerId)) >= self::MAX_PER_OFFER) {
            return 'core.offer.image.error.too_many';
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

        $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
        $name = bin2hex(random_bytes(16));
        $dir = $this->uploadDir . '/' . $offerId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return 'core.offer.image.error.storage';
        }

        $large = $this->resized($source, self::LARGE_EDGE);
        $thumb = $this->resized($source, self::THUMB_EDGE);
        $written = $this->write($large, "{$dir}/{$name}.{$extension}", $extension)
            && $this->write($thumb, "{$dir}/{$name}_thumb.{$extension}", $extension);
        $width = imagesx($large);
        $height = imagesy($large);
        imagedestroy($source);
        imagedestroy($large);
        imagedestroy($thumb);

        if (!$written) {
            @unlink("{$dir}/{$name}.{$extension}");
            @unlink("{$dir}/{$name}_thumb.{$extension}");

            return 'core.offer.image.error.storage';
        }

        $stmt = $this->db->prepare(
            'INSERT INTO offer_image (offer_id, name, extension, width, height, position, created_at)
             VALUES (:offer, :name, :extension, :width, :height, :position, :now)'
        );
        $stmt->execute([
            'offer' => $offerId, 'name' => $name, 'extension' => $extension, 'width' => $width, 'height' => $height,
            'position' => count($this->ofOffer($offerId)), 'now' => Clock::now(),
        ]);

        return null;
    }

    public function delete(int $offerId, int $imageId): void
    {
        $stmt = $this->db->prepare('SELECT * FROM offer_image WHERE id = :id AND offer_id = :offer');
        $stmt->execute(['id' => $imageId, 'offer' => $offerId]);
        $image = $stmt->fetch();
        if (!$image) {
            return;
        }

        $delete = $this->db->prepare('DELETE FROM offer_image WHERE id = :id');
        $delete->execute(['id' => $imageId]);
        $this->unlinkFiles($offerId, $image);
    }

    /** Removes the files of an offer that is being deleted (its rows go with the offer). */
    public function deleteAll(int $offerId): void
    {
        foreach ($this->ofOffer($offerId) as $image) {
            $this->unlinkFiles($offerId, $image);
        }
        @rmdir($this->uploadDir . '/' . $offerId);
    }

    /** The stored file for a request, or null - only names this class created can match. */
    public function path(int $offerId, string $file): ?string
    {
        if (preg_match('/^[0-9a-f]{32}(_thumb)?\.(webp|jpg)$/', $file) !== 1) {
            return null;
        }

        $path = $this->uploadDir . '/' . $offerId . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /** Paths (below /media) of an image row, for templates. */
    public static function urls(array $image): array
    {
        $base = '/media/offers/' . $image['offer_id'] . '/' . $image['name'];

        return [
            'id' => (int) $image['id'],
            'large' => $base . '.' . $image['extension'],
            'thumb' => $base . '_thumb.' . $image['extension'],
            'width' => (int) $image['width'],
            'height' => (int) $image['height'],
        ];
    }

    private function unlinkFiles(int $offerId, array $image): void
    {
        $base = $this->uploadDir . '/' . $offerId . '/' . $image['name'];
        @unlink($base . '.' . $image['extension']);
        @unlink($base . '_thumb.' . $image['extension']);
    }

    private function resized(\GdImage $source, int $maxEdge): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxEdge / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        // Transparent areas become white instead of black.
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $target;
    }

    private function write(\GdImage $image, string $path, string $extension): bool
    {
        return $extension === 'webp' ? imagewebp($image, $path, 82) : imagejpeg($image, $path, 85);
    }
}
