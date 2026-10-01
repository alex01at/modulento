<?php

declare(strict_types=1);

namespace Modulento\Core\Provider;

use Modulento\Core\App;
use Modulento\Core\Review\Reviews;

/** A provider as templates may show it to visitors, in the current language. */
final class ProviderView
{
    /**
     * A business is identified with its legal details; of a private
     * person only the name and the place are public.
     */
    public static function of(array $provider, App $app): array
    {
        $text = Providers::text($provider, $app->translator->locale(), $app->locales->default());
        $isBusiness = $provider['type'] === 'business';

        return [
            'name' => $provider['name'],
            'slug' => $provider['slug'],
            'path' => '/providers/' . $provider['slug'],
            'type' => $provider['type'],
            'headline' => $text['headline'] ?? '',
            'description' => $text['description'] ?? '',
            'city' => $provider['city'],
            'country' => $provider['country'],
            'rating' => Reviews::summary($provider),
            'legal' => $isBusiness ? [
                'legal_name' => $provider['legal_name'],
                'street' => $provider['street'],
                'postal_code' => $provider['postal_code'],
                'contact_email' => $provider['contact_email'],
                'phone' => $provider['phone'],
                'vat_id' => $provider['vat_id'],
                'company_register' => $provider['company_register'],
            ] : null,
        ];
    }

    /**
     * @param array<int, array> $providers
     * @return array<int, array>
     */
    public static function all(array $providers, App $app): array
    {
        return array_map(fn (array $provider) => self::of($provider, $app), $providers);
    }
}
