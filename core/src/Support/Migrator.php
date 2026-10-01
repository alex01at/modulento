<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use Modulento\Core\Extension\ExtensionManager;
use PDO;

/**
 * Forward-only SQL migrations, tracked per source: "core" for core/migrations
 * and the extension id for an extension's own migrations folder. Calling
 * run() again is always safe - already-applied files are skipped.
 */
final class Migrator
{
    /** @return string[] filenames newly applied by this call */
    public static function run(PDO $db, string $source, string $migrationsDir): array
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS migration (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(64) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                applied_at DATETIME NOT NULL,
                UNIQUE KEY uq_migration (source, filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $stmt = $db->prepare('SELECT filename FROM migration WHERE source = :source');
        $stmt->execute(['source' => $source]);
        $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files);

        $newlyApplied = [];

        foreach ($files as $file) {
            $filename = basename($file);

            if (in_array($filename, $applied, true)) {
                continue;
            }

            foreach (self::statements((string) file_get_contents($file)) as $statement) {
                $db->exec($statement);
            }

            $insert = $db->prepare('INSERT INTO migration (source, filename, applied_at) VALUES (:source, :filename, NOW())');
            $insert->execute(['source' => $source, 'filename' => $filename]);

            $newlyApplied[] = $filename;
        }

        return $newlyApplied;
    }

    /**
     * Core migrations plus those of every enabled extension - the one
     * call behind bin/migrate.php and the updater, so a release that ships
     * a schema change needs nothing else.
     *
     * @return array<string, string[]> source => filenames newly applied
     */
    public static function runAll(PDO $db, string $root): array
    {
        $applied = ['core' => self::run($db, 'core', $root . '/core/migrations')];

        $extensions = new ExtensionManager($db, $root . '/extensions');
        $discovered = $extensions->discover();

        foreach ($extensions->enabledIds() as $id) {
            if (isset($discovered[$id]) && is_dir($discovered[$id]->dir . '/migrations')) {
                $applied[$id] = self::run($db, $id, $discovered[$id]->dir . '/migrations');
            }
        }

        return $applied;
    }

    /**
     * Splits a migration file into single statements. Running the whole
     * file through one exec() would only report an error from the first
     * statement and silently ignore failures in every later one.
     * Convention: a statement ends with ";" at the end of a line.
     *
     * @return string[]
     */
    public static function statements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                $lines[] = $line;
            }
        }

        $parts = preg_split('/;[ \t]*(?:\n|$)/', implode("\n", $lines));

        return array_values(array_filter(array_map('trim', $parts), fn (string $part) => $part !== ''));
    }
}
