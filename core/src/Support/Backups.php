<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use FilesystemIterator;
use Generator;
use PDO;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * A full backup an administrator can make with one click, for a site that
 * usually has no shell: the database (as plain SQL, see DatabaseDump - no
 * mysqldump binary needed) and the whole application tree, including
 * .env and var/secret.key (both are needed to actually restore anything -
 * see Administration -> Payments' own note on secret.key), in one zip
 * under var/backups/. Deliberately a different place and a different
 * purpose from var/updates/backups/, the auto-updater's own short-lived
 * safety copy of the files it is about to overwrite - that one never
 * contains the database or var/uploads at all, and is not meant to be
 * restored from by hand.
 */
final class Backups
{
    public const DIR = 'var/backups';
    private const EXCLUDE_PREFIXES = ['.git/', 'var/cache/', 'var/log/', 'var/backups/', 'var/updates/'];
    private const RETENTION = 10;
    private const NAME_PATTERN = '/^backup-\d{8}-\d{6}\.zip$/';

    public function __construct(private string $root, private PDO $db)
    {
        $this->root = rtrim($this->root, '/');
    }

    /** @return list<array{name: string, size: int, created_at: string}> newest first */
    public function list(): array
    {
        $files = glob($this->dir() . '/backup-*.zip') ?: [];
        rsort($files);

        return array_map(fn (string $file) => [
            'name' => basename($file),
            'size' => (int) filesize($file),
            'created_at' => gmdate('Y-m-d H:i:s', (int) filemtime($file)),
        ], $files);
    }

    /** Builds a new backup and returns its file name. */
    public function create(): string
    {
        $this->ensureDir($this->dir());

        $dumpPath = $this->dir() . '/.database-' . bin2hex(random_bytes(8)) . '.sql';
        (new DatabaseDump($this->db))->toFile($dumpPath);

        $name = 'backup-' . gmdate('Ymd-His') . '.zip';
        $path = $this->dir() . '/' . $name;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($dumpPath);

            throw new BackupException('core.backup.error.not_writable', ['path' => self::DIR]);
        }

        $zip->addFile($dumpPath, 'database.sql');
        foreach ($this->walkFiles() as $relative => $absolute) {
            $zip->addFile($absolute, 'files/' . $relative);
        }

        $closed = $zip->close();
        @unlink($dumpPath);
        if (!$closed) {
            throw new BackupException('core.backup.error.not_writable', ['path' => self::DIR]);
        }

        $this->prune();

        return $name;
    }

    /** The full path of a backup by name, or null for a name that is not a backup of this site's own making. */
    public function path(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }
        $path = $this->dir() . '/' . $name;

        return is_file($path) ? $path : null;
    }

    public function delete(string $name): void
    {
        $path = $this->path($name);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function dir(): string
    {
        return $this->root . '/' . self::DIR;
    }

    private function prune(): void
    {
        $files = glob($this->dir() . '/backup-*.zip') ?: [];
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - self::RETENTION)) as $file) {
            @unlink($file);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new BackupException('core.backup.error.not_writable', ['path' => str_replace($this->root . '/', '', $dir)]);
        }
    }

    /** @return Generator<string, string> relative path => absolute path */
    private function walkFiles(): Generator
    {
        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $current): bool {
                $relative = ltrim(substr($current->getPathname(), strlen($this->root)), '/');
                $checkPath = $current->isDir() ? $relative . '/' : $relative;

                foreach (self::EXCLUDE_PREFIXES as $prefix) {
                    if (str_starts_with($checkPath, $prefix)) {
                        return false;
                    }
                }

                return true;
            }
        );

        // LEAVES_ONLY also yields an empty directory itself (it has no
        // children to recurse into, so it counts as a "leaf") - only an
        // actual file belongs in the zip.
        foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY) as $item) {
            if ($item->isFile()) {
                yield ltrim(substr($item->getPathname(), strlen($this->root)), '/') => $item->getPathname();
            }
        }
    }
}
