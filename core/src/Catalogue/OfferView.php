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
     * @return array{id: int, title: string, summary: string, path: string, price_from: ?int, currency: string, thumb: ?string, provider_name: string, provider_path: string, type: string, rating: array{count: int, average: ?float}}
     *         (cards() adds "type_card" for types with a CardOfferType)
     */
    public static function card(array $offer, App $app): array
    {
        $text = $app->offers->text($offer, $app->translator->locale()) ?? ['title' => '', 'summary' => '', 'slug' => ''];
        $image = $offer['images'][0] ?? null;

        return [
            'id' => $offer['id'],
            'title' => $text['title'],
            'summary' => $text['summary'],
            'path' => '/offers/' . $text['slug'],
            'price_from' => $offer['price_from'],
            'currency' => $offer['currency'],
            'thumb' => $image !== null ? OfferImages::urls($image)['thumb'] : null,
            'provider_name' => $offer['provider_name'],
            'provider_path' => '/providers/' . $offer['provider_slug'],
            'type' => $offer['type'],
            'rating' => Reviews::summary($offer),
        ];
    }

    /**
     * @param array<int, array> $offers
     * @return array<int, array>
     */
    public static function cards(array $offers, App $app): array
    {
        $cards = array_map(fn (array $offer) => self::card($offer, $app), $offers);

        // A type that shows more on its cards is asked once for all of its
        // offers on the page.
        $byType = [];
        foreach ($cards as $i => $card) {
            $byType[$card['type']][$card['id']] = $i;
        }
        foreach ($byType as $typeId => $indexes) {
            $type = $app->offers->type($typeId);
            if (!$type instanceof CardOfferType) {
                continue;
            }
            $data = $type->cardData(array_keys($indexes), $app->translator->locale(), $app);
            foreach ($indexes as $id => $i) {
                $cards[$i]['type_card'] = ['template' => $type->cardTemplate(), 'data' => $data[$id] ?? []];
            }
        }

        return $cards;
    }
}
