<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Amounts are integer minor units (cents) everywhere - in the database, in
 * PHP and towards payment providers. Floats only ever appear here, at the
 * moment of formatting for display.
 */
final class Money
{
    private const SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF'];

    /**
     * German convention is "1.234,50 €" (comma decimal, suffixed); English
     * is "€1,234.50" (period decimal, prefixed).
     */
    public static function format(int $minorUnits, string $currency, string $locale): string
    {
        $symbol = self::SYMBOLS[$currency] ?? $currency;
        $sign = $minorUnits < 0 ? '-' : '';
        $amount = abs($minorUnits) / 100;

        if ($locale === 'de') {
            return $sign . number_format($amount, 2, ',', '.') . ' ' . $symbol;
        }

        // "CHF 10.00" but "€10.00": a letter code is set apart, a sign is not.
        $separator = ctype_alpha($symbol) ? ' ' : '';

        return $sign . $symbol . $separator . number_format($amount, 2, '.', ',');
    }
}
