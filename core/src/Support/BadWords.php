<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Refuses the most common swear words and insults in text that visitors
 * write for others: messages, questions and reviews.
 *
 * The list is kept in the settings, one word per line, once an administrator
 * has saved it under Administration → Word filter. Until then the lists in
 * core/data/badwords (one file per language) apply. Every word is checked
 * whatever the language of the text.
 *
 * Spelling tricks are undone first: capitals, accents, "f*ck", "sh!t", and
 * letters repeated to stretch a word ("fuuuck"). This is a list, not a
 * judgement - it catches the usual words, not every insult.
 */
final class BadWords
{
    public const DEFAULT_DIRECTORY = __DIR__ . '/../../data/badwords';
    public const SETTING = 'core.badwords';
    /** Shorter words only match as a whole, so they are not found inside other words. */
    private const SUBSTRING_MIN_LENGTH = 5;
    private const MAX_WORDS = 1000;
    private const MAX_WORD_LENGTH = 40;
    private const ACCENTS = [
        'ß' => 'ss', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ý' => 'y',
    ];

    /** @var array<string, string>|null folded word => word as listed */
    private ?array $folded = null;

    public function __construct(private Settings $settings, private string $directory = self::DEFAULT_DIRECTORY)
    {
    }

    /** The first listed word the text contains, as listed, or null. */
    public function find(string $text): ?string
    {
        $words = $this->foldedWords();
        foreach ($this->tokens($text) as $token) {
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

    /** The list as it applies, one word per entry. @return list<string> */
    public function words(): array
    {
        return array_values(array_unique(array_values($this->foldedWords())));
    }

    /** Whether an administrator has saved a list of their own. */
    public function isCustomized(): bool
    {
        return $this->settings->has(self::SETTING);
    }

    /**
     * Saves a list typed by an administrator: words separated by line breaks,
     * commas or spaces. Duplicates, empty entries and overlong words are
     * dropped. @return int the number of words saved
     */
    public function save(string $typed): int
    {
        $words = [];
        foreach (preg_split('/[\s,;]+/u', mb_strtolower($typed, 'UTF-8')) ?: [] as $word) {
            $word = trim($word);
            if ($word !== '' && mb_strlen($word) <= self::MAX_WORD_LENGTH) {
                $words[$word] = true;
            }
        }
        $words = array_slice(array_keys($words), 0, self::MAX_WORDS);
        // An empty list would switch the filter off; the saved one stays.
        if ($words === []) {
            return 0;
        }

        $this->settings->set(self::SETTING, implode("\n", $words));
        $this->folded = null;

        return count($words);
    }

    /** Back to the lists that ship with the core. */
    public function reset(): void
    {
        $this->settings->forget(self::SETTING);
        $this->folded = null;
    }

    /** @return array<string, string> */
    private function foldedWords(): array
    {
        if ($this->folded !== null) {
            return $this->folded;
        }

        $words = $this->isCustomized()
            ? preg_split('/\R/', $this->settings->get(self::SETTING)) ?: []
            : $this->shippedWords();

        $this->folded = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word !== '' && !str_starts_with($word, '#')) {
                $this->folded[self::collapse(self::fold($word))] = $word;
            }
        }

        return $this->folded;
    }

    /** @return list<string> the words of the lists that ship with the core */
    private function shippedWords(): array
    {
        $words = [];
        foreach (glob($this->directory . '/*.txt') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $words[] = $line;
            }
        }

        return $words;
    }

    /** @return list<string> the words of the text, folded as the lists are */
    private function tokens(string $text): array
    {
        $tokens = preg_split('/[^a-z]+/', self::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

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
