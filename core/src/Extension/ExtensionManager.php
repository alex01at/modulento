<?php

declare(strict_types=1);

namespace Modulento\Core\Extension;

use InvalidArgumentException;
use Modulento\Core\App;
use Modulento\Core\Support\Migrator;
use PDO;
use PDOException;
use RuntimeException;

final class ExtensionManager
{
    /** @var array<string, Manifest>|null */
    private ?array $discovered = null;
    /** @var array<string, string> folder name => why its manifest was rejected */
    private array $invalid = [];
    /** @var array<string, Manifest> */
    private array $loaded = [];

    public function __construct(private PDO $db, private string $extensionsDir)
    {
    }

    /** @return array<string, Manifest> every valid extension folder, keyed by id */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $this->discovered = [];
        foreach (glob($this->extensionsDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            try {
                $manifest = Manifest::fromDir($dir);
                $this->discovered[$manifest->id] = $manifest;
            } catch (InvalidArgumentException $e) {
                $this->invalid[basename($dir)] = $e->getMessage();
            }
        }
        ksort($this->discovered);

        return $this->discovered;
    }

    /** @return array<string, string> folder name => why its manifest was rejected */
    public function invalid(): array
    {
        $this->discover();

        return $this->invalid;
    }

    /** @return string[] */
    public function enabledIds(): array
    {
        try {
            return $this->db->query('SELECT id FROM extension WHERE enabled = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            // 42S02: the core migrations have not been run yet.
            if ($e->getCode() === '42S02') {
                return [];
            }
            throw $e;
        }
    }

    /** @return array<string, Manifest> the extensions registered in this request */
    public function loaded(): array
    {
        return $this->loaded;
    }

    public function loadEnabled(App $app): void
    {
        $discovered = $this->discover();

        foreach ($this->enabledIds() as $id) {
            $manifest = $discovered[$id] ?? null;
            // An enabled extension whose folder was removed or whose
            // interface version no longer matches is skipped rather than
            // taking the whole site down; the admin page shows it.
            if ($manifest === null || $manifest->api !== App::API_VERSION) {
                continue;
            }

            $extension = $this->instantiate($manifest);
            $app->translator->load($manifest->dir . '/lang', $manifest->id);
            $this->loaded[$id] = $manifest;
            $extension->register(new Registrar($app, $manifest));
        }
    }

    /**
     * Runs the extension's migrations and marks it enabled. An "app" is
     * exclusive: any other enabled app is switched off for it (its data
     * stays). An "addon" never switches anything off - any number of
     * addons, and one app, can be active together. Loaded from the next
     * request on.
     *
     * @return string[] the apps that were switched off for it (always empty for an addon)
     */
    public function enable(string $id): array
    {
        $manifest = $this->discover()[$id] ?? throw new RuntimeException("Extension \"{$id}\" not found");

        if ($manifest->api !== App::API_VERSION) {
            throw new RuntimeException(
                "Extension \"{$id}\" needs interface version {$manifest->api}, this core provides " . App::API_VERSION
            );
        }

        $this->instantiate($manifest);

        if (is_dir($manifest->dir . '/migrations')) {
            Migrator::run($this->db, $manifest->id, $manifest->dir . '/migrations');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO extension (id, version, enabled, enabled_at) VALUES (:id, :version, 1, NOW())
             ON DUPLICATE KEY UPDATE version = VALUES(version), enabled = 1, enabled_at = NOW()'
        );
        $stmt->execute(['id' => $manifest->id, 'version' => $manifest->version]);

        if ($manifest->type !== 'app') {
            return [];
        }

        $others = self::appsToSwitchOff($this->discover(), $this->enabledIds(), $manifest->id);
        if ($others !== []) {
            $stmt = $this->db->prepare(
                'UPDATE extension SET enabled = 0 WHERE enabled = 1 AND id IN (' . implode(',', array_fill(0, count($others), '?')) . ')'
            );
            $stmt->execute($others);
        }

        return $others;
    }

    /**
     * The currently enabled ids, other than the one being enabled, that are
     * apps (an id no longer discoverable counts as an app, as every
     * extension used to be before addons existed - the safe default).
     *
     * @param array<string, Manifest> $discovered
     * @param string[] $enabledIds
     * @return string[]
     */
    public static function appsToSwitchOff(array $discovered, array $enabledIds, string $enablingId): array
    {
        return array_values(array_filter(
            array_diff($enabledIds, [$enablingId]),
            fn (string $otherId) => ($discovered[$otherId]->type ?? 'app') === 'app'
        ));
    }

    /** Stops loading the extension. Its tables and data stay untouched. */
    public function disable(string $id): void
    {
        $stmt = $this->db->prepare('UPDATE extension SET enabled = 0 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function instantiate(Manifest $manifest): Extension
    {
        $namespace = $manifest->namespace;
        $srcDir = $manifest->dir . '/src/';

        // Extensions are plain folders, installable without Composer, so
        // each one gets its own PSR-4 autoloader for its namespace.
        spl_autoload_register(static function (string $class) use ($namespace, $srcDir): void {
            if (!str_starts_with($class, $namespace)) {
                return;
            }
            $file = $srcDir . str_replace('\\', '/', substr($class, strlen($namespace))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });

        if (!class_exists($manifest->entry)) {
            throw new RuntimeException("Extension \"{$manifest->id}\": class {$manifest->entry} not found in src/Extension.php");
        }

        $extension = new ($manifest->entry)();
        if (!$extension instanceof Extension) {
            throw new RuntimeException("Extension \"{$manifest->id}\": {$manifest->entry} must implement " . Extension::class);
        }

        return $extension;
    }
}
