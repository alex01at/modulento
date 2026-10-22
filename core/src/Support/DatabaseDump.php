<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use PDO;

/**
 * A plain-SQL dump of the whole database, written straight to a file - no
 * mysqldump binary, since an operator on ordinary shared hosting usually
 * has no shell to run one. Schema (one CREATE TABLE per table, from the
 * database's own SHOW CREATE TABLE) and data (batched INSERTs, named
 * columns so column order in the database never matters on restore).
 */
final class DatabaseDump
{
    private const BATCH_SIZE = 500;

    public function __construct(private PDO $db)
    {
    }

    public function toFile(string $path): void
    {
        $out = fopen($path, 'w');
        if ($out === false) {
            throw new BackupException('core.backup.error.not_writable', ['path' => $path]);
        }

        fwrite($out, "-- Modulento database backup\n-- Created " . Clock::now() . " UTC\n\n");
        fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

        foreach ($this->tables() as $table) {
            $this->writeTable($out, $table);
        }

        fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($out);
    }

    /** @return list<string> */
    private function tables(): array
    {
        return $this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param resource $out */
    private function writeTable($out, string $table): void
    {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';

        $create = $this->db->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_ASSOC);
        fwrite($out, "--\n-- {$table}\n--\nDROP TABLE IF EXISTS {$quotedTable};\n" . $create['Create Table'] . ";\n\n");

        $columns = array_map(
            fn (array $column) => '`' . str_replace('`', '``', $column['Field']) . '`',
            $this->db->query('SHOW COLUMNS FROM ' . $quotedTable)->fetchAll(PDO::FETCH_ASSOC)
        );
        if ($columns === []) {
            return;
        }

        $stmt = $this->db->query('SELECT * FROM ' . $quotedTable);
        $batch = [];
        foreach ($stmt as $row) {
            $batch[] = '(' . implode(',', array_map(fn (mixed $v) => $v === null ? 'NULL' : $this->db->quote((string) $v), $row)) . ')';
            if (count($batch) >= self::BATCH_SIZE) {
                $this->flush($out, $quotedTable, $columns, $batch);
                $batch = [];
            }
        }
        $this->flush($out, $quotedTable, $columns, $batch);
        fwrite($out, "\n");
    }

    /**
     * @param resource $out
     * @param list<string> $columns
     * @param list<string> $batch
     */
    private function flush($out, string $quotedTable, array $columns, array $batch): void
    {
        if ($batch === []) {
            return;
        }
        fwrite($out, "INSERT INTO {$quotedTable} (" . implode(',', $columns) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
    }
}
