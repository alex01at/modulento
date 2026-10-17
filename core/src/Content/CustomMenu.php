<?php

declare(strict_types=1);

namespace Modulento\Core\Content;

use Modulento\Core\Support\Settings;

/**
 * Extra entries in the site's main (header) menu, added by the operator -
 * next to whatever an installed extension already contributes with
 * Registrar::navigation(). One ordered list, kept as JSON in the settings,
 * the same shape HomeLayout already uses for the home page's own blocks.
 */
final class CustomMenu
{
    public const SETTING = 'core.custom_menu';
    private const MAX_ITEMS = 30;
    private const MAX_LABEL = 60;

    public function __construct(private Settings $settings)
    {
    }

    /** @return list<array{id: string, enabled: bool, path: string, texts: array<string, array<string, string>>}> */
    public function items(): array
    {
        $data = json_decode($this->settings->get(self::SETTING), true);
        if (!is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null)) {
                continue;
            }
            $items[] = [
                'id' => $item['id'],
                'enabled' => (bool) ($item['enabled'] ?? true),
                'path' => is_string($item['path'] ?? null) ? $item['path'] : '',
                'texts' => is_array($item['texts'] ?? null) ? $item['texts'] : [],
            ];
        }

        return $items;
    }

    /**
     * The enabled entries in one language: the label in that language, else
     * the default language's - an entry with no label in either, or no
     * path, is left out rather than shown blank or going nowhere.
     *
     * @return list<array{path: string, label: string}>
     */
    public function view(string $locale, string $default): array
    {
        $view = [];
        foreach ($this->items() as $item) {
            if (!$item['enabled'] || $item['path'] === '') {
                continue;
            }
            $label = (string) ($item['texts'][$locale]['label'] ?? '');
            if ($label === '') {
                $label = (string) ($item['texts'][$default]['label'] ?? '');
            }
            if ($label === '') {
                continue;
            }
            $view[] = ['path' => $item['path'], 'label' => $label];
        }

        return $view;
    }

    /** @param list<array<string, mixed>> $items */
    public function save(array $items): void
    {
        $clean = [];
        foreach (array_slice($items, 0, self::MAX_ITEMS) as $item) {
            if (is_string($item['id'] ?? null) && $item['id'] !== '') {
                $clean[] = $item;
            }
        }
        $this->settings->set(self::SETTING, (string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** A new, empty entry. */
    public static function newItem(): array
    {
        return ['id' => bin2hex(random_bytes(4)), 'enabled' => true, 'path' => '', 'texts' => []];
    }

    /** A page of this site or a secure address elsewhere - never a script. The same rule HomeLayout's own links use. */
    public static function validPath(string $path): bool
    {
        return preg_match('#^(/(?!/)|https://)[^\s"<>]{0,500}$#', $path) === 1;
    }

    public static function cleanLabel(string $value): string
    {
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value));

        return mb_substr($value, 0, self::MAX_LABEL);
    }
}
