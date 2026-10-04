<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Refuses the most common swear words and insults in text that visitors
 * write for others: messages, questions and reviews. The lists live in
 * core/data/badwords, one file per language, and every list is checked
 * whatever the language of the text.
 *
 * Spelling tricks are undone first: capitals, accents, "f*ck", "sh!t", and
 * letters repeated to stretch a word ("fuuuck"). This is a list, not a
 * judgement - it catches the usual words, not every insult.
 */
final class BadWords
{
    private const DIRECTORY = __DIR__ . '/../../data/badwords';
    /** Shorter words only match as a whole, so they are not found inside other words. */
    private const SUBSTRING_MIN_LENGTH = 5;

    private const ACCENTS = [
        'ß' => 'ss', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ý' => 'y',
    ];

    /** @var array<string, string>|null folded word => listed word */
    private static ?array $words = null;

    /** The first listed word the text contains, as listed, or null. */
    public static function find(string $text): ?string
    {
        $words = self::words();
        foreach (self::tokens($text) as $token) {
            if (isset($words[$token])) {
                return $words[$token];
            }
            foreach ($words as $folded => $listed) {
                if (strlen($folded) >= self::SUBSTRING_MIN_LENGTH && str_contains($token, $folded)) {
                    return $listed;
                }
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private static function words(): array
    {
        if (self::$words !== null) {
            return self::$words;
        }

        self::$words = [];
        foreach (glob(self::DIRECTORY . '/*.txt') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $word = trim($line);
                if ($word === '' || str_starts_with($word, '#')) {
                    continue;
                }
                self::$words[self::collapse(self::fold($word))] = $word;
            }
        }

        return self::$words;
    }

    /** @return list<string> the words of the text, folded as the lists are */
    private static function tokens(string $text): array
    {
        $folded = self::fold($text);
        $tokens = preg_split('/[^a-z]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map([self::class, 'collapse'], $tokens);
    }

    /** Lower case, accents removed, the usual letter substitutes read as letters. */
    private static function fold(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // Without the intl extension, the accents that occur in the languages
        // of the site are listed one by one.
        $text = strtr($text, self::ACCENTS);
        // "f*ck": the star stands for the letter that is usually hidden (u).
        $text = str_replace('*', 'u', $text);

        return strtr($text, ['0' => 'o', '1' => 'i', '!' => 'i', '3' => 'e', '4' => 'a', '@' => 'a', '5' => 's', '$' => 's', '7' => 't']);
    }

    /** One letter for every run of repeated letters: "fuuuck" and "fuck" compare equal. */
    private static function collapse(string $word): string
    {
        return (string) preg_replace('/(.)\1+/', '$1', $word);
    }
}
