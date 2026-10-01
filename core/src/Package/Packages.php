<?php

declare(strict_types=1);

namespace Modulento\Core\Package;

use Modulento\Core\App;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\ReleaseClient;
use Modulento\Core\Support\SafeArchive;
use Modulento\Core\Support\UpdateException;
use PDO;

/**
 * Installs and updates extensions and themes from the releases of their
 * own GitHub repositories. A package is a release whose zip contains the
 * extension's or theme's folder content (extension.json or theme.json at
 * the top) together with a ".sha256" file.
 *
 * Installing a package means running someone else's code on this server.
 * Only repositories matching PACKAGE_SOURCES can be installed from, a
 * package keeps the repository it first came from, and what the core
 * ships itself cannot be replaced by a package.
 */
final class Packages
{
    public const KINDS = ['extension', 'theme'];

    /** Shipped with the core: a core update would overwrite a package of the same name again. */
    private const SHIPPED = ['extension' => ['example', 'freelancer'], 'theme' => ['default', 'admin']];

    /** @param string[] $allowedSources patterns like "owner/*" or "owner/name" */
    public function __construct(
        private PDO $db,
        private ReleaseClient $releases,
        private string $extensionsDir,
        private string $themesDir,
        private string $workDir,
        private array $allowedSources
    ) {
    }

    /** @return string[] */
    public function allowedSources(): array
    {
        return $this->allowedSources;
    }

    public function isAllowed(string $repo): bool
    {
        if (!ReleaseClient::isRepository($repo)) {
            return false;
        }

        foreach ($this->allowedSources as $pattern) {
            if (fnmatch($pattern, $repo, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{kind: string, id: string, repo: string, version: string, installed_at: string}> */
    public function installed(): array
    {
        return $this->db->query('SELECT * FROM package ORDER BY kind, id')->fetchAll();
    }

    public function find(string $kind, string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM package WHERE kind = :kind AND id = :id');
        $stmt->execute(['kind' => $kind, 'id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** The newest released version of a repository. @throws UpdateException */
    public function latestVersion(string $repo): string
    {
        return $this->releases->latest($repo)['version'];
    }

    /**
     * Downloads the newest release of a repository and installs it, or
     * updates the package it already is.
     *
     * @return array{kind: string, id: string, version: string, updated: bool}
     * @throws UpdateException
     */
    public function install(string $repo): array
    {
        if (!$this->isAllowed($repo)) {
            throw new UpdateException('core.package.error.source', ['repo' => $repo]);
        }

        $release = $this->releases->latest($repo);

        // The one zip of the release that comes with a checksum.
        $zipName = null;
        foreach (array_keys($release['assets']) as $name) {
            if (str_ends_with($name, '.zip') && isset($release['assets'][$name . '.sha256'])) {
                $zipName = $name;
                break;
            }
        }
        if ($zipName === null) {
            throw new UpdateException('core.update.error.assets_missing', ['version' => $release['version']]);
        }

        $this->ensureDir($this->workDir . '/staging');
        $zipPath = $this->workDir . '/staging/package-' . bin2hex(random_bytes(6)) . '.zip';

        $sha256 = $this->releases->checksum($release['assets'][$zipName . '.sha256']);
        $this->releases->download($release['assets'][$zipName], $zipPath);

        return $this->installArchive($zipPath, $sha256, $repo, $release['version']);
    }

    /**
     * Verifies and installs an already downloaded package.
     *
     * @return array{kind: string, id: string, version: string, updated: bool}
     * @throws UpdateException
     */
    public function installArchive(string $zipPath, string $sha256, string $repo, string $version): array
    {
        $actual = hash_file('sha256', $zipPath);
        if ($actual === false || !hash_equals(strtolower($sha256), $actual)) {
            @unlink($zipPath);
            throw new UpdateException('core.update.error.checksum');
        }

        $extractDir = $this->workDir . '/extract/package-' . bin2hex(random_bytes(6));

        try {
            SafeArchive::extract($zipPath, $extractDir);
            [$kind, $id] = $this->inspect($extractDir, $version);

            $existing = $this->find($kind, $id);
            // A name that is taken stays with the repository it came from.
            if ($existing !== null && strcasecmp($existing['repo'], $repo) !== 0) {
                throw new UpdateException('core.package.error.other_source', ['id' => $id, 'repo' => $existing['repo']]);
            }

            $this->swap($extractDir, ($kind === 'extension' ? $this->extensionsDir : $this->themesDir) . '/' . $id, $kind . '-' . $id);
        } finally {
            @unlink($zipPath);
            SafeArchive::removeDir($extractDir);
        }

        $delete = $this->db->prepare('DELETE FROM package WHERE kind = :kind AND id = :id');
        $delete->execute(['kind' => $kind, 'id' => $id]);
        $insert = $this->db->prepare('INSERT INTO package (kind, id, repo, version, installed_at) VALUES (:kind, :id, :repo, :version, :now)');
        $insert->execute(['kind' => $kind, 'id' => $id, 'repo' => $repo, 'version' => $version, 'now' => Clock::now()]);

        return ['kind' => $kind, 'id' => $id, 'version' => $version, 'updated' => $existing !== null];
    }

    /**
     * Takes a package off the server. Tables an extension created stay.
     * The caller makes sure the extension is disabled or the theme not
     * active.
     */
    public function remove(string $kind, string $id): void
    {
        if ($this->find($kind, $id) === null) {
            return;
        }

        $dir = ($kind === 'extension' ? $this->extensionsDir : $this->themesDir) . '/' . $id;
        if (is_dir($dir)) {
            $this->ensureDir($this->workDir . '/backups/packages');
            $backup = $this->workDir . '/backups/packages/' . $kind . '-' . $id . '-' . date('Ymd-His');
            if (!@rename($dir, $backup)) {
                SafeArchive::removeDir($dir);
            }
        }

        $stmt = $this->db->prepare('DELETE FROM package WHERE kind = :kind AND id = :id');
        $stmt->execute(['kind' => $kind, 'id' => $id]);
    }

    /**
     * What an unpacked package is, after checking that it is one this
     * core can take.
     *
     * @return array{0: string, 1: string} kind and id
     */
    private function inspect(string $dir, string $version): array
    {
        $isExtension = is_file($dir . '/extension.json');
        $isTheme = is_file($dir . '/theme.json');
        if ($isExtension === $isTheme) {
            throw new UpdateException('core.package.error.manifest');
        }

        $kind = $isExtension ? 'extension' : 'theme';
        $manifest = json_decode((string) file_get_contents($dir . '/' . $kind . '.json'), true);
        $id = is_array($manifest) && is_string($manifest['id'] ?? null) ? $manifest['id'] : '';

        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/', $id) !== 1 || $id === 'core') {
            throw new UpdateException('core.package.error.manifest');
        }
        if (in_array($id, self::SHIPPED[$kind], true)) {
            throw new UpdateException('core.package.error.shipped', ['id' => $id]);
        }
        // What the package says about itself has to match what the
        // release announced.
        if (($manifest['version'] ?? null) !== $version) {
            throw new UpdateException('core.update.error.version_mismatch');
        }

        if ($kind === 'extension') {
            if (($manifest['api'] ?? null) !== App::API_VERSION) {
                throw new UpdateException('core.package.error.api', ['needed' => (string) ($manifest['api'] ?? '?'), 'provided' => App::API_VERSION]);
            }
            if (!is_file($dir . '/src/Extension.php')) {
                throw new UpdateException('core.package.error.manifest');
            }
        }

        return [$kind, $id];
    }

    /** Puts the new folder in place of the old one, which is kept as a backup. */
    private function swap(string $newDir, string $targetDir, string $label): void
    {
        $backup = null;

        if (is_dir($targetDir)) {
            $this->ensureDir($this->workDir . '/backups/packages');
            $backup = $this->workDir . '/backups/packages/' . $label . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
            if (!@rename($targetDir, $backup)) {
                throw new UpdateException('core.update.error.not_writable', ['path' => basename(dirname($targetDir)) . '/' . basename($targetDir)]);
            }
        }

        if (!@rename($newDir, $targetDir) && !$this->copyDir($newDir, $targetDir)) {
            // Put back what was there.
            SafeArchive::removeDir($targetDir);
            if ($backup !== null) {
                @rename($backup, $targetDir);
            }
            throw new UpdateException('core.update.error.not_writable', ['path' => basename(dirname($targetDir))]);
        }
    }

    /** For when rename() cannot move across file systems. */
    private function copyDir(string $from, string $to): bool
    {
        if (!@mkdir($to, 0755, true) && !is_dir($to)) {
            return false;
        }

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $source = $from . '/' . $entry;
            $ok = is_dir($source) ? $this->copyDir($source, $to . '/' . $entry) : @copy($source, $to . '/' . $entry);
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UpdateException('core.update.error.not_writable', ['path' => basename($dir)]);
        }
    }
}
