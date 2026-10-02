<?php

declare(strict_types=1);

namespace Modulento\Core\Order;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * Files exchanged within an order - material from the buyer, deliveries
 * from the provider.
 *
 * Unlike pictures, these cannot be re-encoded, so they are treated as
 * untrusted for good: stored outside the web root under a random name
 * without extension (nothing the server could ever execute), handed out
 * only to the two parties and to administrators, and always as a download,
 * never displayed by the browser.
 */
final class OrderFiles
{
    public const MAX_FILES = 5;
    public const MAX_BYTES = 20 * 1024 * 1024;
    /** Everything attached to one order together. */
    public const MAX_ORDER_BYTES = 200 * 1024 * 1024;

    /**
     * What a delivery or a briefing plausibly consists of. Programs and
     * scripts are left out: the recipient opens what they are sent.
     */
    public const EXTENSIONS = [
        'pdf', 'txt', 'md', 'rtf', 'csv',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'tif', 'tiff', 'psd', 'ai', 'eps', 'indd', 'fig', 'sketch', 'xd',
        'mp3', 'wav', 'flac', 'ogg', 'm4a', 'mp4', 'mov', 'webm', 'mkv',
        'zip', '7z', 'rar', 'tar', 'gz',
        'ttf', 'otf', 'woff', 'woff2',
        'json', 'xml', 'css', 'srt',
    ];

    public function __construct(private PDO $db, private string $uploadDir)
    {
    }

    /** The size limit that actually applies: ours, or the server's if that is lower. */
    public static function maxBytes(): int
    {
        $limits = [self::MAX_BYTES];
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = self::iniBytes((string) ini_get($setting));
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    /**
     * Turns PHP's structure for a multi-file field into one entry per
     * file, leaving out empty slots.
     *
     * @param array<string, mixed>|null $field $_FILES['files']
     * @return array<int, array{name: string, tmp_name: string, error: int, size: int}>
     */
    public static function uploads(?array $field): array
    {
        if ($field === null || !isset($field['error'])) {
            return [];
        }

        $uploads = [];
        foreach ((array) $field['error'] as $i => $error) {
            if ((int) $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $uploads[] = [
                'name' => (string) ((array) $field['name'])[$i],
                'tmp_name' => (string) ((array) $field['tmp_name'])[$i],
                'error' => (int) $error,
                'size' => (int) (((array) ($field['size'] ?? []))[$i] ?? 0),
            ];
        }

        return $uploads;
    }

    /**
     * Checked before anything else happens with the request, so a refused
     * file never leaves a half-done step behind.
     *
     * @param array<int, array{name: string, tmp_name: string, error: int, size: int}> $uploads
     * @return string|null language key of the problem, null if all are acceptable
     */
    public static function problem(array $uploads): ?string
    {
        if (count($uploads) > self::MAX_FILES) {
            return 'core.order.file.error.too_many';
        }

        foreach ($uploads as $upload) {
            if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return 'core.order.file.error.too_large';
            }
            if ($upload['error'] !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'])) {
                return 'core.order.file.error.upload';
            }
            if (filesize($upload['tmp_name']) > self::MAX_BYTES) {
                return 'core.order.file.error.too_large';
            }
            if (filesize($upload['tmp_name']) === 0) {
                return 'core.order.file.error.empty';
            }
            if (!in_array(strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                return 'core.order.file.error.type';
            }
        }

        return null;
    }

    /**
     * Whether an order has room for more files; checked with problem().
     *
     * @param array<int, array{name: string, tmp_name: string, error: int, size: int}> $uploads
     * @return string|null language key of the problem
     */
    public function quotaProblem(int $orderId, array $uploads): ?string
    {
        if ($uploads === []) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT COALESCE(SUM(size), 0) FROM order_file WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);

        return (int) $stmt->fetchColumn() + array_sum(array_column($uploads, 'size')) > self::MAX_ORDER_BYTES ? 'core.order.file.error.quota' : null;
    }

    /**
     * @param array<int, array{name: string, tmp_name: string, error: int, size: int}> $uploads already passed problem()
     * @param int|null $eventId the history entry the files belong to, or
     * @param int|null $messageId the message they belong to
     */
    public function store(int $orderId, ?int $accountId, string $role, ?int $eventId, ?int $messageId, array $uploads): void
    {
        if ($uploads === []) {
            return;
        }

        $dir = $this->uploadDir . '/' . $orderId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('Order files: cannot create ' . $dir);
            return;
        }

        $insert = $this->db->prepare(
            'INSERT INTO order_file (order_id, event_id, message_id, account_id, author_role, original_name, stored_name, size, created_at)
             VALUES (:order, :event, :message, :account, :role, :original, :stored, :size, :now)'
        );

        foreach ($uploads as $upload) {
            $stored = bin2hex(random_bytes(16));
            $target = $dir . '/' . $stored;

            // move_uploaded_file() only accepts real uploads; rename() is
            // the fallback for the test suite, which has no HTTP upload.
            if (!@move_uploaded_file($upload['tmp_name'], $target) && !@rename($upload['tmp_name'], $target)) {
                error_log('Order files: cannot store an upload for order ' . $orderId);
                continue;
            }
            @chmod($target, 0640);

            $insert->execute([
                'order' => $orderId, 'event' => $eventId, 'message' => $messageId, 'account' => $accountId, 'role' => $role,
                'original' => self::safeName($upload['name']), 'stored' => $stored, 'size' => filesize($target), 'now' => Clock::now(),
            ]);
        }
    }

    /** @return array{events: array<int, array<int, array>>, messages: array<int, array<int, array>>} files by history entry and by message */
    public function ofOrder(int $orderId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM order_file WHERE order_id = :id ORDER BY id');
        $stmt->execute(['id' => $orderId]);

        $files = ['events' => [], 'messages' => []];
        foreach ($stmt->fetchAll() as $file) {
            $view = ['id' => (int) $file['id'], 'name' => $file['original_name'], 'size' => (int) $file['size']];
            if ($file['event_id'] !== null) {
                $files['events'][(int) $file['event_id']][] = $view;
            } elseif ($file['message_id'] !== null) {
                $files['messages'][(int) $file['message_id']][] = $view;
            }
        }

        return $files;
    }

    /** @return array{path: string, name: string, size: int}|null the file if it belongs to this order and still exists */
    public function find(int $orderId, int $fileId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM order_file WHERE id = :id AND order_id = :order');
        $stmt->execute(['id' => $fileId, 'order' => $orderId]);
        $file = $stmt->fetch();
        if (!$file) {
            return null;
        }

        $path = $this->uploadDir . '/' . $orderId . '/' . $file['stored_name'];

        return is_file($path) ? ['path' => $path, 'name' => $file['original_name'], 'size' => (int) $file['size']] : null;
    }

    /**
     * The name as it will be offered for download: no path, no control
     * characters, nothing a header or a file system could misread.
     */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>|:*?\/]+/u', '_', $name);
        $name = trim($name, " .\t");

        if (mb_strlen($name) > 150) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 150 - mb_strlen($extension) - 1) . '.' . $extension;
        }

        return $name !== '' ? $name : 'file';
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
