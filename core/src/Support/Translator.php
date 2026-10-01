<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use LogicException;

final class Translator
{
    public const SUPPORTED_LOCALES = ['de', 'en'];

    /** @var array<string, string> */
    private array $translations = [];

    public function __construct(private string $locale, private string $siteName)
    {
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Loads <langDir>/<locale>.php. Every key must start with "<prefix>." -
     * "core." for the core, the extension id for an extension - so two
     * extensions can never overwrite each other's or the core's texts.
     */
    public function load(string $langDir, string $prefix): void
    {
        $file = $langDir . '/' . $this->locale . '.php';
        if (!is_file($file)) {
            return;
        }

        $translations = require $file;

        foreach (array_keys($translations) as $key) {
            if (!str_starts_with((string) $key, $prefix . '.')) {
                throw new LogicException("Language key \"{$key}\" in {$file} must start with \"{$prefix}.\"");
            }
        }

        $this->translations += $translations;
    }

    public function trans(string $key, array $replacements = []): string
    {
        $text = $this->translations[$key] ?? $key;
        $replacements += ['site_name' => $this->siteName];

        foreach ($replacements as $placeholder => $value) {
            $text = str_replace('{' . $placeholder . '}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * The first supported language in the browser's preference order;
     * anything else (unsupported language, missing header) falls back to
     * English.
     */
    public static function detectLocale(?string $acceptLanguageHeader): string
    {
        if ($acceptLanguageHeader === null || trim($acceptLanguageHeader) === '') {
            return 'en';
        }

        $entries = [];
        foreach (explode(',', $acceptLanguageHeader) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $pieces = explode(';q=', $part);
            $entries[] = [strtolower(trim($pieces[0])), isset($pieces[1]) ? (float) $pieces[1] : 1.0];
        }

        usort($entries, fn (array $a, array $b) => $b[1] <=> $a[1]);

        foreach ($entries as [$tag]) {
            $primary = substr($tag, 0, 2);
            if (in_array($primary, self::SUPPORTED_LOCALES, true)) {
                return $primary;
            }
        }

        return 'en';
    }
}
