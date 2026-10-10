<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use Modulento\Core\App;
use Modulento\Core\Package\Packages;

/**
 * Every component that can be updated - the core, and each installed
 * extension or theme - in one place. "Check" asks each release server once and
 * stores the answers; the Updates page only ever reads them. The dashboard
 * reads them too, but repeats the check itself first when the last one is
 * a day old or more (see isStale()), so an administrator never has to
 * remember to ask.
 */
final class UpdateChecks
{
    private const SETTING = 'core.update_check';
    /** How long a check stands before the dashboard quietly repeats it on its own. */
    private const STALE_AFTER_SECONDS = 86400;

    public function __construct(private App $app)
    {
    }

    /**
     * Asks the core's and every package's release server for the newest
     * version, and stores the answers.
     *
     * @return list<string> problems, already translated
     */
    public function refresh(): array
    {
        $trans = fn (string $key, array $params = []) => $this->app->translator->trans($key, $params);
        $problems = [];

        $latestCore = null;
        try {
            $latestCore = $this->coreUpdater()->checkForUpdate()['version'] ?? null;
            $latestCore ??= $this->installedCore();
        } catch (UpdateException $e) {
            $problems[] = $trans($e->messageKey, $e->params);
        }

        $latestPackages = [];
        foreach ($this->app->packages->installed() as $package) {
            if ($package['repo'] === Packages::UPLOAD_SOURCE) {
                continue;
            }
            try {
                $latestPackages[$package['kind'] . ':' . $package['id']] = $this->app->packages->latestVersion($package['repo']);
            } catch (UpdateException $e) {
                $problems[] = $package['repo'] . ': ' . $trans($e->messageKey, $e->params);
            }
        }

        $this->app->settings->set(self::SETTING, (string) json_encode([
            'checked_at' => gmdate('Y-m-d H:i'),
            'core' => $latestCore ?? '',
            'packages' => $latestPackages,
        ], JSON_THROW_ON_ERROR));

        return $problems;
    }

    /** When the last check ran (UTC), or null before any check. */
    public function checkedAt(): ?string
    {
        return $this->stored()['checked_at'] ?? null;
    }

    /** Never checked, or the last check is old enough that the dashboard should ask again. */
    public function isStale(): bool
    {
        $checkedAt = $this->checkedAt();

        return $checkedAt === null || strtotime($checkedAt . ' UTC') < time() - self::STALE_AFTER_SECONDS;
    }

    /**
     * Every installed component - the core first, then extensions and themes -
     * with its installed version, the newest one the last check found, and
     * whether that is newer.
     *
     * @return list<array{kind: string, id: string, installed: string, latest: ?string, newer: bool, action: string, repo: string}>
     */
    public function components(): array
    {
        $stored = $this->stored();
        $installedCore = $this->installedCore();
        $latestCore = (string) ($stored['core'] ?? '') ?: null;

        $found = [[
            'kind' => 'core', 'id' => 'core', 'repo' => (string) ($this->app->config['update']['repo'] ?? ''),
            'installed' => $installedCore, 'latest' => $latestCore,
            'newer' => $latestCore !== null && version_compare($latestCore, $installedCore, '>'),
            'action' => '/admin/updates/apply',
        ]];

        foreach ($this->app->packages->installed() as $package) {
            $latest = $this->latest($package['kind'], $package['id']);
            $found[] = [
                'kind' => $package['kind'], 'id' => $package['id'], 'repo' => (string) $package['repo'],
                'installed' => (string) $package['version'], 'latest' => $latest,
                'newer' => $latest !== null && version_compare($latest, (string) $package['version'], '>'),
                'action' => '/admin/packages/' . $package['kind'] . '/' . $package['id'] . '/update',
            ];
        }

        return $found;
    }

    /**
     * Components with a newer release than the one installed.
     *
     * @return list<array{kind: string, id: string, installed: string, latest: ?string, newer: bool, action: string, repo: string}>
     */
    public function available(): array
    {
        return array_values(array_filter($this->components(), fn (array $component) => $component['newer']));
    }

    /** The newest version the last check found for an extension or theme, or null. */
    public function latest(string $kind, string $id): ?string
    {
        $version = (string) ($this->stored()['packages'][$kind . ':' . $id] ?? '');

        return $version !== '' ? $version : null;
    }

    public function installedCore(): string
    {
        return Updater::installedVersion($this->app->config['app']['root']);
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $data = json_decode($this->app->settings->get(self::SETTING), true);

        return is_array($data) ? $data : [];
    }

    private function coreUpdater(): Updater
    {
        $config = $this->app->config;
        $db = $this->app->db;
        $root = $config['app']['root'];

        return new Updater(
            $root,
            $config['update']['repo'] ?? '',
            $config['update']['token'] ?? '',
            static function () use ($db, $root): void {
                Migrator::runAll($db, $root);
            }
        );
    }
}
