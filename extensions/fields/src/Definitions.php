<?php

declare(strict_types=1);

namespace Modulento\Fields;

use Modulento\Core\Content\Pages;
use Modulento\Core\Support\Clock;
use PDO;

/**
 * The types and fields the operator made: reading them, and the rules for
 * changing them. Keys are made from the first label and never change, since
 * offers and addresses refer to them.
 */
final class Definitions
{
    public const MAX_FIELDS = 40;
    public const MAX_OPTIONS = 60;
    private const LABEL_MAX = 100;

    /** @var list<TypeDefinition>|null */
    private ?array $types = null;

    public function __construct(private PDO $db)
    {
    }

    /** @return list<TypeDefinition> */
    public function types(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $typeLabels = $this->labels('x_fields_type_label', 'type_id');
        $fieldLabels = $this->labels('x_fields_field_label', 'field_id');

        $fields = [];
        foreach ($this->db->query('SELECT * FROM x_fields_field ORDER BY position, id')->fetchAll() as $row) {
            $options = json_decode((string) $row['options'], true);
            $fields[(int) $row['type_id']][] = new Field(
                (int) $row['id'], (int) $row['type_id'], $row['key_name'], $row['kind'],
                (bool) $row['required'], (bool) $row['filterable'], (bool) $row['sortable'], (bool) $row['in_card'], (bool) $row['is_price'],
                (int) $row['position'], $fieldLabels[(int) $row['id']] ?? [], self::cleanOptions($options),
            );
        }

        $types = [];
        foreach ($this->db->query('SELECT * FROM x_fields_type ORDER BY position, id')->fetchAll() as $row) {
            $id = (int) $row['id'];
            $types[] = new TypeDefinition($id, $row['key_name'], (int) $row['position'], $typeLabels[$id] ?? [], $fields[$id] ?? []);
        }

        return $this->types = $types;
    }

    public function type(int $id): ?TypeDefinition
    {
        foreach ($this->types() as $type) {
            if ($type->id === $id) {
                return $type;
            }
        }

        return null;
    }

    // --- Types --------------------------------------------------------------

    /**
     * @param array<string, mixed> $labels the name by locale, as sent
     * @param string[] $locales the languages of the site
     * @return array{id: ?int, errors: string[]}
     */
    public function saveType(?int $id, array $labels, int $position, array $locales, string $default): array
    {
        $labels = self::labelsFrom($labels, $locales);
        if (($labels[$default] ?? '') === '') {
            return ['id' => null, 'errors' => ['fields.error.label']];
        }

        $this->db->beginTransaction();
        if ($id === null) {
            $stmt = $this->db->prepare('INSERT INTO x_fields_type (key_name, position, created_at) VALUES (:key, :position, :now)');
            $stmt->execute(['key' => $this->uniqueKey($labels[$default], 'x_fields_type', null), 'position' => $position, 'now' => Clock::now()]);
            $id = (int) $this->db->lastInsertId();
        } else {
            $stmt = $this->db->prepare('UPDATE x_fields_type SET position = :position WHERE id = :id');
            $stmt->execute(['position' => $position, 'id' => $id]);
        }
        $this->replaceLabels('x_fields_type_label', 'type_id', $id, $labels);
        $this->db->commit();
        $this->types = null;

        return ['id' => $id, 'errors' => []];
    }

    /** Removes a type with its fields and all values; the caller makes sure no offer uses it. */
    public function deleteType(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM x_fields_type WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $this->types = null;
    }

    // --- Fields -------------------------------------------------------------

    /**
     * @param array<string, mixed> $input labels (by locale), kind (only for a new field), flags, position, options
     * @param string[] $locales
     * @return array{id: ?int, errors: string[]}
     */
    public function saveField(int $typeId, ?int $fieldId, array $input, array $locales, string $default): array
    {
        $type = $this->type($typeId);
        $existing = $fieldId !== null ? $type?->field($fieldId) : null;
        if ($type === null || ($fieldId !== null && $existing === null)) {
            return ['id' => null, 'errors' => ['fields.error.not_found']];
        }

        $errors = [];
        $labels = self::labelsFrom(is_array($input['labels'] ?? null) ? $input['labels'] : [], $locales);
        if (($labels[$default] ?? '') === '') {
            $errors[] = 'fields.error.label';
        }

        $kind = $existing?->kind ?? (string) ($input['kind'] ?? '');
        if (!in_array($kind, Field::KINDS, true)) {
            $errors[] = 'fields.error.kind';
        }
        if ($existing === null && count($type->fields) >= self::MAX_FIELDS) {
            $errors[] = 'fields.error.too_many';
        }

        $options = [];
        if ($kind === 'select') {
            $options = self::optionsFrom(is_array($input['options'] ?? null) ? $input['options'] : [], $locales, $default, $existing?->options ?? []);
            if ($options === null) {
                $errors[] = 'fields.error.option_label';
                $options = [];
            } elseif ($options === []) {
                $errors[] = 'fields.error.options';
            }
        }

        if ($errors !== []) {
            return ['id' => null, 'errors' => array_values(array_unique($errors))];
        }

        $flag = fn (string $name, bool $allowed = true): int => $allowed && !empty($input[$name]) ? 1 : 0;
        $row = [
            'required' => $flag('required'),
            'filterable' => $flag('filterable', in_array($kind, Field::FILTERABLE, true)),
            'sortable' => $flag('sortable', in_array($kind, Field::SORTABLE, true)),
            'in_card' => $flag('in_card'),
            'is_price' => $flag('is_price', $kind === 'number'),
            'position' => (int) ($input['position'] ?? 0),
            'options' => $kind === 'select' ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
        ];

        $this->db->beginTransaction();
        if ($existing === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO x_fields_field (type_id, key_name, kind, required, filterable, sortable, in_card, is_price, position, options)
                 VALUES (:type, :key, :kind, :required, :filterable, :sortable, :in_card, :is_price, :position, :options)'
            );
            $stmt->execute($row + ['type' => $typeId, 'kind' => $kind, 'key' => $this->uniqueFieldKey($typeId, $labels[$default])]);
            $fieldId = (int) $this->db->lastInsertId();
        } else {
            $stmt = $this->db->prepare(
                'UPDATE x_fields_field SET required = :required, filterable = :filterable, sortable = :sortable, in_card = :in_card,
                 is_price = :is_price, position = :position, options = :options WHERE id = :id'
            );
            $stmt->execute($row + ['id' => $fieldId]);
        }
        if ($row['is_price'] === 1) {
            // One price per type: it is the offer's "from" price.
            $stmt = $this->db->prepare('UPDATE x_fields_field SET is_price = 0 WHERE type_id = :type AND id <> :id');
            $stmt->execute(['type' => $typeId, 'id' => $fieldId]);
        }
        $this->replaceLabels('x_fields_field_label', 'field_id', $fieldId, $labels);
        $this->db->commit();
        $this->types = null;

        return ['id' => $fieldId, 'errors' => []];
    }

    /** Removes the field and, with it, what every offer has in it. */
    public function deleteField(int $typeId, int $fieldId): void
    {
        $stmt = $this->db->prepare('DELETE FROM x_fields_field WHERE id = :id AND type_id = :type');
        $stmt->execute(['id' => $fieldId, 'type' => $typeId]);
        $this->types = null;
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * Labels as sent (by locale), reduced to the site's languages, trimmed and cut.
     *
     * @param array<string, mixed> $sent
     * @param string[] $locales
     * @return array<string, string>
     */
    private static function labelsFrom(array $sent, array $locales): array
    {
        $labels = [];
        foreach ($locales as $code) {
            $text = is_string($sent[$code] ?? null) ? trim($sent[$code]) : '';
            if ($text !== '') {
                $labels[$code] = mb_substr($text, 0, self::LABEL_MAX);
            }
        }

        return $labels;
    }

    /**
     * The rows of the option editor: an existing option keeps its value, a new
     * one gets a value made from its label in the default language. A row
     * without any label is removed.
     *
     * @param array<mixed> $rows
     * @param string[] $locales
     * @param list<array{value: string, labels: array<string, string>}> $current
     * @return list<array{value: string, labels: array<string, string>}>|null null: a row lacks its label in the default language
     */
    private static function optionsFrom(array $rows, array $locales, string $default, array $current): ?array
    {
        $known = array_column($current, 'value');
        $options = [];
        $taken = [];

        foreach (array_slice($rows, 0, self::MAX_OPTIONS) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $labels = self::labelsFrom(is_array($row['label'] ?? null) ? $row['label'] : [], $locales);
            if ($labels === []) {
                continue;
            }
            if (($labels[$default] ?? '') === '') {
                return null;
            }

            $value = is_string($row['value'] ?? null) ? $row['value'] : '';
            if (!in_array($value, $known, true) || in_array($value, $taken, true)) {
                $base = substr(Pages::slugify($labels[$default]), 0, 36) ?: 'option';
                $value = $base;
                for ($n = 2; in_array($value, $taken, true) || in_array($value, $known, true); $n++) {
                    $value = $base . '-' . $n;
                }
            }
            $taken[] = $value;
            $options[] = ['value' => $value, 'labels' => $labels];
        }

        return $options;
    }

    /** @return list<array{value: string, labels: array<string, string>}> */
    private static function cleanOptions(mixed $stored): array
    {
        $options = [];
        foreach (is_array($stored) ? $stored : [] as $option) {
            if (is_array($option) && is_string($option['value'] ?? null) && is_array($option['labels'] ?? null)) {
                $options[] = ['value' => $option['value'], 'labels' => array_filter($option['labels'], 'is_string')];
            }
        }

        return $options;
    }

    /** @return array<int, array<string, string>> id => locale => label */
    private function labels(string $table, string $column): array
    {
        $labels = [];
        foreach ($this->db->query("SELECT {$column} AS ref, locale, label FROM {$table}")->fetchAll() as $row) {
            $labels[(int) $row['ref']][$row['locale']] = $row['label'];
        }

        return $labels;
    }

    /** @param array<string, string> $labels */
    private function replaceLabels(string $table, string $column, int $id, array $labels): void
    {
        $stmt = $this->db->prepare("DELETE FROM {$table} WHERE {$column} = :id");
        $stmt->execute(['id' => $id]);
        $insert = $this->db->prepare("INSERT INTO {$table} ({$column}, locale, label) VALUES (:id, :locale, :label)");
        foreach ($labels as $locale => $label) {
            $insert->execute(['id' => $id, 'locale' => $locale, 'label' => $label]);
        }
    }

    private function uniqueFieldKey(int $typeId, string $label): string
    {
        return $this->uniqueKey($label, 'x_fields_field', $typeId);
    }

    /**
     * Letters and digits only (no underscore: "price_min" in an address must
     * not be mistaken for a field), starting with a letter, at most 24.
     */
    private function uniqueKey(string $label, string $table, ?int $typeId): string
    {
        $base = substr((string) preg_replace('/[^a-z0-9]/', '', Pages::slugify($label)), 0, 20);
        if ($base === '' || !ctype_alpha($base[0])) {
            $base = 'f' . $base;
        }

        $sql = "SELECT COUNT(*) FROM {$table} WHERE key_name = :key" . ($typeId !== null ? ' AND type_id = :type' : '');
        $stmt = $this->db->prepare($sql);
        for ($n = 1; ; $n++) {
            $key = $n === 1 ? $base : $base . $n;
            $stmt->execute(['key' => $key] + ($typeId !== null ? ['type' => $typeId] : []));
            if ((int) $stmt->fetchColumn() === 0) {
                return $key;
            }
        }
    }
}
