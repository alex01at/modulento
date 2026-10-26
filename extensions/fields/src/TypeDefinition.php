<?php

declare(strict_types=1);

namespace Modulento\Fields;

/** A kind of offer the operator made, with its fields in order. */
final class TypeDefinition
{
    /**
     * @param array<string, string> $labels by locale
     * @param list<Field> $fields
     */
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly int $position,
        public readonly array $labels,
        public readonly array $fields,
    ) {
    }

    /** The id the core knows the type by. */
    public function offerType(): string
    {
        return 'fields.' . $this->key;
    }

    public function label(string $locale, string $fallback): string
    {
        return Field::pick($this->labels, $locale, $fallback) ?? $this->key;
    }

    public function field(int $id): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->id === $id) {
                return $field;
            }
        }

        return null;
    }
}
