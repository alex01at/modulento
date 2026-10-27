<?php

declare(strict_types=1);

namespace Modulento\Core\Catalogue;

use Modulento\Core\App;

/**
 * An OfferType that also implements this adds a line of its own facts
 * ("4 Sterne", "Frühstück inklusive") to its offers' cards in the shared
 * catalogue list. Optional: a type that does not implement it simply shows
 * no extra line.
 */
interface CardOfferType
{
    /**
     * Teaser facts for a batch of offers, in one call rather than one per
     * offer, since a list page shows many cards at once.
     *
     * @param int[] $offerIds
     * @return array<int, list<array{label: string, value: string}>> offer id => its items, offers with nothing to show may be left out
     */
    public function cardItems(array $offerIds, string $locale, App $app): array;
}
