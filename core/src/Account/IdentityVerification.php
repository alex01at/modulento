<?php

declare(strict_types=1);

namespace Modulento\Core\Account;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * Proof that an account is who it says it is: a passport, an ID card, an
 * official document uploaded for an administrator to check - fraud
 * prevention, not the e-mail confirmation Accounts::markVerified() is about.
 *
 * Like order files, these cannot be re-encoded, so they are stored outside
 * the web root under a random name without extension and handed out only
 * to administrators, never shown publicly and never displayed by the
 * browser - only the resulting status ("verified") is public.
 */
final class IdentityVerification
{
    public const STATUSES = ['none', 'pending', 'verified', 'rejected'];
    public const MAX_FILES = 3;
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private PDO $db, private string $uploadDir)
    {
    }

    /**
     * Turns PHP's structure for a multi-file field into one entry per file,
     * leaving out empty slots - same shape as Order\OrderFiles::uploads().
     *
     * @param array<string, mixed>|null $field $_FILES['documents']
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
     * @param array<int, array{name: string, tmp_name: string, error: int, size: int}> $uploads
     * @return string|null language key of the problem, null if all are acceptable
     */
    public static function problem(array $uploads): ?string
    {
        if ($uploads === []) {
            return 'core.account.identity.error.none';
        }
        if (count($uploads) > self::MAX_FILES) {
            return 'core.account.identity.error.too_many';
        }

        foreach ($uploads as $upload) {
            if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return 'core.account.identity.error.too_large';
            }
            if ($upload['error'] !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'])) {
                return 'core.account.identity.error.upload';
            }
            if (filesize($upload['tmp_name']) > self::MAX_BYTES) {
                return 'core.account.identity.error.too_large';
            }
            if (filesize($upload['tmp_name']) === 0) {
                return 'core.account.identity.error.empty';
            }
            if (!in_array(strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                return 'core.account.identity.error.type';
            }
        }

        return null;
    }

    /**
     * Stores the documents and puts the account back to "pending" - also
     * after a rejection, so correcting and resubmitting asks for a new
     * decision, the same way a rejected provider profile does.
     *
     * @param array<int, array{name: string, tmp_name: string, error: int, size: int}> $uploads already passed problem()
     */
    public function submit(int $accountId, array $uploads): void
    {
        if ($uploads === []) {
            return;
        }

        $dir = $this->uploadDir . '/' . $accountId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('Identity verification: cannot create ' . $dir);
            return;
        }

        $insert = $this->db->prepare(
            'INSERT INTO account_identity_document (account_id, original_name, stored_name, size, created_at) VALUES (:account, :original, :stored, :size, :now)'
        );
        foreach ($uploads as $upload) {
            $stored = bin2hex(random_bytes(16));
            $target = $dir . '/' . $stored;

            // move_uploaded_file() only accepts real uploads; rename() is
            // the fallback for the test suite, which has no HTTP upload.
            if (!@move_uploaded_file($upload['tmp_name'], $target) && !@rename($upload['tmp_name'], $target)) {
                error_log('Identity verification: cannot store an upload for account ' . $accountId);
                continue;
            }
            @chmod($target, 0640);

            $insert->execute([
                'account' => $accountId, 'original' => $this->safeName($upload['name']), 'stored' => $stored,
                'size' => filesize($target), 'now' => Clock::now(),
            ]);
        }

        $stmt = $this->db->prepare(
            "UPDATE account SET identity_status = 'pending', identity_note = NULL, identity_decided_at = NULL, identity_decided_by = NULL WHERE id = :id"
        );
        $stmt->execute(['id' => $accountId]);
    }

    /** @return array{status: string, note: ?string, decided_at: ?string}|null null if the account does not exist */
    public function status(int $accountId): ?array
    {
        $stmt = $this->db->prepare('SELECT identity_status, identity_note, identity_decided_at FROM account WHERE id = :id');
        $stmt->execute(['id' => $accountId]);
        $row = $stmt->fetch();

        return $row !== false ? ['status' => $row['identity_status'], 'note' => $row['identity_note'], 'decided_at' => $row['identity_decided_at']] : null;
    }

    /** @return array<int, array{id: int, name: string, size: int, created_at: string}> */
    public function documents(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT id, original_name, size, created_at FROM account_identity_document WHERE account_id = :id ORDER BY id');
        $stmt->execute(['id' => $accountId]);

        return array_map(fn (array $row) => [
            'id' => (int) $row['id'], 'name' => $row['original_name'], 'size' => (int) $row['size'], 'created_at' => $row['created_at'],
        ], $stmt->fetchAll());
    }

    /** @return array{path: string, name: string, size: int}|null the file if it belongs to this account and still exists */
    public function find(int $accountId, int $documentId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM account_identity_document WHERE id = :id AND account_id = :account');
        $stmt->execute(['id' => $documentId, 'account' => $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $path = $this->uploadDir . '/' . $accountId . '/' . $row['stored_name'];

        return is_file($path) ? ['path' => $path, 'name' => $row['original_name'], 'size' => (int) $row['size']] : null;
    }

    /** @param int $decidedBy the administrator's account */
    public function setStatus(int $accountId, string $status, ?string $note, int $decidedBy): bool
    {
        if (!in_array($status, ['verified', 'rejected'], true)) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE account SET identity_status = :status, identity_note = :note, identity_decided_at = :now, identity_decided_by = :by WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'now' => Clock::now(),
            'by' => $decidedBy,
            'id' => $accountId,
        ]);

        return true;
    }

    /** Physical cleanup when an account is deleted - the row is cascaded by the database. */
    public function deleteFiles(int $accountId): void
    {
        $dir = $this->uploadDir . '/' . $accountId;
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($dir . '/' . $file);
            }
        }
        @rmdir($dir);
    }

    /** Same rule as Order\OrderFiles::safeName(): no path, no control characters. */
    private function safeName(string $name): string
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
}
