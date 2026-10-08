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
     * @return array{id: int, title: string, summary: string, path: string, price_from: ?int, price_label_key: string, currency: string, thumb: ?string, provider_name: string, provider_path: string, provider_featured: bool, type: string, rating: array{count: int, average: ?float}}
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
            'price_label_key' => $app->offers->type($offer['type'])->priceLabelKey(),
            'currency' => $offer['currency'],
            'thumb' => $image !== null ? OfferImages::urls($image)['thumb'] : null,
            'provider_name' => $offer['provider_name'],
            'provider_path' => '/providers/' . $offer['provider_slug'],
            'provider_featured' => $app->subscriptions->grants($offer['account_id'], 'core.provider.featured_badge'),
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
        return array_map(fn (array $offer) => self::card($offer, $app), $offers);
    }
}
