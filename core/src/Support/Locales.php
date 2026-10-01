<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Which languages the site offers, and how a language shows in a URL.
 *
 * The language of a page is part of its address: the default language has
 * no prefix ("/login"), every other one has its code in front
 * ("/en/login"). That way each language version has its own URL that can
 * be linked, bookmarked and indexed, and nothing depends on a cookie.
 */
final class Locales
{
    public const SETTING_ENABLED = 'core.languages';
    public const SETTING_DEFAULT = 'core.default_language';

    // Native names for the language menu. A language missing here still
    // works and shows its code.
    private const NAMES = [
        'bg' => 'Български', 'cs' => 'Čeština', 'da' => 'Dansk', 'de' => 'Deutsch', 'el' => 'Ελληνικά',
        'en' => 'English', 'es' => 'Español', 'et' => 'Eesti', 'fi' => 'Suomi', 'fr' => 'Français',
        'hr' => 'Hrvatski', 'hu' => 'Magyar', 'it' => 'Italiano', 'lt' => 'Lietuvių', 'lv' => 'Latviešu',
        'nl' => 'Nederlands', 'no' => 'Norsk', 'pl' => 'Polski', 'pt' => 'Português', 'ro' => 'Română',
        'ru' => 'Русский', 'sk' => 'Slovenčina', 'sl' => 'Slovenščina', 'sr' => 'Srpski', 'sv' => 'Svenska',
        'tr' => 'Türkçe', 'uk' => 'Українська',
    ];

    /** @var string[]|null */
    private ?array $enabled = null;

    public function __construct(private Settings $settings, private string $coreLangDir)
    {
    }

    /** @return string[] every language the core has a file for */
    public function available(): array
    {
        return Translator::localesIn($this->coreLangDir);
    }

    /** @return string[] the languages offered to visitors, the default one first */
    public function enabled(): array
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }

        $available = $this->available();
        $chosen = array_filter(array_map('trim', explode(',', $this->settings->get(self::SETTING_ENABLED))));
        $enabled = array_values(array_intersect($chosen, $available)) ?: $available;

        $default = $this->settings->get(self::SETTING_DEFAULT);
        if (!in_array($default, $enabled, true)) {
            $default = in_array('en', $enabled, true) ? 'en' : ($enabled[0] ?? 'en');
        }

        return $this->enabled = array_values(array_unique([$default, ...$enabled]));
    }

    public function default(): string
    {
        return $this->enabled()[0];
    }

    public function isEnabled(string $locale): bool
    {
        return in_array($locale, $this->enabled(), true);
    }

    public function name(string $locale): string
    {
        return self::NAMES[$locale] ?? strtoupper($locale);
    }

    /** @param string[] $enabled */
    public function save(string $default, array $enabled): void
    {
        $enabled = array_values(array_intersect(array_unique([$default, ...$enabled]), $this->available()));
        if (!in_array($default, $enabled, true)) {
            return;
        }

        $this->settings->set(self::SETTING_ENABLED, implode(',', $enabled));
        $this->settings->set(self::SETTING_DEFAULT, $default);
        $this->enabled = null;
    }

    /**
     * Takes a request path apart.
     *
     * @return array{locale: string, path: string, redirect: ?string} the
     *         language, the path without its language prefix, and - when
     *         the default language was requested with a prefix - the
     *         prefix-free address to redirect to, so every page has
     *         exactly one URL
     */
    public function split(string $path): array
    {
        $segments = explode('/', ltrim($path, '/'), 2);
        $first = $segments[0];
        $rest = '/' . ($segments[1] ?? '');

        if (!$this->isEnabled($first)) {
            return ['locale' => $this->default(), 'path' => $path, 'redirect' => null];
        }

        return [
            'locale' => $first,
            'path' => $rest,
            'redirect' => $first === $this->default() ? $rest : null,
        ];
    }

    /** The path as it is addressed in a language. */
    public function prefix(string $path, string $locale): string
    {
        if ($locale === $this->default() || !$this->isEnabled($locale)) {
            return $path;
        }

        return '/' . $locale . ($path === '/' ? '' : $path);
    }
}
