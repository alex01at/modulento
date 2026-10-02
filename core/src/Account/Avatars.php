<?php

declare(strict_types=1);

namespace Modulento\Core\Account;

use Modulento\Core\Support\Clock;
use PDO;
use PDOException;

/**
 * The picture an account shows of itself. Like offer pictures it is
 * decoded and written anew - never stored as uploaded - and kept outside
 * the web root under a random name.
 */
final class Avatars
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_PIXELS = 40_000_000;
    private const EDGE = 256;
    private const ACCEPTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(private PDO $db, private string $uploadDir)
    {
    }

    /**
     * @param array{tmp_name?: string, error?: int} $upload one entry of $_FILES
     * @return string|null language key of the problem, null on success
     */
    public function set(int $accountId, array $upload): ?string
    {
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

        // A square cut from the middle.
        $side = min(imagesx($source), imagesy($source));
        $target = imagecreatetruecolor(self::EDGE, self::EDGE);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, intdiv(imagesx($source) - $side, 2), intdiv(imagesy($source) - $side, 2), self::EDGE, self::EDGE, $side, $side);

        $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
        $name = bin2hex(random_bytes(16));
        if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
            return 'core.offer.image.error.storage';
        }
        $path = "{$this->uploadDir}/{$name}.{$extension}";
        $written = $extension === 'webp' ? imagewebp($target, $path, 82) : imagejpeg($target, $path, 85);
        imagedestroy($source);
        imagedestroy($target);
        if (!$written) {
            @unlink($path);

            return 'core.offer.image.error.storage';
        }

        $this->delete($accountId);
        $stmt = $this->db->prepare('INSERT INTO account_avatar (account_id, name, extension, created_at) VALUES (:id, :name, :extension, :now)');
        $stmt->execute(['id' => $accountId, 'name' => $name, 'extension' => $extension, 'now' => Clock::now()]);

        return null;
    }

    /** Removes an account's picture, file and row. */
    public function delete(int $accountId): void
    {
        $row = $this->row($accountId);
        if ($row === null) {
            return;
        }

        @unlink("{$this->uploadDir}/{$row['name']}.{$row['extension']}");
        $stmt = $this->db->prepare('DELETE FROM account_avatar WHERE account_id = :id');
        $stmt->execute(['id' => $accountId]);
    }

    /** Path (below /media) of an account's picture, or null without one. */
    public function url(int $accountId): ?string
    {
        $row = $this->row($accountId);

        return $row !== null ? "/media/avatars/{$row['name']}.{$row['extension']}" : null;
    }

    /** The file behind a requested name, if the upload code created it. */
    public function path(string $file): ?string
    {
        if (preg_match('/^[a-f0-9]{32}\.(webp|jpg)$/', $file) !== 1) {
            return null;
        }
        $path = $this->uploadDir . '/' . $file;

        return is_file($path) ? $path : null;
    }

    private function row(int $accountId): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT name, extension FROM account_avatar WHERE account_id = :id');
            $stmt->execute(['id' => $accountId]);
        } catch (PDOException) {
            // Files of a newer version, database of the older one: between
            // an update and its migrations nobody has a picture.
            return null;
        }

        return $stmt->fetch() ?: null;
    }
}
