<?php

declare(strict_types=1);

namespace Modulento\Core\Catalogue;

use Modulento\Core\App;
use Modulento\Core\Review\Reviews;

/** Turns an offer row into what templates show, in the current language. */
final class OfferView
{
    /**
     * For lists: one card per offer.
     *
     * @param list<array{label: string, value: string}> $items the type's own teaser facts, from CardOfferType::cardItems()
     * @return array{id: int, title: string, summary: string, path: string, price_from: ?int, price_label_key: string, currency: string, thumb: ?string, provider_name: string, provider_path: string, provider_featured: bool, type: string, rating: array{count: int, average: ?float}, items: list<array{label: string, value: string}>}
     */
    public static function card(array $offer, App $app, array $items = []): array
    {
        $text = $app->offers->text($offer, $app->translator->locale()) ?? ['title' => '', 'summary' => '', 'slug' => ''];
        $image = $offer['images'][0] ?? null;

        return [
            'id' => $offer['id'],
            'title' => $text['title'],
            'summary' => $text['summary'],
            'path' => '/offers/' . $text['slug'],
            'price_from' => $offer['price_from'],
            'price_label_key' => $app->offers->type($offer['type'])->priceLabelKey(),
            'currency' => $offer['currency'],
            'thumb' => $image !== null ? OfferImages::urls($image)['thumb'] : null,
            'provider_name' => $offer['provider_name'],
            'provider_path' => '/providers/' . $offer['provider_slug'],
            'provider_featured' => $app->subscriptions->grants($offer['account_id'], 'core.provider.featured_badge'),
            'type' => $offer['type'],
            'rating' => Reviews::summary($offer),
            'items' => $items,
        ];
    }

    /**
     * @param array<int, array> $offers
     * @return array<int, array>
     */
    public static function cards(array $offers, App $app): array
    {
        // Batched per type (one call per type, not one per offer), since a
        // list page shows many cards of the same type at once.
        $offerIdsByType = [];
        foreach ($offers as $offer) {
            $offerIdsByType[$offer['type']][] = $offer['id'];
        }

        $items = [];
        foreach ($offerIdsByType as $typeId => $offerIds) {
            $type = $app->offers->type($typeId);
            if ($type instanceof CardOfferType) {
                $items += $type->cardItems($offerIds, $app->translator->locale(), $app);
            }
        }

        return array_map(fn (array $offer) => self::card($offer, $app, $items[$offer['id']] ?? []), $offers);
    }
}
