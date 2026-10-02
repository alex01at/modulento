<?php

declare(strict_types=1);

namespace Modulento\Core\Payment;

/** Checks the bank details a provider types for payments by transfer. */
final class BankAccount
{
    /** Without spaces and in capitals, as it is stored. */
    public static function normalize(string $input): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', $input));
    }

    /**
     * ISO 13616: two letters, two check digits, then letters or digits, and
     * the whole number - country and check digits moved to the end, letters
     * counted as 10 to 35 - leaves the remainder 1 when divided by 97.
     */
    public static function isIban(string $iban): bool
    {
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        $remainder = 0;
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $char) {
            // Digit by digit, so the number never outgrows an integer.
            foreach (str_split(ctype_digit($char) ? $char : (string) (ord($char) - 55)) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }

    /** ISO 9362: bank (4 letters), country (2 letters), place (2), optionally branch (3). */
    public static function isBic(string $bic): bool
    {
        return preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/', $bic) === 1;
    }

    /** In groups of four, as it is printed on statements. */
    public static function formatIban(string $iban): string
    {
        return trim(chunk_split($iban, 4, ' '));
    }
}
