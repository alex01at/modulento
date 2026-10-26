<?php

declare(strict_types=1);

namespace Modulento\Fields;

use Modulento\Core\App;
use Modulento\Core\Catalogue\CardOfferType;
use Modulento\Core\Catalogue\FilterableOfferType;

/**
 * One kind of offer made in the administration. The fields it asks for, how
 * they are checked, stored, shown, filtered and sorted all follow from its
 * definition, so a hotel and an event are the same class with different data.
 */
final class CustomOfferType implements FilterableOfferType, CardOfferType
{
    private const SORT = '/^fld_([a-z][a-z0-9]*)_(asc|desc)$/';

    /** @param string $default the site's default language, the one labels fall back to */
    public function __construct(
        private TypeDefinition $type,
        private Values $values,
        private string $default,
    ) {
    }

    public function definition(): TypeDefinition
    {
        return $this->type;
    }

    public function id(): string
    {
        return $this->type->offerType();
    }

    public function labelKey(): string
    {
        return 'fields.type.' . $this->type->key;
    }

    public function formTemplate(): string
    {
        return '@fields/form.twig';
    }

    public function detailTemplate(): string
    {
        return '@fields/detail.twig';
    }

    public function cardTemplate(): string
    {
        return '@fields/card.twig';
    }

    public function filterTemplate(): string
    {
        return '@fields/filter.twig';
    }

    // --- The form ----------------------------------------------------------------

    public function formData(?int $offerId, ?array $typed, App $app): array
    {
        $locale = $app->translator->locale();
        $stored = $offerId !== null && $typed === null
            ? ($this->values->forOffers([$offerId], $this->fieldIds())[$offerId] ?? [])
            : [];
        $checked = $typed !== null ? $this->check(self::input($typed), $this->enabled($app)) : ['errors' => []];

        $fields = [];
        foreach ($this->type->fields as $field) {
            $fields[] = [
                'key' => $field->key,
                'kind' => $field->kind,
                'label' => $field->label($locale, $this->default),
                'required' => $field->required,
                'translatable' => $field->translatable(),
                'options' => $field->optionList($locale, $this->default),
                'value' => $typed !== null ? self::typed(self::input($typed)[$field->key] ?? null, $field) : $this->stored($field, $stored[$field->id] ?? []),
                'error' => $checked['errors'][$field->key] ?? null,
            ];
        }

        return ['fields' => $fields];
    }

    public function validate(array $input, ?int $offerId, App $app): array
    {
        $checked = $this->check(self::input($input), $this->enabled($app));

        return [
            'values' => $checked['rows'],
            'errors' => array_values(array_unique(array_values($checked['errors']))),
        ];
    }

    public function save(int $offerId, array $values, App $app): ?int
    {
        $this->values->replace($offerId, $values);

        // The price field, if the type has one, is the offer's "from" price.
        foreach ($this->type->fields as $field) {
            if (!$field->isPrice) {
                continue;
            }
            foreach ($values as $row) {
                if ($row['field'] === $field->id && $row['num'] !== null && (float) $row['num'] >= 0) {
                    return (int) round((float) $row['num'] * 100);
                }
            }
        }

        return null;
    }

    // --- Showing the offer ---------------------------------------------------------

    public function detailData(int $offerId, string $locale, App $app): array
    {
        return ['items' => $this->items([$offerId], $locale, $app, false)[$offerId] ?? []];
    }

    public function cardData(array $offerIds, string $locale, App $app): array
    {
        $items = $this->items($offerIds, $locale, $app, true);
        $cards = [];
        foreach ($offerIds as $id) {
            $cards[$id] = ['items' => $items[$id] ?? []];
        }

        return $cards;
    }

    /**
     * @param int[] $offerIds
     * @return array<int, list<array{label: string, value: string, long: bool}>>
     */
    private function items(array $offerIds, string $locale, App $app, bool $onlyCard): array
    {
        $fields = array_values(array_filter($this->type->fields, fn (Field $field) => !$onlyCard || $field->inCard));
        $values = $this->values->forOffers($offerIds, array_map(fn (Field $field) => $field->id, $fields));

        $items = [];
        foreach ($offerIds as $offerId) {
            foreach ($fields as $field) {
                $text = $this->show($field, $values[$offerId][$field->id] ?? [], $locale, $app);
                if ($text !== null) {
                    $items[$offerId][] = ['label' => $field->label($locale, $this->default), 'value' => $text, 'long' => $field->kind === 'textarea'];
                }
            }
        }

        return $items;
    }

    /** @param array<string, array{text: ?string, num: ?string}> $byLocale */
    private function show(Field $field, array $byLocale, string $locale, App $app): ?string
    {
        if ($byLocale === []) {
            return null;
        }

        switch ($field->kind) {
            case 'text':
            case 'textarea':
                $texts = array_map(fn (array $row) => (string) $row['text'], $byLocale);

                return Field::pick($texts, $locale, $this->default);
            case 'number':
                $number = $byLocale['']['num'] ?? null;

                return $number !== null ? Values::formatNumber($number, $locale) : null;
            case 'select':
                $value = (string) ($byLocale['']['text'] ?? '');
                foreach ($field->optionList($locale, $this->default) as $option) {
                    if ($option['value'] === $value) {
                        return $option['label'];
                    }
                }

                return null;
            case 'checkbox':
                return ($byLocale['']['num'] ?? '0') === '1' ? $app->translator->trans('fields.value.yes', [], $locale) : null;
            case 'date':
                $date = $byLocale['']['text'] ?? null;

                return $date !== null ? Values::formatDate($date, $locale) : null;
        }

        return null;
    }

    // --- Filter and sort ---------------------------------------------------------------

    public function filterData(array $input, string $locale, App $app): array
    {
        $fields = [];
        foreach ($this->type->fields as $field) {
            if ($field->filterable) {
                $fields[] = [
                    'key' => $field->key,
                    'kind' => $field->kind,
                    'label' => $field->label($locale, $this->default),
                    'options' => $field->optionList($locale, $this->default),
                ];
            }
        }

        return ['fields' => $fields, 'values' => $input];
    }

    public function applyFilter(array $input, string $locale): ?array
    {
        $where = [];
        $params = [];

        foreach ($this->type->fields as $field) {
            if (!$field->filterable) {
                continue;
            }
            $id = $field->id;
            $conditions = [];
            $text = fn (string $name): ?string => is_string($input[$name] ?? null) && trim($input[$name]) !== '' ? trim($input[$name]) : null;

            switch ($field->kind) {
                case 'select':
                    $value = $text($field->key);
                    if ($value !== null && $field->hasOption($value)) {
                        $conditions[] = "v.value_text = :f_{$id}_eq";
                        $params["f_{$id}_eq"] = $value;
                    }
                    break;
                case 'checkbox':
                    if (($input[$field->key] ?? null) === '1') {
                        $conditions[] = 'v.value_num = 1';
                    }
                    break;
                case 'text':
                    $value = $text($field->key);
                    if ($value !== null) {
                        $conditions[] = "v.value_text LIKE :f_{$id}_like ESCAPE '!'";
                        $params["f_{$id}_like"] = '%' . strtr(mb_substr($value, 0, 100), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                    }
                    break;
                case 'number':
                    foreach (['min' => '>=', 'max' => '<='] as $bound => $operator) {
                        $number = ($raw = $text($field->key . '_' . $bound)) !== null ? Values::parseNumber($raw) : null;
                        if ($number !== null) {
                            $conditions[] = "v.value_num {$operator} :f_{$id}_{$bound}";
                            $params["f_{$id}_{$bound}"] = $number;
                        }
                    }
                    break;
                case 'date':
                    foreach (['from' => '>=', 'to' => '<='] as $bound => $operator) {
                        $date = ($raw = $text($field->key . '_' . $bound)) !== null ? Values::parseDate($raw) : null;
                        if ($date !== null) {
                            $conditions[] = "v.value_text {$operator} :f_{$id}_{$bound}";
                            $params["f_{$id}_{$bound}"] = $date;
                        }
                    }
                    break;
            }

            if ($conditions !== []) {
                $where[] = "EXISTS (SELECT 1 FROM x_fields_value v WHERE v.offer_id = o.id AND v.field_id = {$id} AND " . implode(' AND ', $conditions) . ')';
            }
        }

        return $where === [] ? null : ['where' => implode(' AND ', $where), 'params' => $params];
    }

    public function sorts(): array
    {
        $sorts = [];
        foreach ($this->type->fields as $field) {
            if ($field->sortable) {
                foreach (['asc', 'desc'] as $direction) {
                    $sorts['fld_' . $field->key . '_' . $direction] = 'fields.sort.' . $this->type->key . '.' . $field->key . '.' . $direction;
                }
            }
        }

        return $sorts;
    }

    public function orderBy(string $sort): ?string
    {
        if (preg_match(self::SORT, $sort, $match) !== 1) {
            return null;
        }
        foreach ($this->type->fields as $field) {
            if ($field->sortable && $field->key === $match[1]) {
                $column = $field->kind === 'number' ? 'value_num' : 'value_text';
                $value = "(SELECT v.{$column} FROM x_fields_value v WHERE v.offer_id = o.id AND v.field_id = {$field->id} AND v.locale = '')";

                // Offers without a value come last, whichever way it goes.
                return "({$value} IS NULL), {$value} " . strtoupper($match[2]);
            }
        }

        return null;
    }

    // --- Checking what was sent ----------------------------------------------------------

    /**
     * @param array<string, mixed> $input the "cf" part of the form
     * @param string[] $locales
     * @return array{rows: list<array{field: int, locale: string, text: ?string, num: ?string}>, errors: array<string, string>} errors by field key
     */
    private function check(array $input, array $locales): array
    {
        $rows = [];
        $errors = [];

        foreach ($this->type->fields as $field) {
            $raw = $input[$field->key] ?? null;
            $before = count($rows);
            $invalid = false;

            switch ($field->kind) {
                case 'text':
                case 'textarea':
                    $max = $field->kind === 'text' ? 255 : 5000;
                    foreach ($locales as $code) {
                        $text = is_array($raw) && is_string($raw[$code] ?? null) ? trim(str_replace("\r\n", "\n", $raw[$code])) : '';
                        if ($text === '') {
                            continue;
                        }
                        if (mb_strlen($text) > $max || ($field->kind === 'text' && str_contains($text, "\n"))) {
                            $invalid = true;
                            continue;
                        }
                        $rows[] = ['field' => $field->id, 'locale' => $code, 'text' => $text, 'num' => null];
                    }
                    break;
                case 'number':
                    $text = is_string($raw) ? trim($raw) : '';
                    if ($text !== '') {
                        $number = Values::parseNumber($text);
                        if ($number === null) {
                            $invalid = true;
                        } else {
                            $rows[] = ['field' => $field->id, 'locale' => '', 'text' => null, 'num' => $number];
                        }
                    }
                    break;
                case 'select':
                    $text = is_string($raw) ? $raw : '';
                    if ($text !== '') {
                        if ($field->hasOption($text)) {
                            $rows[] = ['field' => $field->id, 'locale' => '', 'text' => $text, 'num' => null];
                        } else {
                            $invalid = true;
                        }
                    }
                    break;
                case 'checkbox':
                    if ($raw === '1') {
                        $rows[] = ['field' => $field->id, 'locale' => '', 'text' => null, 'num' => '1'];
                    }
                    break;
                case 'date':
                    $text = is_string($raw) ? trim($raw) : '';
                    if ($text !== '') {
                        $date = Values::parseDate($text);
                        if ($date === null) {
                            $invalid = true;
                        } else {
                            $rows[] = ['field' => $field->id, 'locale' => '', 'text' => $date, 'num' => null];
                        }
                    }
                    break;
            }

            if ($invalid) {
                $errors[$field->key] = 'fields.error.invalid';
            } elseif ($field->required && count($rows) === $before) {
                $errors[$field->key] = 'fields.error.required';
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /** @return array<string, mixed> */
    private static function input(array $post): array
    {
        return is_array($post['cf'] ?? null) ? $post['cf'] : [];
    }

    /** What the form shows again after a failed check: the text as it was typed. */
    private static function typed(mixed $raw, Field $field): string|array
    {
        if ($field->translatable()) {
            $texts = [];
            foreach (is_array($raw) ? $raw : [] as $code => $text) {
                if (is_string($code) && is_string($text)) {
                    $texts[$code] = $text;
                }
            }

            return $texts;
        }

        return is_string($raw) ? $raw : '';
    }

    /** @param array<string, array{text: ?string, num: ?string}> $byLocale */
    private function stored(Field $field, array $byLocale): string|array
    {
        if ($field->translatable()) {
            return array_map(fn (array $row) => (string) $row['text'], $byLocale);
        }

        $row = $byLocale[''] ?? null;

        return match ($field->kind) {
            'number', 'checkbox' => (string) ($row['num'] ?? ''),
            default => (string) ($row['text'] ?? ''),
        };
    }

    /** @return int[] */
    private function fieldIds(): array
    {
        return array_map(fn (Field $field) => $field->id, $this->type->fields);
    }

    /** @return string[] */
    private function enabled(App $app): array
    {
        return $app->locales->enabled();
    }
}
