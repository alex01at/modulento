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
        'text' => ['heading', 'body', 'button_label', 'button_url'],
        'categories' => ['heading'],
        'offers' => ['heading'],
        'providers' => ['heading'],
        'image' => ['caption'],
        'links' => ['heading', 'items'],
    ];
    public const MAX_BLOCKS = 30;
    private const MAX_LINE = 300;
    private const MAX_BODY = 60000;
    private const MEDIA_PATTERN = '#^/media/library/[a-f0-9]{32}\.(webp|png)$#';

    /** @param Closure(): ?string $themeFile the layout a theme brings for a site that has none of its own */
    public function __construct(private Settings $settings, private ?Closure $themeFile = null)
    {
    }

    /**
     * The built-in types plus whatever extensions registered
     * (Registrar::pageBlockType()).
     *
     * @param array<string, array{label_key: string, text_fields: string[], template: string}> $extra App::pageBlockTypes()
     * @return string[]
     */
    public static function types(array $extra = []): array
    {
        return [...self::TYPES, ...array_keys($extra)];
    }

    /**
     * @param array<string, array{label_key: string, text_fields: string[], template: string}> $extra App::pageBlockTypes()
     * @return array<string, string[]>
     */
    public static function textFields(array $extra = []): array
    {
        $fields = self::TEXT_FIELDS;
        foreach ($extra as $id => $type) {
            $fields[$id] = $type['text_fields'];
        }

        return $fields;
    }

    /**
     * Every type with the language key for its name in the "+" menu.
     *
     * @param array<string, array{label_key: string, text_fields: string[], template: string}> $extra App::pageBlockTypes()
     * @return array<string, string>
     */
    public static function typeLabels(array $extra = []): array
    {
        $labels = [];
        foreach (self::TYPES as $type) {
            $labels[$type] = 'core.home.type.' . $type;
        }
        foreach ($extra as $id => $type) {
            $labels[$id] = $type['label_key'];
        }

        return $labels;
    }

    /** The template that renders one block: the theme's built-in convention, or an extension's own for a type it registered. */
    public static function template(string $type, array $extra = []): string
    {
        return $extra[$type]['template'] ?? ('home/_' . $type . '.twig');
    }

    public function isCustomized(): bool
    {
        return $this->settings->has(self::SETTING);
    }

    /**
     * The blocks as they are stored, in their order.
     *
     * @param array<string, array{label_key: string, text_fields: string[], template: string}> $extra App::pageBlockTypes()
     * @return list<array{id: string, type: string, enabled: bool, texts: array<string, array<string, string>>, settings: array<string, mixed>}>
     */
    public function blocks(array $extra = []): array
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
            if (!is_array($block) || !in_array($block['type'] ?? '', self::types($extra), true)) {
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
     * @param array<string, array{label_key: string, text_fields: string[], template: string}> $extra App::pageBlockTypes()
     * @return list<array<string, mixed>>
     */
    public function view(string $locale, string $default, Closure $trans, string $siteName, bool $includeHidden = false, array $extra = []): array
    {
        $view = [];
        foreach ($this->blocks($extra) as $block) {
            if (!$block['enabled'] && !$includeHidden) {
                continue;
            }
            $texts = [];
            foreach (self::textFields($extra)[$block['type']] as $field) {
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
                'template' => self::template($block['type'], $extra),
                'label_key' => self::typeLabels($extra)[$block['type']] ?? ('core.home.type.' . $block['type']),
                'texts' => $texts,
                'count' => max(1, min(12, (int) ($block['settings']['count'] ?? 6))),
                'media' => (string) ($block['settings']['media'] ?? ''),
                'links' => self::links($texts['items'] ?? ''),
                // The raw values, for the admin form to show what is set - the computed
                // CSS itself is never inline (the site's CSP has no style-src 'unsafe-inline'),
                // it lives in css() below, served as part of the design stylesheet.
                'background_type' => (string) ($block['settings']['background_type'] ?? ''),
                'background_color' => (string) ($block['settings']['background_color'] ?? ''),
                'background_color2' => (string) ($block['settings']['background_color2'] ?? ''),
                'background_media' => (string) ($block['settings']['background_media'] ?? ''),
                'text_color' => (string) ($block['settings']['text_color'] ?? ''),
                'shadow' => (bool) ($block['settings']['shadow'] ?? false),
                'shape_top' => (bool) ($block['settings']['shape_top'] ?? false),
                'shape_bottom' => (bool) ($block['settings']['shape_bottom'] ?? false),
                'shape_color' => (string) ($block['settings']['shape_color'] ?? ''),
            ];
        }

        return $view;
    }

    /**
     * Saves the blocks in this order. Each block is cleaned on the way in.
     *
     * @param list<array<string, mixed>> $blocks
     * @param array<string, array{label_key: string, text_fields: string[], template: string}> $extra App::pageBlockTypes()
     */
    public function save(array $blocks, array $extra = []): void
    {
        $clean = [];
        foreach (array_slice($blocks, 0, self::MAX_BLOCKS) as $block) {
            if (in_array($block['type'] ?? '', self::types($extra), true)) {
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

    /**
     * Saves one text field of one block in one language; returns the cleaned value, or null when there is no such field.
     *
     * @param array<string, array{label_key: string, text_fields: string[], template: string}> $extra App::pageBlockTypes()
     */
    public function setField(string $id, string $locale, string $field, string $value, array $extra = []): ?string
    {
        $blocks = $this->blocks($extra);
        foreach ($blocks as $i => $block) {
            if ($block['id'] !== $id) {
                continue;
            }
            if (!in_array($field, self::textFields($extra)[$block['type']] ?? [], true)) {
                return null;
            }
            $clean = self::clean($block['type'], $field, $value);
            $blocks[$i]['texts'][$locale][$field] = $clean;
            $this->save($blocks, $extra);

            return $clean;
        }

        return null;
    }

    /**
     * The blocks in the order of the ids. An id that is not there is skipped, a
     * repeated one counts once, and a block whose id is not listed keeps its
     * place after the listed ones.
     *
     * @param list<array<string, mixed>> $blocks
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    public static function ordered(array $blocks, array $ids): array
    {
        $byId = [];
        foreach ($blocks as $block) {
            $byId[(string) $block['id']] = $block;
        }

        $out = [];
        foreach (array_unique($ids) as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
                unset($byId[$id]);
            }
        }

        return array_merge($out, array_values(array_filter($blocks, fn (array $block) => isset($byId[(string) $block['id']]))));
    }

    /** The blocks with the block of this id copied right after it, with a new id and the same content. */
    public static function duplicateAfter(array $blocks, string $id): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $out[] = $block;
            if ($block['id'] === $id) {
                $copy = $block;
                $copy['id'] = bin2hex(random_bytes(4));
                $out[] = $copy;
            }
        }

        return $out;
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

        return self::placeAfter($blocks, $afterId, $new);
    }

    /**
     * The blocks with the given block placed after the block with the given id; "start" puts it first.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    public static function placeAfter(array $blocks, string $afterId, array $new): array
    {
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

    public static function validColour(string $value): bool
    {
        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1;
    }

    /**
     * CSS rules for every block of this list that has its own background
     * and/or text colour/shadow, one rule per block targeted by
     * `[data-home-block="{id}"]` (an attribute, not an id - a theme's own
     * block may already carry a fixed id of its own, e.g. as a page anchor,
     * and must not collide with this one) - never an inline `style=""`
     * (the site's Content-Security-Policy allows no `style-src
     * 'unsafe-inline'`, only same-origin stylesheets). Meant to be appended
     * to the design stylesheet, which every theme already loads on every
     * page. Public and static, over a plain block list rather than
     * `$this->blocks()`: Pages::blockCss() builds the same rules for every
     * page's own blocks, not just the home page's.
     *
     * @param list<array{id: string, settings: array<string, mixed>}> $blocks
     */
    public static function cssFor(array $blocks): string
    {
        $css = '';
        foreach ($blocks as $block) {
            $background = self::backgroundStyle($block['settings']);
            if ($background !== '') {
                $css .= '[data-home-block="' . $block['id'] . '"] {' . "\n    " . $background . "\n}\n";
            }
            // The text colour/shadow apply to the block itself and every
            // descendant, not just what it inherits from - the same
            // specificity (one attribute selector) as a theme's own
            // elemental rules (e.g. "h1, h2, h3 { color: ... }"), so this
            // wins only because the design stylesheet loads after a
            // theme's own, the same way backgroundStyle() above already
            // relies on for the block's background. A `.button`'s own
            // class selector still outranks it, so button text stays
            // exactly as the theme styles it.
            $text = self::textStyle($block['settings']);
            if ($text !== '') {
                // ":not(.button)" - a button inside the block (the hero's or
                // a text block's own button_label/button_url) keeps the
                // theme's own button colour; it sits on its own background,
                // and a flat text colour meant for the block's prose could
                // easily make it illegible against it.
                $css .= '[data-home-block="' . $block['id'] . '"], [data-home-block="' . $block['id'] . '"] *:not(.button) {' . "\n    " . $text . "\n}\n";
            }
            $css .= self::shapeCss($block['id'], $block['settings']);
        }

        return $css;
    }

    /**
     * The decorative wave divider(s) a block's own settings ask for, at its
     * top and/or bottom edge - a self-contained CSS shape (an SVG data URI
     * on a pseudo-element, `fill="currentColor"` picking up the `color`
     * declaration right next to it), not a template change: every theme's
     * existing `[data-home-block]` element already carries this for free.
     * `position: relative` only applies when a shape is actually asked for,
     * so a block without one is never nudged into a new stacking context it
     * did not have before.
     */
    private static function shapeCss(string $id, array $settings): string
    {
        $top = !empty($settings['shape_top']);
        $bottom = !empty($settings['shape_bottom']);
        if (!$top && !$bottom) {
            return '';
        }

        $colour = is_string($settings['shape_color'] ?? null) && self::validColour($settings['shape_color']) ? $settings['shape_color'] : '#ffffff';
        $svg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1440 120' preserveAspectRatio='none'%3E%3Cpath fill='currentColor' d='M0,64 C240,120 480,0 720,32 C960,64 1200,120 1440,64 L1440,120 L0,120 Z'/%3E%3C/svg%3E";
        $shared = "content: ''; position: absolute; left: 0; width: 100%; height: 7vw; min-height: 2.5rem; max-height: 6rem;"
            . " background-image: url(\"{$svg}\"); background-size: 100% 100%; background-repeat: no-repeat; color: {$colour}; pointer-events: none;";

        $css = '[data-home-block="' . $id . '"] { position: relative; }' . "\n";
        if ($top) {
            // The same shape as the bottom one, flipped: its flat edge now touches the block's own top edge,
            // the curve dips down into the block's content rather than out of it.
            $css .= '[data-home-block="' . $id . '"]::before {' . "\n    " . $shared . ' top: -1px; transform: scaleY(-1); z-index: 1;' . "\n}\n";
        }
        if ($bottom) {
            $css .= '[data-home-block="' . $id . '"]::after {' . "\n    " . $shared . ' bottom: -1px; z-index: 1;' . "\n}\n";
        }

        return $css;
    }

    /**
     * The background a block's own settings ask for, as a ready
     * "property: value;" string - empty for none. Public: Pages::view()
     * reuses it for the same block catalogue on a normal page.
     */
    public static function backgroundStyle(array $settings): string
    {
        $type = $settings['background_type'] ?? null;
        $colour = fn (string $key) => is_string($settings[$key] ?? null) && self::validColour($settings[$key]) ? $settings[$key] : null;

        return match (true) {
            $type === 'color' && $colour('background_color') !== null => 'background: ' . $colour('background_color') . ';',
            $type === 'gradient' && $colour('background_color') !== null && $colour('background_color2') !== null
                => 'background: linear-gradient(135deg, ' . $colour('background_color') . ', ' . $colour('background_color2') . ');',
            $type === 'image' && self::cleanMedia((string) ($settings['background_media'] ?? '')) !== ''
                => 'background-image: url(' . self::cleanMedia((string) $settings['background_media']) . '); background-size: cover; background-position: center;',
            default => '',
        };
    }

    /**
     * A block's own text colour and/or shadow, as a ready "property: value;"
     * string - empty for none. `!important`, deliberately, unlike
     * backgroundStyle(): that one only has to outrank a same-specificity
     * theme rule on the very same element, which it already does by
     * loading after a theme's own stylesheet, but this one reaches into
     * the block's descendants (its own heading, its own paragraph, ...),
     * where a theme may well have a more specific rule of its own (e.g.
     * Audigo's own ".hero-auction h1"/ ".hero-auction .lead") - this is an
     * administrator's explicit, per-block choice, the same kind of
     * override an inline `style=""` would be if the site's CSP allowed
     * one (Design::css()'s own ".edit-page" rule already sets the same
     * precedent, for the same reason). Public for the same reason
     * backgroundStyle() is: Pages reuses it for a normal page's blocks.
     */
    public static function textStyle(array $settings): string
    {
        $colour = $settings['text_color'] ?? null;
        $style = is_string($colour) && self::validColour($colour) ? 'color: ' . $colour . ' !important;' : '';
        if (!empty($settings['shadow'])) {
            // A soft blur alone all but disappears against a busy photo - a
            // four-direction dark outline (the standard "legible over any
            // image" trick) plus a wider, softer blur behind it for depth.
            $style .= ($style !== '' ? ' ' : '') . 'text-shadow: -1px -1px 2px rgb(0 0 0 / .8), 1px -1px 2px rgb(0 0 0 / .8), '
                . '-1px 1px 2px rgb(0 0 0 / .8), 1px 1px 2px rgb(0 0 0 / .8), 0 4px 16px rgb(0 0 0 / .55) !important;';
        }

        return $style;
    }

    /**
     * @return list<array{title: string, url: string}>
     *
     * Public: Pages::view() reuses it for the "links" block type.
     */
    public static function links(string $items): array
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
