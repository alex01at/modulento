<?php

declare(strict_types=1);

namespace Modulento\Core\Content;

use Closure;
use Modulento\Core\Support\HtmlSanitizer;
use Modulento\Core\Support\Settings;

/**
 * The blocks of the home page: which sections it shows, in which order, and
 * their texts in every language. Kept in the settings, as a list. Until an
 * administrator changes the page, it shows what a fresh site shows.
 *
 * Only the content lives here; how a block looks is the theme's (its
 * home/_<type>.twig partials), so a theme decides the markup.
 */
final class HomeLayout
{
    public const SETTING = 'core.home_layout';
    public const TYPES = ['hero', 'categories', 'text', 'offers', 'providers', 'image', 'links'];
    /** The text fields of each type. */
    public const TEXT_FIELDS = [
        'hero' => ['title', 'text', 'button_label', 'button_url'],
        'text' => ['heading', 'body'],
        'categories' => ['heading'],
        'offers' => ['heading'],
        'providers' => ['heading'],
        'image' => ['caption'],
        'links' => ['heading', 'items'],
    ];
    private const MAX_BLOCKS = 30;
    private const MAX_LINE = 300;
    private const MAX_BODY = 60000;
    private const MEDIA_PATTERN = '#^/media/library/[a-f0-9]{32}\.(webp|png)$#';

    /** @param Closure(): ?string $themeFile the layout a theme brings for a site that has none of its own */
    public function __construct(private Settings $settings, private ?Closure $themeFile = null)
    {
    }

    public function isCustomized(): bool
    {
        return $this->settings->has(self::SETTING);
    }

    /**
     * The blocks as they are stored, in their order.
     *
     * @return list<array{id: string, type: string, enabled: bool, texts: array<string, array<string, string>>, settings: array<string, mixed>}>
     */
    public function blocks(): array
    {
        $data = json_decode($this->settings->get(self::SETTING), true);
        if (!is_array($data)) {
            // A site without a page of its own shows what its theme brings, else the core's start.
            $file = $this->themeFile !== null ? ($this->themeFile)() : null;
            $data = $file !== null ? json_decode((string) file_get_contents($file), true) : null;
        }
        if (!is_array($data)) {
            return [
                ['id' => 'default-hero', 'type' => 'hero', 'enabled' => true, 'texts' => [], 'settings' => []],
                ['id' => 'default-categories', 'type' => 'categories', 'enabled' => true, 'texts' => [], 'settings' => []],
                ['id' => 'default-offers', 'type' => 'offers', 'enabled' => true, 'texts' => [], 'settings' => ['count' => 6]],
                ['id' => 'default-providers', 'type' => 'providers', 'enabled' => true, 'texts' => [], 'settings' => ['count' => 3]],
            ];
        }

        $blocks = [];
        foreach ($data as $block) {
            if (!is_array($block) || !in_array($block['type'] ?? '', self::TYPES, true)) {
                continue;
            }
            $blocks[] = [
                'id' => (string) ($block['id'] ?? ''),
                'type' => $block['type'],
                'enabled' => (bool) ($block['enabled'] ?? true),
                'texts' => is_array($block['texts'] ?? null) ? $block['texts'] : [],
                'settings' => is_array($block['settings'] ?? null) ? $block['settings'] : [],
            ];
        }

        return $blocks;
    }

    /**
     * What the page shows in one language: the enabled blocks, their texts in
     * that language, else in the default language, else the wording of the core.
     *
     * @param Closure(string, array<string, string>): string $trans
     * @return list<array<string, mixed>>
     */
    public function view(string $locale, string $default, Closure $trans, string $siteName, bool $includeHidden = false): array
    {
        $view = [];
        foreach ($this->blocks() as $block) {
            if (!$block['enabled'] && !$includeHidden) {
                continue;
            }
            $texts = [];
            foreach (self::TEXT_FIELDS[$block['type']] as $field) {
                // An empty text in this language is no text: the default language stands in.
                $text = (string) ($block['texts'][$locale][$field] ?? '');
                if ($text === '') {
                    $text = (string) ($block['texts'][$default][$field] ?? '');
                }
                if ($text === '') {
                    $text = self::fallback($block['type'], $field, $trans, $siteName);
                }
                $texts[$field] = $text;
            }

            $view[] = [
                'id' => $block['id'],
                'type' => $block['type'],
                'enabled' => $block['enabled'],
                'texts' => $texts,
                'count' => max(1, min(12, (int) ($block['settings']['count'] ?? 6))),
                'media' => (string) ($block['settings']['media'] ?? ''),
                'links' => self::links($texts['items'] ?? ''),
            ];
        }

        return $view;
    }

    /**
     * Saves the blocks in this order. Each block is cleaned on the way in.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public function save(array $blocks): void
    {
        $clean = [];
        foreach (array_slice($blocks, 0, self::MAX_BLOCKS) as $block) {
            if (in_array($block['type'] ?? '', self::TYPES, true)) {
                $clean[] = $block;
            }
        }
        $this->settings->set(self::SETTING, (string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Back to the layout a fresh site shows. */
    public function reset(): void
    {
        $this->settings->forget(self::SETTING);
    }

    /** Saves one text field of one block in one language; returns the cleaned value, or null when there is no such field. */
    public function setField(string $id, string $locale, string $field, string $value): ?string
    {
        $blocks = $this->blocks();
        foreach ($blocks as $i => $block) {
            if ($block['id'] !== $id) {
                continue;
            }
            if (!in_array($field, self::TEXT_FIELDS[$block['type']] ?? [], true)) {
                return null;
            }
            $clean = self::clean($block['type'], $field, $value);
            $blocks[$i]['texts'][$locale][$field] = $clean;
            $this->save($blocks);

            return $clean;
        }

        return null;
    }

    /**
     * The blocks with a new one of a type after the block with the given id;
     * "start" puts it first.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    public static function insertAfter(array $blocks, string $afterId, string $type): array
    {
        $new = self::newBlock($type);
        if ($type === 'offers') {
            $new['settings'] = ['count' => 6];
        }
        if ($afterId === 'start') {
            return [$new, ...$blocks];
        }

        $out = [];
        $placed = false;
        foreach ($blocks as $block) {
            $out[] = $block;
            if ($block['id'] === $afterId) {
                $out[] = $new;
                $placed = true;
            }
        }

        return $placed ? $out : [...$out, $new];
    }

    /** A new, empty block of a type. */
    public static function newBlock(string $type, ?string $id = null): array
    {
        return ['id' => $id ?? bin2hex(random_bytes(4)), 'type' => $type, 'enabled' => true, 'texts' => [], 'settings' => []];
    }

    /**
     * Cleans one text field as it arrives from the form: HTML for the body
     * of a text block, plain text elsewhere, links and pictures only where
     * they are allowed.
     */
    public static function clean(string $type, string $field, string $value): string
    {
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value));

        if ($type === 'text' && $field === 'body') {
            return HtmlSanitizer::clean(mb_substr($value, 0, self::MAX_BODY));
        }
        if ($field === 'button_url') {
            return self::validUrl($value) ? $value : '';
        }
        if ($field === 'items') {
            // "Title | address" per line; lines without a usable address are dropped.
            $lines = [];
            foreach (preg_split('/\R/', $value) ?: [] as $line) {
                [$title, $url] = array_pad(explode('|', $line, 2), 2, '');
                $title = trim($title);
                $url = trim($url);
                if ($title !== '' && self::validUrl($url)) {
                    $lines[] = mb_substr($title, 0, self::MAX_LINE) . ' | ' . $url;
                }
            }

            return implode("\n", array_slice($lines, 0, 50));
        }

        return mb_substr($value, 0, self::MAX_LINE);
    }

    /** A picture of the media library, or nothing. */
    public static function cleanMedia(string $value): string
    {
        return preg_match(self::MEDIA_PATTERN, $value) === 1 ? $value : '';
    }

    /** A page of this site or a secure address elsewhere - never a script. */
    public static function validUrl(string $url): bool
    {
        return preg_match('#^(/(?!/)|https://)[^\s"<>]{0,500}$#', $url) === 1;
    }

    /** @return list<array{title: string, url: string}> */
    private static function links(string $items): array
    {
        $links = [];
        foreach (preg_split('/\R/', $items) ?: [] as $line) {
            [$title, $url] = array_pad(explode('|', $line, 2), 2, '');
            if (trim($title) !== '' && trim($url) !== '') {
                $links[] = ['title' => trim($title), 'url' => trim($url)];
            }
        }

        return $links;
    }

    private static function fallback(string $type, string $field, Closure $trans, string $siteName): string
    {
        $key = match (true) {
            $type === 'hero' && $field === 'title' => 'core.home.title',
            $type === 'hero' && $field === 'text' => 'core.home.intro',
            $type === 'categories' && $field === 'heading' => 'core.offers.categories',
            $type === 'offers' && $field === 'heading' => 'core.offers.latest',
            $type === 'providers' && $field === 'heading' => 'core.home.providers',
            default => null,
        };

        return $key === null ? '' : $trans($key, ['site_name' => $siteName]);
    }
}
