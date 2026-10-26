<?php

declare(strict_types=1);

namespace Modulento\Fields;

use DateTimeImmutable;
use PDO;

/** What offers have in their fields: reading, writing, and turning it into text. */
final class Values
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @param int[] $offerIds
     * @param int[] $fieldIds
     * @return array<int, array<int, array<string, array{text: ?string, num: ?string}>>> offer id => field id => locale => value
     */
    public function forOffers(array $offerIds, array $fieldIds): array
    {
        if ($offerIds === [] || $fieldIds === []) {
            return [];
        }

        $params = [];
        $offers = [];
        foreach (array_values($offerIds) as $i => $id) {
            $offers[] = ':o' . $i;
            $params['o' . $i] = $id;
        }
        $fields = [];
        foreach (array_values($fieldIds) as $i => $id) {
            $fields[] = ':f' . $i;
            $params['f' . $i] = $id;
        }

        $stmt = $this->db->prepare(
            'SELECT offer_id, field_id, locale, value_text, value_num FROM x_fields_value
             WHERE offer_id IN (' . implode(', ', $offers) . ') AND field_id IN (' . implode(', ', $fields) . ')'
        );
        $stmt->execute($params);

        $values = [];
        foreach ($stmt->fetchAll() as $row) {
            $values[(int) $row['offer_id']][(int) $row['field_id']][$row['locale']] = [
                'text' => $row['value_text'],
                'num' => $row['value_num'] !== null ? self::trimNumber((string) $row['value_num']) : null,
            ];
        }

        return $values;
    }

    /**
     * Replaces everything the offer has.
     *
     * @param list<array{field: int, locale: string, text: ?string, num: ?string}> $rows
     */
    public function replace(int $offerId, array $rows): void
    {
        $this->db->beginTransaction();
        $stmt = $this->db->prepare('DELETE FROM x_fields_value WHERE offer_id = :id');
        $stmt->execute(['id' => $offerId]);

        $insert = $this->db->prepare(
            'INSERT INTO x_fields_value (offer_id, field_id, locale, value_text, value_num) VALUES (:offer, :field, :locale, :text, :num)'
        );
        foreach ($rows as $row) {
            $insert->execute(['offer' => $offerId, 'field' => $row['field'], 'locale' => $row['locale'], 'text' => $row['text'], 'num' => $row['num']]);
        }
        $this->db->commit();
    }

    // --- Reading what was typed ------------------------------------------------

    /** "12,5" and "12.5" are the same; null if it is not a number this field can hold. */
    public static function parseNumber(string $text): ?string
    {
        $text = str_replace([' ', "\u{00A0}"], '', trim($text));
        $text = str_replace(',', '.', $text);

        return preg_match('/^-?\d{1,10}(\.\d{1,4})?$/', $text) === 1 ? self::trimNumber($text) : null;
    }

    /** "2026-03-09" if it is a real date. */
    public static function parseDate(string $text): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($text));
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === trim($text) ? $date->format('Y-m-d') : null;
    }

    // --- Showing it -------------------------------------------------------------

    /** "12.5000" -> "12.5", "3.0000" -> "3". */
    public static function trimNumber(string $number): string
    {
        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.');
        }

        return $number === '' || $number === '-0' ? '0' : $number;
    }

    public static function formatNumber(string $number, string $locale): string
    {
        $number = self::trimNumber($number);
        $negative = str_starts_with($number, '-');
        [$whole, $decimals] = array_pad(explode('.', ltrim($number, '-'), 2), 2, '');
        [$thousands, $point] = $locale === 'de' ? ['.', ','] : [',', '.'];

        $whole = strrev(implode($thousands, str_split(strrev($whole), 3)));

        return ($negative ? '-' : '') . $whole . ($decimals !== '' ? $point . $decimals : '');
    }

    public static function formatDate(string $iso, string $locale): string
    {
        return $locale === 'de' ? implode('.', array_reverse(explode('-', $iso))) : $iso;
    }
}
