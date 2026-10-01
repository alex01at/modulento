<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Countries a provider can be based in, as ISO 3166-1 codes. Their names
 * are language texts ("core.country.<code>"); a code without a text shows
 * as the code. The list covers Europe and a few others and is extended by
 * adding a code here and its name to the language files.
 */
final class Countries
{
    public const CODES = [
        'AT', 'DE', 'CH', 'LI',
        'BE', 'BG', 'CY', 'CZ', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
        'IS', 'NO', 'GB',
        'AL', 'BA', 'MD', 'ME', 'MK', 'RS', 'TR', 'UA',
        'AU', 'CA', 'US',
    ];

    public static function isValid(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }
}
