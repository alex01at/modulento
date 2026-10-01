<?php

declare(strict_types=1);

namespace Modulento\Core\Catalogue;

use Modulento\Core\App;

/** Turns an offer row into what templates show, in the current language. */
final class OfferView
{
    /**
     * For lists: one card per offer.
     *
     * @return array{id: int, title: string, summary: string, path: string, price_from: ?int, currency: string, thumb: ?string, provider_name: string, provider_path: string, type: string}
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
