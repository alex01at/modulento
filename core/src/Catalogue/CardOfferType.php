<?php

declare(strict_types=1);

namespace Modulento\Core\Catalogue;

use Modulento\Core\App;

/**
 * An offer type that shows more on the cards of a list than the core does
 * (the city of a hotel, the date of an event).
 */
interface CardOfferType extends OfferType
{
    /** Template included inside each card of this type. It receives "type_data". */
    public function cardTemplate(): string;

    /**
     * The data for cardTemplate(), for all offers of a page at once, so a
     * list costs one query and not one per card.
     *
     * @param int[] $offerIds
     * @return array<int, array<string, mixed>> by offer id
     */
    public function cardData(array $offerIds, string $locale, App $app): array;
}
