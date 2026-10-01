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

    /**
     * Reads an amount as people type it - "49", "49.90", "49,90",
     * "1.234,50", "1,234.50" - into minor units. Null if it is not an
     * amount or has more than two decimals.
     */
    public static function parse(string $input): ?int
    {
        $input = str_replace([' ', "\u{00A0}", "'"], '', trim($input));
        if (preg_match('/^\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,2})?$|^\d+(?:[.,]\d{1,2})?$/', $input) !== 1) {
            return null;
        }

        // The last separator is the decimal one if one or two digits
        // follow it; every other separator groups thousands.
        $decimals = '';
        if (preg_match('/[.,](\d{1,2})$/', $input, $matches) === 1) {
            $decimals = $matches[1];
            $input = substr($input, 0, -strlen($matches[0]));
        }
        $whole = str_replace(['.', ','], '', $input);
        if (strlen($whole) > 9) {
            return null;
        }

        return (int) $whole * 100 + (int) str_pad($decimals, 2, '0');
    }

    /** For a form field: "49,90" in German, "49.90" otherwise - no symbol, no grouping. */
    public static function input(int $minorUnits, string $locale): string
    {
        return number_format($minorUnits / 100, 2, $locale === 'de' ? ',' : '.', '');
    }
}
