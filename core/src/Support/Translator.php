<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use LogicException;

/**
 * Texts of the interface. A language is a file "<locale>.php" in a language
 * folder; which languages exist is decided by the files in core/lang, not
 * by code.
 *
 * A key is looked up in the current language, then in the site's default
 * language, then in English, so a half-translated language pack shows the
 * rest in a language people can read instead of raw keys.
 */
final class Translator
{
    private const LAST_RESORT = 'en';

    /** @var array<int, array{dir: string, prefix: ?string}> */
    private array $sources = [];
    /** @var array<string, array<string, string>> locale => texts, built on first use */
    private array $catalogs = [];
    private string $fallback = self::LAST_RESORT;

    public function __construct(private string $locale, private string $siteName)
    {
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /** The site's default language, used where the current one lacks a text. */
    public function setFallback(string $locale): void
    {
        $this->fallback = $locale;
    }

    public function setSiteName(string $siteName): void
    {
        $this->siteName = $siteName;
    }

    /**
     * Registers a language folder. Every key in it must start with
     * "<prefix>." - "core." for the core, the extension id for an extension
     * - so two extensions can never overwrite each other's or the core's
     * texts.
     */
    public function load(string $langDir, string $prefix): void
    {
        $this->sources[] = ['dir' => $langDir, 'prefix' => $prefix];
        $this->catalogs = [];
    }

    /**
     * Registers the site's own language folder (lang/ in the installation).
     * It is read last and may contain any key: this is where an operator
     * rewords texts or adds a language without editing core or extension
     * files, and it survives updates.
     */
    public function loadOverrides(string $langDir): void
    {
        $this->sources[] = ['dir' => $langDir, 'prefix' => null];
        $this->catalogs = [];
    }

    public function trans(string $key, array $replacements = [], ?string $locale = null): string
    {
        $text = $key;
        foreach (array_unique([$locale ?? $this->locale, $this->fallback, self::LAST_RESORT]) as $candidate) {
            $catalog = $this->catalog($candidate);
            if (isset($catalog[$key])) {
                $text = $catalog[$key];
                break;
            }
        }

        $replacements += ['site_name' => $this->siteName];
        foreach ($replacements as $placeholder => $value) {
            $text = str_replace('{' . $placeholder . '}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * Runs $fn with another current language - for an e-mail written in
     * the recipient's language rather than the visitor's.
     */
    public function inLocale(string $locale, callable $fn): mixed
    {
        $previous = $this->locale;
        $this->locale = $locale;

        try {
            return $fn();
        } finally {
            $this->locale = $previous;
        }
    }

    /** @return string[] locales that have a file in $langDir, sorted */
    public static function localesIn(string $langDir): array
    {
        $locales = [];
        foreach (glob($langDir . '/*.php') ?: [] as $file) {
            $locale = basename($file, '.php');
            if (self::isLocaleCode($locale)) {
                $locales[] = $locale;
            }
        }
        sort($locales);

        return $locales;
    }

    /** Two-letter language code. It appears in URLs and file names, so nothing else is accepted. */
    public static function isLocaleCode(string $locale): bool
    {
        return preg_match('/^[a-z]{2}$/', $locale) === 1;
    }

    /**
     * The first of $supported in the browser's order of preference, or
     * $default if the browser asks for none of them.
     *
     * @param string[] $supported
     */
    public static function detectLocale(?string $acceptLanguageHeader, array $supported, string $default): string
    {
        if ($acceptLanguageHeader === null || trim($acceptLanguageHeader) === '') {
            return $default;
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
            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return $default;
    }

    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        if (isset($this->catalogs[$locale])) {
            return $this->catalogs[$locale];
        }

        $catalog = [];
        foreach ($this->sources as $source) {
            $file = $source['dir'] . '/' . $locale . '.php';
            if (!self::isLocaleCode($locale) || !is_file($file)) {
                continue;
            }

            $texts = require $file;
            if (!is_array($texts)) {
                continue;
            }

            if ($source['prefix'] !== null) {
                foreach (array_keys($texts) as $key) {
                    if (!str_starts_with((string) $key, $source['prefix'] . '.')) {
                        throw new LogicException("Language key \"{$key}\" in {$file} must start with \"{$source['prefix']}.\"");
                    }
                }
            }

            $catalog = array_replace($catalog, $texts);
        }

        return $this->catalogs[$locale] = $catalog;
    }
}
