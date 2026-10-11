<?php

declare(strict_types=1);

namespace Modulento\Core\CustomField;

use Closure;
use DateTimeImmutable;
use Modulento\Core\Content\Pages;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Locales;
use PDO;

/**
 * Fields an administrator defines without an extension, shown on an offer's
 * form (optionally restricted to some offer types) or on the provider
 * profile form. Text and textarea values are per language, like a title;
 * every other type has a single, language-independent value, with a
 * select's own option labels translated instead.
 */
final class CustomFields
{
    public const TYPES = ['text', 'textarea', 'number', 'select', 'checkbox', 'date'];
    public const TRANSLATABLE_TYPES = ['text', 'textarea'];
    private const DATE_FORMATS = ['de' => 'd.m.Y'];

    public function __construct(private PDO $db, private Locales $locales)
    {
    }

    /** @return array<int, array<string, mixed>> every definition of an entity, with its translations, by position */
    public function definitions(string $entity): array
    {
        $stmt = $this->db->prepare('SELECT * FROM custom_field WHERE entity = :entity ORDER BY position, id');
        $stmt->execute(['entity' => $entity]);

        $fields = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            $fields[$id] = [
                'id' => $id,
                'entity' => $row['entity'],
                'field_key' => $row['field_key'],
                'type' => $row['type'],
                'required' => (bool) $row['required'],
                'position' => (int) $row['position'],
                'type_ids' => is_string($row['type_ids']) ? (json_decode($row['type_ids'], true) ?: []) : [],
                'translations' => [],
            ];
        }
        if ($fields === []) {
            return [];
        }

        foreach ($this->db->query('SELECT * FROM custom_field_translation')->fetchAll() as $row) {
            $id = (int) $row['field_id'];
            if (isset($fields[$id])) {
                $fields[$id]['translations'][$row['locale']] = [
                    'label' => $row['label'],
                    'option_labels' => is_string($row['option_labels']) ? (json_decode($row['option_labels'], true) ?: []) : [],
                ];
            }
        }

        return array_values($fields);
    }

    public function find(string $entity, int $id): ?array
    {
        foreach ($this->definitions($entity) as $field) {
            if ($field['id'] === $id) {
                return $field;
            }
        }

        return null;
    }

    /** The definitions that apply to an offer type; a provider-profile definition or one with no type_ids always applies. */
    public function applicable(array $definitions, ?string $typeId): array
    {
        return array_values(array_filter(
            $definitions,
            fn (array $field) => $field['entity'] !== 'offer' || $field['type_ids'] === [] || ($typeId !== null && in_array($typeId, $field['type_ids'], true))
        ));
    }

    /**
     * @param array{type: string, required: bool, position: int, type_ids?: string[]} $values
     * @param array<string, array{label: string, options?: array<int, string>}> $translations by locale
     * @return array{id: ?int, errors: array<int, array{key: string, params: array}>}
     */
    public function save(string $entity, ?int $id, array $values, array $translations): array
    {
        $errors = [];
        $type = in_array($values['type'] ?? '', self::TYPES, true) ? $values['type'] : null;
        if ($id === null && $type === null) {
            $errors[] = ['key' => 'core.admin.custom_fields.error.type', 'params' => []];
        }

        $default = $this->locales->default();
        $defaultLabel = trim((string) ($translations[$default]['label'] ?? ''));
        if ($defaultLabel === '') {
            $errors[] = ['key' => 'core.admin.custom_fields.error.label', 'params' => []];
        }

        // Option keys come only from the default language's rows; the other
        // languages fill in a label for the same keys, by position.
        $optionKeys = [];
        $optionLabelsByLocale = [];
        if ($type === 'select') {
            foreach ((is_array($translations[$default]['options'] ?? null) ? $translations[$default]['options'] : []) as $i => $label) {
                $label = trim((string) $label);
                if ($label === '') {
                    continue;
                }
                $key = Pages::slugify($label);
                if ($key === '') {
                    continue;
                }
                $base = $key;
                $n = 2;
                while (in_array($key, $optionKeys, true)) {
                    $key = $base . '-' . $n++;
                }
                $optionKeys[$i] = $key;
            }
            if ($optionKeys === []) {
                $errors[] = ['key' => 'core.admin.custom_fields.error.options', 'params' => []];
            }
            foreach ($this->locales->enabled() as $locale) {
                $rows = is_array($translations[$locale]['options'] ?? null) ? $translations[$locale]['options'] : [];
                $map = [];
                foreach ($optionKeys as $i => $key) {
                    $label = trim((string) ($rows[$i] ?? ''));
                    if ($label !== '') {
                        $map[$key] = $label;
                    }
                }
                if ($map !== []) {
                    $optionLabelsByLocale[$locale] = $map;
                }
            }
        }

        if ($errors !== []) {
            return ['id' => $id, 'errors' => $errors];
        }

        $required = !empty($values['required']);
        $position = (int) ($values['position'] ?? 0);
        $typeIds = $entity === 'offer' ? array_values(array_unique(array_filter((array) ($values['type_ids'] ?? []), 'is_string'))) : [];

        $this->db->beginTransaction();

        if ($id === null) {
            $fieldKey = $this->uniqueKey($entity, Pages::slugify($defaultLabel));
            $stmt = $this->db->prepare('INSERT INTO custom_field (entity, field_key, type, required, position, type_ids, created_at) VALUES (:entity, :key, :type, :required, :position, :type_ids, :now)');
            $stmt->execute([
                'entity' => $entity,
                'key' => $fieldKey,
                'type' => $type,
                'required' => $required ? 1 : 0,
                'position' => $position,
                'type_ids' => json_encode($typeIds, JSON_THROW_ON_ERROR),
                'now' => Clock::now(),
            ]);
            $id = (int) $this->db->lastInsertId();
        } else {
            // The type and the key stay fixed once created: changing either
            // would orphan stored values or break form field names.
            $stmt = $this->db->prepare('UPDATE custom_field SET required = :required, position = :position, type_ids = :type_ids WHERE id = :id AND entity = :entity');
            $stmt->execute(['required' => $required ? 1 : 0, 'position' => $position, 'type_ids' => json_encode($typeIds, JSON_THROW_ON_ERROR), 'id' => $id, 'entity' => $entity]);
        }

        $delete = $this->db->prepare('DELETE FROM custom_field_translation WHERE field_id = :id');
        $delete->execute(['id' => $id]);
        $insert = $this->db->prepare('INSERT INTO custom_field_translation (field_id, locale, label, option_labels) VALUES (:id, :locale, :label, :options)');
        foreach ($this->locales->enabled() as $locale) {
            $label = trim((string) ($translations[$locale]['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $insert->execute([
                'id' => $id,
                'locale' => $locale,
                'label' => mb_substr($label, 0, 150),
                'options' => isset($optionLabelsByLocale[$locale]) ? json_encode($optionLabelsByLocale[$locale], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
            ]);
        }

        $this->db->commit();

        return ['id' => $id, 'errors' => []];
    }

    public function delete(string $entity, int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM custom_field WHERE id = :id AND entity = :entity');
        $stmt->execute(['id' => $id, 'entity' => $entity]);
    }

    /**
     * The definitions applicable to this entity/type, with the value to show in the
     * form: the stored one, or - after a failed validation - what was typed.
     *
     * @param array<string, mixed>|null $typed $_POST['custom_fields'] as sent, or null to show the stored values
     * @param string[] $enabledLocales
     */
    public function formData(string $entity, ?int $entityId, ?array $typed, ?string $typeId, string $uiLocale, array $enabledLocales): array
    {
        $fields = $this->applicable($this->definitions($entity), $typeId);
        $stored = $entityId !== null ? $this->storedValues($entity, $entityId) : [];

        return array_map(function (array $field) use ($typed, $stored, $enabledLocales, $uiLocale) {
            $translatable = in_array($field['type'], self::TRANSLATABLE_TYPES, true);
            if ($translatable) {
                $value = [];
                foreach ($enabledLocales as $code) {
                    $value[$code] = $typed !== null
                        ? (string) ($typed[$field['field_key']][$code] ?? '')
                        : (string) ($stored[$field['id']][$code] ?? '');
                }
            } elseif ($field['type'] === 'checkbox') {
                $value = $typed !== null
                    ? isset($typed[$field['field_key']])
                    : (($stored[$field['id']][''] ?? '0') === '1');
            } else {
                $value = $typed !== null
                    ? (string) ($typed[$field['field_key']] ?? '')
                    : (string) ($stored[$field['id']][''] ?? '');
            }

            return [
                'id' => $field['id'],
                'key' => $field['field_key'],
                'type' => $field['type'],
                'required' => $field['required'],
                'label' => $this->label($field, $uiLocale),
                'options' => $field['type'] === 'select' ? $this->optionLabels($field, $uiLocale) : [],
                'translatable' => $translatable,
                'value' => $value,
            ];
        }, $fields);
    }

    /**
     * Checks the submitted custom fields of an offer or a provider profile.
     *
     * @param array<string, mixed> $input $_POST['custom_fields']
     * @param string[] $enabledLocales
     * @param Closure(string, array): string $trans the error, already worded - a custom
     *        field's label is itself an operator-entered text, not a language key
     * @return array{values: array<int, array<string, string>>, errors: string[]} values by field id, then by
     *         locale ('' for a language-independent type), ready for saveValues()
     */
    public function validate(string $entity, array $input, ?string $typeId, array $enabledLocales, Closure $trans): array
    {
        $fields = $this->applicable($this->definitions($entity), $typeId);
        $default = $this->locales->default();
        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            $label = $this->label($field, $default);

            if ($field['type'] === 'checkbox') {
                $checked = isset($input[$field['field_key']]);
                if ($field['required'] && !$checked) {
                    $errors[] = $trans('core.custom_field.error.required', ['label' => $label]);
                }
                $values[$field['id']] = ['' => $checked ? '1' : '0'];
                continue;
            }

            if (in_array($field['type'], self::TRANSLATABLE_TYPES, true)) {
                $raw = is_array($input[$field['field_key']] ?? null) ? $input[$field['field_key']] : [];
                $stored = [];
                foreach ($enabledLocales as $code) {
                    $text = trim((string) ($raw[$code] ?? ''));
                    if ($field['type'] === 'textarea') {
                        $text = str_replace("\r\n", "\n", $text);
                    }
                    if ($text !== '') {
                        $stored[$code] = mb_substr($text, 0, $field['type'] === 'textarea' ? 5000 : 500);
                    }
                }
                if ($field['required'] && ($stored[$default] ?? '') === '') {
                    $errors[] = $trans('core.custom_field.error.required', ['label' => $label]);
                }
                $values[$field['id']] = $stored;
                continue;
            }

            $raw = trim((string) ($input[$field['field_key']] ?? ''));
            if ($raw === '') {
                if ($field['required']) {
                    $errors[] = $trans('core.custom_field.error.required', ['label' => $label]);
                }
                $values[$field['id']] = [];
                continue;
            }

            $valid = match ($field['type']) {
                'number' => is_numeric($raw),
                'date' => self::isValidDate($raw),
                'select' => in_array($raw, $this->optionKeys($field), true),
                default => true,
            };
            if (!$valid) {
                $errors[] = $trans('core.custom_field.error.invalid', ['label' => $label]);
                $values[$field['id']] = [];
                continue;
            }
            $values[$field['id']] = ['' => $raw];
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** @param array<int, array<string, string>> $values from validate(), by field id */
    public function saveValues(string $entity, int $entityId, array $values): void
    {
        $table = $entity === 'offer' ? 'custom_field_offer_value' : 'custom_field_provider_value';
        $column = $entity === 'offer' ? 'offer_id' : 'provider_id';

        $this->db->beginTransaction();

        $delete = $this->db->prepare("DELETE FROM {$table} WHERE {$column} = :id");
        $delete->execute(['id' => $entityId]);

        $insert = $this->db->prepare("INSERT INTO {$table} (field_id, {$column}, locale, value) VALUES (:field, :entity, :locale, :value)");
        foreach ($values as $fieldId => $byLocale) {
            foreach ($byLocale as $locale => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $insert->execute(['field' => $fieldId, 'entity' => $entityId, 'locale' => $locale, 'value' => $value]);
            }
        }

        $this->db->commit();
    }

    /** @return array<int, array{label: string, value: string|bool}> filled-in fields only, for a public page */
    public function display(string $entity, int $entityId, ?string $typeId, string $locale): array
    {
        $fields = $this->applicable($this->definitions($entity), $typeId);
        $stored = $this->storedValues($entity, $entityId);
        $result = [];

        foreach ($fields as $field) {
            $translatable = in_array($field['type'], self::TRANSLATABLE_TYPES, true);
            $raw = $translatable
                ? ($stored[$field['id']][$locale] ?? $stored[$field['id']][$this->locales->default()] ?? '')
                : ($stored[$field['id']][''] ?? '');

            if ($field['type'] === 'checkbox') {
                if ($raw === '1') {
                    $result[] = ['label' => $this->label($field, $locale), 'value' => true];
                }
                continue;
            }
            if ($raw === '') {
                continue;
            }

            $value = match ($field['type']) {
                'select' => $this->optionLabels($field, $locale)[$raw] ?? $raw,
                'date' => self::isValidDate($raw) ? (new DateTimeImmutable($raw))->format(self::DATE_FORMATS[$locale] ?? 'Y-m-d') : $raw,
                default => $raw,
            };
            $result[] = ['label' => $this->label($field, $locale), 'value' => $value];
        }

        return $result;
    }

    /** @return array<int, array<string, string>> by field id, then locale ('' for a language-independent type) */
    private function storedValues(string $entity, int $entityId): array
    {
        $table = $entity === 'offer' ? 'custom_field_offer_value' : 'custom_field_provider_value';
        $column = $entity === 'offer' ? 'offer_id' : 'provider_id';

        $stmt = $this->db->prepare("SELECT field_id, locale, value FROM {$table} WHERE {$column} = :id");
        $stmt->execute(['id' => $entityId]);

        $values = [];
        foreach ($stmt->fetchAll() as $row) {
            $values[(int) $row['field_id']][$row['locale']] = $row['value'];
        }

        return $values;
    }

    private function label(array $field, string $locale): string
    {
        return $field['translations'][$locale]['label']
            ?? $field['translations'][$this->locales->default()]['label']
            ?? $field['field_key'];
    }

    /** @return array<string, string> option key => label, in a language */
    private function optionLabels(array $field, string $locale): array
    {
        return $field['translations'][$locale]['option_labels']
            ?? $field['translations'][$this->locales->default()]['option_labels']
            ?? [];
    }

    /** @return string[] a select's option keys - the same set in every language, read from whichever has them */
    private function optionKeys(array $field): array
    {
        foreach ($field['translations'] as $translation) {
            if ($translation['option_labels'] !== []) {
                return array_keys($translation['option_labels']);
            }
        }

        return [];
    }

    private function uniqueKey(string $entity, string $base): string
    {
        $base = $base !== '' ? substr($base, 0, 55) : 'field';
        $existing = array_column($this->definitions($entity), 'field_key');
        $key = $base;
        $n = 2;
        while (in_array($key, $existing, true)) {
            $key = $base . '-' . $n++;
        }

        return $key;
    }

    private static function isValidDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
