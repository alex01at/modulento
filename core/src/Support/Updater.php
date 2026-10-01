<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use Closure;
use FilesystemIterator;
use Generator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Downloads, verifies and applies a release from the GitHub Releases of the
 * configured repository (UPDATE_REPO). A release is built and published by
 * .github/workflows/release.yml when a version tag is pushed; it carries
 * two assets, modulento-<version>.zip and the matching .sha256 file.
 *
 * An update only ever adds and overwrites files that are in the package.
 * It never deletes anything, so third-party extensions, themes, .env and
 * everything below var/ survive untouched.
 */
final class Updater
{
    private const API = 'https://api.github.com';
    private const COPY_EXCLUDE_PREFIXES = ['var/'];
    private const COPY_EXCLUDE_FILES = ['.env', 'VERSION'];
    private const BACKUP_EXCLUDE_PREFIXES = ['var/cache/', 'var/updates/', 'var/uploads/', 'var/log/', '.git/'];
    private const BACKUP_RETENTION = 5;
    private const REQUIRED_PACKAGE_ENTRIES = ['public/index.php', 'core/src', 'vendor/autoload.php', 'composer.json', 'VERSION'];

    /**
     * @param Closure(): void $migrate runs the core and extension migrations
     *        after the new files are in place
     */
    public function __construct(
        private string $root,
        private string $repo,
        private string $token,
        private Closure $migrate
    ) {
        $this->root = rtrim($this->root, '/');
    }

    public function isEnabled(): bool
    {
        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $this->repo) === 1;
    }

    /**
     * A git checkout is updated with git, not by unpacking a release over
     * the working tree - that would silently overwrite uncommitted work.
     */
    public function isDevelopmentCheckout(): bool
    {
        return is_dir($this->root . '/.git');
    }

    /** VERSION only exists inside a release package, stamped from the tag. */
    public function currentVersion(): string
    {
        $path = $this->root . '/VERSION';
        if (!is_file($path)) {
            return '0.0.0';
        }

        $version = trim((string) file_get_contents($path));

        return $version !== '' ? $version : '0.0.0';
    }

    /**
     * Diagnostic only: a lock file left behind by a failed attempt. Never
     * used to decide whether a new attempt may start - applyUpdate()'s
     * flock() is the real mutex.
     *
     * @return array{stage: string, release_version: string, started_at: string}|null
     */
    public function staleLockInfo(): ?array
    {
        $path = $this->lockPath();
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && isset($data['stage'], $data['release_version'], $data['started_at']) ? $data : null;
    }

    /**
     * @return array{version: string, published_at: string, changelog: string, zip_url: string, sha256_url: string}|null
     *         null means already up to date
     * @throws UpdateException
     */
    public function checkForUpdate(): ?array
    {
        if (!$this->isEnabled()) {
            throw new UpdateException('core.update.error.disabled');
        }

        [$status, $body] = $this->httpGet(self::API . '/repos/' . $this->repo . '/releases/latest', 'application/vnd.github+json');

        if ($status === 404) {
            throw new UpdateException('core.update.error.no_release', ['repo' => $this->repo]);
        }
        if ($status !== 200) {
            throw new UpdateException('core.update.error.unreachable', ['status' => $status]);
        }

        $release = json_decode($body, true);
        $version = is_array($release) && is_string($release['tag_name'] ?? null) ? ltrim($release['tag_name'], 'v') : '';
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new UpdateException('core.update.error.invalid_response');
        }

        if (!version_compare($version, $this->currentVersion(), '>')) {
            return null;
        }

        $zipName = 'modulento-' . $version . '.zip';
        $urls = [];
        foreach ($release['assets'] ?? [] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null) && is_string($asset['url'] ?? null)) {
                $urls[$asset['name']] = $asset['url'];
            }
        }
        if (!isset($urls[$zipName], $urls[$zipName . '.sha256'])) {
            throw new UpdateException('core.update.error.assets_missing', ['version' => $version]);
        }

        return [
            'version' => $version,
            'published_at' => (string) ($release['published_at'] ?? ''),
            'changelog' => (string) ($release['body'] ?? ''),
            'zip_url' => $urls[$zipName],
            'sha256_url' => $urls[$zipName . '.sha256'],
        ];
    }

    /**
     * Re-checks for an update itself (never trusts a version handed in by
     * a request) and, if one exists, downloads, verifies, backs up, applies
     * and migrates it.
     *
     * @return array{success: bool, stage: string, message_key: string, params: array<string, string|int>, backup_path: ?string}
     */
    public function applyUpdate(): array
    {
        if ($this->isDevelopmentCheckout()) {
            return $this->result(false, 'refused', 'core.update.error.dev_checkout');
        }

        $this->ensureDir($this->root . '/var/updates');

        $lockPath = $this->lockPath();
        $lockHandle = fopen($lockPath, 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if ($lockHandle !== false) {
                fclose($lockHandle);
            }

            return $this->result(false, 'locked', 'core.update.error.locked');
        }

        $stage = 'check';
        $version = '';
        $backupPath = null;

        try {
            $meta = $this->checkForUpdate();
            if ($meta === null) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                @unlink($lockPath);

                return $this->result(false, 'up_to_date', 'core.update.up_to_date');
            }
            $version = $meta['version'];

            // Best effort - on a locked-down host set_time_limit() may be
            // disabled. An interrupted run is not prevented, it is reported
            // through the stale-lock diagnostic and fixed by running again.
            @set_time_limit(0);
            ignore_user_abort(true);

            $stage = 'downloading';
            $this->writeLockInfo($lockPath, $stage, $version);
            $sha256 = $this->downloadChecksum($meta['sha256_url']);
            $zipPath = $this->download($meta['zip_url'], $version);

            $backupPath = $this->installPackage($zipPath, $version, $sha256, function (string $next) use (&$stage, $lockPath, $version): void {
                $stage = $next;
                $this->writeLockInfo($lockPath, $next, $version);
            });

            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            @unlink($lockPath);

            return $this->result(true, 'done', 'core.update.done', ['version' => $version], $backupPath);
        } catch (Throwable $e) {
            // The lock file stays (with the failed stage) so the update
            // page can show where it stopped. Every stage starts from
            // scratch, so running the update again is the recovery path.
            $this->writeLockInfo($lockPath, 'failed:' . $stage, $version);
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            error_log('Update failed at stage ' . $stage . ': ' . $e);

            return $e instanceof UpdateException
                ? $this->result(false, $stage, $e->messageKey, $e->params, $backupPath)
                : $this->result(false, $stage, 'core.update.error.unexpected', ['stage' => $stage], $backupPath);
        }
    }

    /**
     * Verifies and applies an already downloaded package.
     *
     * @param (Closure(string): void)|null $onStage told the name of each stage before it starts
     * @return string path of the backup taken before any file was replaced
     * @throws UpdateException
     */
    public function installPackage(string $zipPath, string $version, string $sha256, ?Closure $onStage = null): string
    {
        $onStage ??= static function (string $stage): void {
        };

        $onStage('verifying');
        $actual = hash_file('sha256', $zipPath);
        if ($actual === false || !hash_equals(strtolower($sha256), $actual)) {
            @unlink($zipPath);
            throw new UpdateException('core.update.error.checksum');
        }

        $onStage('extracting');
        $extractDir = $this->extract($zipPath, $version);

        $onStage('checking_structure');
        $this->verifyStructure($extractDir, $version);

        $onStage('backing_up');
        $backupPath = $this->backupCurrentTree();

        $onStage('copying');
        $this->copyTree($extractDir, $this->root);
        // Without this, PHP keeps running the previous version's compiled
        // files until the opcache revalidates them on its own.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $onStage('migrating');
        ($this->migrate)();

        $onStage('finishing');
        $this->writeVersion($version);
        @unlink($zipPath);
        $this->removeDir($extractDir);

        return $backupPath;
    }

    /** @return array{success: bool, stage: string, message_key: string, params: array<string, string|int>, backup_path: ?string} */
    private function result(bool $success, string $stage, string $messageKey, array $params = [], ?string $backupPath = null): array
    {
        return ['success' => $success, 'stage' => $stage, 'message_key' => $messageKey, 'params' => $params, 'backup_path' => $backupPath];
    }

    private function lockPath(): string
    {
        return $this->root . '/var/updates/update.lock';
    }

    private function writeLockInfo(string $lockPath, string $stage, string $version): void
    {
        file_put_contents($lockPath, json_encode([
            'stage' => $stage,
            'release_version' => $version,
            'started_at' => date('c'),
        ]));
    }

    /** @return array<int, string> */
    private function headers(string $accept): array
    {
        $headers = [
            'Accept: ' . $accept,
            'User-Agent: Modulento-Updater',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        // Only needed while the repository is private: a fine-grained
        // token with read-only "Contents" access to it.
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        return $headers;
    }

    /** @return array<int, mixed> curl options shared by every request */
    private function curlOptions(string $accept, int $timeout): array
    {
        return [
            CURLOPT_HTTPHEADER => $this->headers($accept),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            // This connection delivers executable application code.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // An asset download answers with a redirect to GitHub's file
            // storage. curl drops the Authorization header when the host
            // changes, so the token never leaves api.github.com.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
        ];
    }

    /** @return array{0: int, 1: string} HTTP status (0 if the request failed) and body */
    private function httpGet(string $url, string $accept): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->curlOptions($accept, 20) + [CURLOPT_RETURNTRANSFER => true]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [is_string($body) ? $status : 0, is_string($body) ? $body : ''];
    }

    private function downloadChecksum(string $url): string
    {
        [$status, $body] = $this->httpGet($url, 'application/octet-stream');

        // "sha256sum" format: the hash, then the file name.
        if ($status !== 200 || preg_match('/^([0-9a-f]{64})\b/i', trim($body), $matches) !== 1) {
            throw new UpdateException('core.update.error.download', ['reason' => 'sha256, HTTP ' . $status]);
        }

        return strtolower($matches[1]);
    }

    private function download(string $url, string $version): string
    {
        $this->ensureDir($this->root . '/var/updates/staging');
        $zipPath = $this->root . '/var/updates/staging/modulento-' . $version . '.zip';

        $fh = fopen($zipPath, 'wb');
        if ($fh === false) {
            throw new UpdateException('core.update.error.not_writable', ['path' => 'var/updates/staging']);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $this->curlOptions('application/octet-stream', 300) + [CURLOPT_FILE => $fh]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $status !== 200) {
            @unlink($zipPath);
            throw new UpdateException('core.update.error.download', ['reason' => $error !== '' ? $error : 'HTTP ' . $status]);
        }

        return $zipPath;
    }

    /**
     * Extraction with zip-slip AND symlink protection: every entry name is
     * validated before extractTo() is called, and an entry whose Unix mode
     * marks it as a symlink is rejected - a path check on the name alone
     * would not catch a symlink that points outside the extraction
     * directory under a harmless-looking name.
     */
    private function extract(string $zipPath, string $version): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('core.update.error.archive');
        }

        $safeNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            // The mode bits only mean what they look like when the entry
            // was written on Unix, which every package built by
            // release.yml is. Anything else is rejected outright.
            if (!$this->isSafeZipEntryName($name)
                || !$zip->getExternalAttributesIndex($i, $opsys, $attr)
                || $opsys !== ZipArchive::OPSYS_UNIX
                || (((int) $attr >> 16) & 0xF000) === 0xA000) {
                $zip->close();
                throw new UpdateException('core.update.error.unsafe_entry', ['name' => $name]);
            }

            $safeNames[] = $name;
        }

        $extractDir = $this->root . '/var/updates/extract/' . $version;
        $this->removeDir($extractDir);
        $this->ensureDir($extractDir);

        $extracted = $zip->extractTo($extractDir, $safeNames);
        $zip->close();
        if (!$extracted) {
            throw new UpdateException('core.update.error.archive');
        }

        return $extractDir;
    }

    private function isSafeZipEntryName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) {
            return false;
        }
        if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) === 1) {
            return false;
        }

        return !in_array('..', explode('/', $name), true);
    }

    private function verifyStructure(string $extractDir, string $expectedVersion): void
    {
        foreach (self::REQUIRED_PACKAGE_ENTRIES as $entry) {
            if (!file_exists($extractDir . '/' . $entry)) {
                throw new UpdateException('core.update.error.incomplete', ['entry' => $entry]);
            }
        }

        if (trim((string) file_get_contents($extractDir . '/VERSION')) !== $expectedVersion) {
            throw new UpdateException('core.update.error.version_mismatch');
        }
    }

    private function backupCurrentTree(): string
    {
        $this->ensureDir($this->root . '/var/updates/backups');
        $backupPath = $this->root . '/var/updates/backups/backup-' . $this->currentVersion() . '-' . date('Ymd-His') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new UpdateException('core.update.error.not_writable', ['path' => 'var/updates/backups']);
        }

        foreach ($this->walkFiles($this->root, self::BACKUP_EXCLUDE_PREFIXES, []) as $relative => $absolute) {
            $zip->addFile($absolute, $relative);
        }
        if (!$zip->close()) {
            throw new UpdateException('core.update.error.not_writable', ['path' => 'var/updates/backups']);
        }

        // File names embed a sortable timestamp, so a string sort is
        // chronological.
        $files = glob($this->root . '/var/updates/backups/backup-*.zip') ?: [];
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - self::BACKUP_RETENTION)) as $file) {
            @unlink($file);
        }

        return $backupPath;
    }

    private function copyTree(string $fromDir, string $toDir): void
    {
        foreach ($this->walkFiles($fromDir, self::COPY_EXCLUDE_PREFIXES, self::COPY_EXCLUDE_FILES) as $relative => $absolute) {
            $target = $toDir . '/' . $relative;
            $this->ensureDir(dirname($target));
            if (!copy($absolute, $target)) {
                throw new UpdateException('core.update.error.not_writable', ['path' => $relative]);
            }
        }
    }

    private function writeVersion(string $version): void
    {
        $path = $this->root . '/VERSION';
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $version) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new UpdateException('core.update.error.not_writable', ['path' => 'VERSION']);
        }
    }

    /**
     * @param string[] $excludePrefixes relative directory prefixes (with
     *        trailing slash) that are not even descended into
     * @param string[] $excludeFiles exact relative file paths to skip
     * @return Generator<string, string> relative path => absolute path, files only
     */
    private function walkFiles(string $root, array $excludePrefixes, array $excludeFiles): Generator
    {
        $root = rtrim($root, '/');

        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $current) use ($root, $excludePrefixes, $excludeFiles): bool {
                $relative = ltrim(substr($current->getPathname(), strlen($root)), '/');
                $checkPath = $current->isDir() ? $relative . '/' : $relative;

                foreach ($excludePrefixes as $prefix) {
                    if (str_starts_with($checkPath, $prefix)) {
                        return false;
                    }
                }

                return $current->isDir() || !in_array($relative, $excludeFiles, true);
            }
        );

        foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY) as $item) {
            yield ltrim(substr($item->getPathname(), strlen($root)), '/') => $item->getPathname();
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UpdateException('core.update.error.not_writable', ['path' => str_replace($this->root . '/', '', $dir)]);
        }
    }
}
