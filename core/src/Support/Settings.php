<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use PDO;
use PDOException;

/**
 * Settings an administrator changes in the browser (active theme, later
 * currency, fees, legal texts ...). .env only holds what is needed before
 * the database is reachable. Names are prefixed like language keys:
 * "core." or the extension id.
 */
final class Settings
{
    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private PDO $db)
    {
    }

    public function get(string $name, string $default = ''): string
    {
        return $this->all()[$name] ?? $default;
    }

    public function set(string $name, string $value): void
    {
        $exists = $this->db->prepare('SELECT COUNT(*) FROM setting WHERE name = :name');
        $exists->execute(['name' => $name]);

        $stmt = $this->db->prepare(
            (int) $exists->fetchColumn() > 0
                ? 'UPDATE setting SET value = :value WHERE name = :name'
                : 'INSERT INTO setting (name, value) VALUES (:name, :value)'
        );
        $stmt->execute(['name' => $name, 'value' => $value]);
        $this->values = null;
    }

    /** @return array<string, string> */
    private function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        try {
            $this->values = $this->db->query('SELECT name, value FROM setting')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            // 42S02: the table arrives with a migration that has not run
            // yet (right after an update's files were copied).
            if ($e->getCode() !== '42S02') {
                throw $e;
            }
            $this->values = [];
        }

        return $this->values;
    }
}
