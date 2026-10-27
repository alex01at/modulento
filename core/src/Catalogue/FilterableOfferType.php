<?php

declare(strict_types=1);

namespace Modulento\Core\Catalogue;

use Modulento\Core\App;

/**
 * An OfferType that also implements this can be narrowed by its own fields
 * when the visitor has chosen exactly this type on the offer list
 * (?type=<id>): a star rating, a price range, an amenity. Optional.
 */
interface FilterableOfferType
{
    /**
     * The fields of the filter form and, once submitted, what was chosen.
     *
     * @param array<string, mixed> $input the "f" part of the list's query string
     * @return array<string, mixed>
     */
    public function filterData(array $input, string $locale, App $app): array;

    /** Template for the fields of filterData(), included inside the list's search form. It receives "filter". */
    public function filterTemplate(): string;

    /**
     * The condition for $input, if it selects anything.
     *
     * @param array<string, mixed> $input the "f" part of the list's query string
     * @return array{where: string, params: array<string, mixed>}|null a SQL
     *         condition referring to the offer as "o" (typically an EXISTS
     *         subquery against the type's own tables) and its parameters,
     *         or null when $input selects nothing
     */
    public function applyFilter(array $input, string $locale): ?array;
}
