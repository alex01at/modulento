<?php

declare(strict_types=1);

namespace Modulento\Core\Content;

use Modulento\Core\App;
use Modulento\Core\Support\Settings;

/**
 * The site's main (header) menu: the operator's own free-form entries,
 * together with the built-in catalogue/requests links and whatever an
 * installed extension contributes with Registrar::navigation() - the
 * latter two "fixed" entries get a stable id so they can be relabelled,
 * reordered and hidden exactly like a custom one, but never removed (that
 * happens by switching off the catalogue, the module, or the extension
 * itself). One ordered list, kept as JSON in the settings, the same shape
 * HomeLayout already uses for the home page's own blocks.
 */
final class CustomMenu
{
    public const SETTING = 'core.custom_menu';
    private const MAX_ITEMS = 30;
    private const MAX_LABEL = 60;

    public function __construct(private Settings $settings)
    {
    }

    /**
     * The fixed entries that currently exist - not stored, derived fresh
     * from what is actually active, so one that stops applying (catalogue
     * emptied, module switched off, extension removed) simply disappears
     * instead of lingering as a dead, uneditable row.
     *
     * @return list<array{id: string, path: string, label_key: string}>
     */
    private function fixedDefinitions(App $app): array
    {
        $items = [];
        if ($app->offers->types() !== []) {
            $items[] = ['id' => 'core.offers', 'path' => '/offers', 'label_key' => 'core.nav.offers'];
            $items[] = ['id' => 'core.providers', 'path' => '/providers', 'label_key' => 'core.nav.providers'];
        }
        if ($app->modules->enabled('requests')) {
            $items[] = ['id' => 'core.requests', 'path' => '/requests', 'label_key' => 'core.nav.requests'];
        }
        foreach ($app->navigation() as $link) {
            $items[] = ['id' => 'ext.' . $link['label_key'], 'path' => $link['path'], 'label_key' => $link['label_key']];
        }

        return $items;
    }

    /**
     * Every entry of the menu, fixed ones first (in the order they were
     * last arranged in, new ones appended at the end), then the operator's
     * own. A fixed entry's "path" is read-only (it is wired to a real
     * route) and it cannot be deleted, which the admin UI and
     * AdminMenuController both respect via "locked".
     *
     * @return list<array{id: string, kind: string, locked: bool, enabled: bool, path: string, label_key: ?string, texts: array<string, array<string, string>>}>
     */
    public function items(App $app): array
    {
        $stored = [];
        foreach ($this->decode() as $item) {
            $stored[$item['id']] = $item;
        }

        $fixed = $this->fixedDefinitions($app);
        $fixedIds = array_column($fixed, 'id');

        $items = [];
        // Fixed entries first, in their last known order, then any that are new. The key
        // is cast back to string - a purely numeric id (unlikely, but not impossible for
        // the random hex ids newItem() hands out) would otherwise come back as an int,
        // since PHP normalises a numeric array key to one.
        foreach ($stored as $id => $item) {
            $id = (string) $id;
            if (in_array($id, $fixedIds, true)) {
                $items[] = $this->withFixedDefinition($item, $fixed);
            }
        }
        foreach ($fixed as $definition) {
            if (!isset($stored[$definition['id']])) {
                $items[] = $this->withFixedDefinition(['id' => $definition['id'], 'enabled' => true, 'texts' => []], $fixed);
            }
        }
        // Then the operator's own, in their stored order - a stored id that looks fixed
        // (core./ext. prefix) but no longer matches one above belongs to a catalogue,
        // module or extension that went away, so it is dropped rather than kept as custom.
        foreach ($stored as $id => $item) {
            $id = (string) $id;
            if (!in_array($id, $fixedIds, true) && !self::looksFixed($id)) {
                $items[] = [
                    'id' => $id,
                    'kind' => 'custom',
                    'locked' => false,
                    'enabled' => (bool) ($item['enabled'] ?? true),
                    'path' => is_string($item['path'] ?? null) ? $item['path'] : '',
                    'label_key' => null,
                    'texts' => is_array($item['texts'] ?? null) ? $item['texts'] : [],
                ];
            }
        }

        return $items;
    }

    /** @param array<string, mixed> $item @param list<array{id: string, path: string, label_key: string}> $fixed */
    private function withFixedDefinition(array $item, array $fixed): array
    {
        $definition = null;
        foreach ($fixed as $candidate) {
            if ($candidate['id'] === $item['id']) {
                $definition = $candidate;
                break;
            }
        }
        // Should not happen - the caller only passes ids present in $fixed.
        if ($definition === null) {
            $definition = ['id' => $item['id'], 'path' => '', 'label_key' => null];
        }

        return [
            'id' => $item['id'],
            'kind' => 'fixed',
            'locked' => true,
            'enabled' => (bool) ($item['enabled'] ?? true),
            'path' => $definition['path'],
            'label_key' => $definition['label_key'],
            'texts' => is_array($item['texts'] ?? null) ? $item['texts'] : [],
        ];
    }

    /**
     * The menu exactly as stored - the operator's own entries and whatever
     * was customized about a fixed one (its order, label or enabled flag),
     * nothing recomputed from the catalogue or installed extensions. For
     * export/import (App::addExportable()); items($app) is what every
     * other caller wants.
     *
     * @return list<array<string, mixed>>
     */
    public function storedItems(): array
    {
        return $this->decode();
    }

    private static function looksFixed(string $id): bool
    {
        return str_starts_with($id, 'core.') || str_starts_with($id, 'ext.');
    }

    /** @return list<array<string, mixed>> */
    private function decode(): array
    {
        $data = json_decode($this->settings->get(self::SETTING), true);
        if (!is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The enabled entries in one language, in menu order: the label in
     * that language, else the default language's, else (for a fixed
     * entry only) the built-in translation - a custom entry with no label
     * in either, or no path, is left out rather than shown blank or going
     * nowhere.
     *
     * @return list<array{path: string, label: string}>
     */
    public function view(App $app, string $locale, string $default): array
    {
        $view = [];
        foreach ($this->items($app) as $item) {
            if (!$item['enabled'] || $item['path'] === '') {
                continue;
            }
            $label = (string) ($item['texts'][$locale]['label'] ?? '');
            if ($label === '') {
                $label = (string) ($item['texts'][$default]['label'] ?? '');
            }
            if ($label === '' && $item['label_key'] !== null) {
                $label = $app->translator->trans($item['label_key']);
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
                $clean[] = [
                    'id' => $item['id'],
                    'enabled' => (bool) ($item['enabled'] ?? true),
                    'path' => is_string($item['path'] ?? null) ? $item['path'] : '',
                    'texts' => is_array($item['texts'] ?? null) ? $item['texts'] : [],
                ];
            }
        }
        $this->settings->set(self::SETTING, (string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** A new, empty custom entry. */
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
