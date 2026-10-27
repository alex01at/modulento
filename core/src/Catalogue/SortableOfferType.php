<?php

declare(strict_types=1);

namespace Modulento\Core\Catalogue;

/**
 * An OfferType that also implements this adds its own sort options to the
 * offer list when the visitor has chosen exactly this type (?type=<id>):
 * sort by price, by date. Optional.
 */
interface SortableOfferType
{
    /**
     * @return array<string, string> sort id (must start with a prefix
     *         unique to this type, so ids of different types never
     *         collide - "fld_" for a field of the "fields" extension) =>
     *         its label, already in the visitor's language
     */
    public function sorts(): array;

    /** The ORDER BY expression for one of sorts()'s ids, referring to the offer as "o", or null if $sort is not one of them. */
    public function orderBy(string $sort): ?string;
}
