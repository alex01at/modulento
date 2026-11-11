<?php

declare(strict_types=1);

namespace Modulento\Core\Catalogue;

/**
 * Bulk-creates top-level categories from a CSV file (name, description,
 * icon), one language at a time - whichever the uploading account is
 * using; an operator who needs more just imports once per language,
 * switching the locale beforehand (the same convention
 * Shop\CsvImport's own doc comment describes for products). Creates
 * only - a name that already exists in this language is skipped rather
 * than merged or updated, so a second import can never silently change
 * or duplicate something that is already there.
 */
final class CategoryCsvImport
{
    public const TEMPLATE_HEADER = ['name', 'description', 'icon'];
    private const MAX_ROWS = 500;

    /** @return array{created: int, skipped: list<string>, errors: list<array{row: int, key: string, params: array}>, fatal: ?string} */
    public function import(string $path, string $locale, Categories $categories): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return ['created' => 0, 'skipped' => [], 'errors' => [], 'fatal' => 'core.admin.categories.import.error.file'];
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if ($header === false) {
            fclose($handle);

            return ['created' => 0, 'skipped' => [], 'errors' => [], 'fatal' => 'core.admin.categories.import.error.empty'];
        }
        $header = array_map(fn (string $name) => mb_strtolower(trim($name)), $header);

        $existingNames = [];
        foreach ($categories->all() as $category) {
            $name = $category['translations'][$locale]['name'] ?? null;
            if ($name !== null) {
                $existingNames[mb_strtolower($name)] = true;
            }
        }

        $created = 0;
        $skipped = [];
        $errors = [];
        $position = 0;
        $rowNumber = 1;

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false && $rowNumber <= self::MAX_ROWS) {
            $rowNumber++;
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $fields = [];
            foreach ($header as $index => $name) {
                $fields[$name] = trim((string) ($row[$index] ?? ''));
            }
            $name = (string) ($fields['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (isset($existingNames[mb_strtolower($name)])) {
                $skipped[] = $name;
                continue;
            }

            $result = $categories->save(null, null, $position, (string) ($fields['icon'] ?? ''), [
                $locale => ['name' => $name, 'slug' => '', 'description' => (string) ($fields['description'] ?? '')],
            ]);

            if ($result['errors'] !== []) {
                foreach ($result['errors'] as $error) {
                    $errors[] = ['row' => $rowNumber, 'key' => $error['key'], 'params' => $error['params'] + ['name' => $name]];
                }
                continue;
            }

            $existingNames[mb_strtolower($name)] = true;
            $created++;
            $position++;
        }
        fclose($handle);

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors, 'fatal' => null];
    }
}
