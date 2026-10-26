<?php

declare(strict_types=1);

namespace Modulento\Fields;

/** One field of a type, as the operator defined it. */
final class Field
{
    public const KINDS = ['text', 'textarea', 'number', 'select', 'checkbox', 'date'];
    /** Kinds whose value is a text per language. */
    public const TRANSLATABLE = ['text', 'textarea'];
    public const FILTERABLE = ['text', 'number', 'select', 'checkbox', 'date'];
    public const SORTABLE = ['number', 'date'];

    /**
     * @param array<string, string> $labels by locale
     * @param list<array{value: string, labels: array<string, string>}> $options
     */
    public function __construct(
        public readonly int $id,
        public readonly int $typeId,
        public readonly string $key,
        public readonly string $kind,
        public readonly bool $required,
        public readonly bool $filterable,
        public readonly bool $sortable,
        public readonly bool $inCard,
        public readonly bool $isPrice,
        public readonly int $position,
        public readonly array $labels,
        public readonly array $options,
    ) {
    }

    public function translatable(): bool
    {
        return in_array($this->kind, self::TRANSLATABLE, true);
    }

    /** The label in a language, else in the fallback language, else any, else the key. */
    public function label(string $locale, string $fallback): string
    {
        return self::pick($this->labels, $locale, $fallback) ?? $this->key;
    }

    /** @return list<array{value: string, label: string}> */
    public function optionList(string $locale, string $fallback): array
    {
        return array_map(
            fn (array $option) => ['value' => $option['value'], 'label' => self::pick($option['labels'], $locale, $fallback) ?? $option['value']],
            $this->options
        );
    }

    public function hasOption(string $value): bool
    {
        foreach ($this->options as $option) {
            if ($option['value'] === $value) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $texts */
    public static function pick(array $texts, string $locale, string $fallback): ?string
    {
        foreach ([$locale, $fallback] as $code) {
            if (($texts[$code] ?? '') !== '') {
                return $texts[$code];
            }
        }
        foreach ($texts as $text) {
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }
}
