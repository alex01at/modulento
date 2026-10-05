<?php

declare(strict_types=1);

namespace Modulento\Core\Content;

use Modulento\Core\Support\Settings;

/**
 * The parts of an offer's page, in the order an administrator sets, and
 * which of them are shown. The core's parts are the gallery, the summary and
 * description, the part of the offer's type (its extension), the reviews and
 * the contact section; a free text block can be added and has its texts in
 * every language. The title, provider, price and rating always stay on top.
 */
final class OfferLayout
{
    public const SETTING = 'core.offer_layout';
    /** The parts that come with the core, in their default order. */
    public const BUILT_IN = ['gallery', 'summary', 'details', 'reviews', 'contact'];
    public const TYPES = ['gallery', 'summary', 'details', 'reviews', 'contact', 'text'];

    public function __construct(private Settings $settings)
    {
    }

    public function isCustomized(): bool
    {
        return $this->settings->has(self::SETTING);
    }

    /**
     * The blocks as stored, in their order.
     *
     * @return list<array{id: string, type: string, enabled: bool, texts: array<string, array<string, string>>}>
     */
    public function blocks(): array
    {
        $data = json_decode($this->settings->get(self::SETTING), true);
        if (!is_array($data)) {
            return array_map(fn (string $type) => ['id' => $type, 'type' => $type, 'enabled' => true, 'texts' => []], self::BUILT_IN);
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
            ];
        }

        return $blocks;
    }

    /**
     * What an offer page shows in one language: the enabled blocks, a text
     * block's texts in that language, else in the default language.
     *
     * @return list<array{id: string, type: string, heading: string, body: string}>
     */
    public function view(string $locale, string $default, bool $includeHidden = false): array
    {
        $view = [];
        foreach ($this->blocks() as $block) {
            if (!$block['enabled'] && !$includeHidden) {
                continue;
            }
            $text = [];
            foreach (['heading', 'body', 'button_label', 'button_url'] as $field) {
                $text[$field] = (string) (($block['texts'][$locale][$field] ?? '') ?: ($block['texts'][$default][$field] ?? ''));
            }
            $view[] = ['id' => $block['id'], 'type' => $block['type'], 'enabled' => $block['enabled']] + $text;
        }

        return $view;
    }

    /** The blocks with the block of this id copied right after it (text blocks only are copied on the offer page). */
    public function duplicate(string $id): void
    {
        $this->save(HomeLayout::duplicateAfter($this->blocks(), $id));
    }

    /** @param list<array<string, mixed>> $blocks */
    public function save(array $blocks): void
    {
        $clean = [];
        foreach (array_slice($blocks, 0, 30) as $block) {
            if (in_array($block['type'] ?? '', self::TYPES, true)) {
                $clean[] = $block;
            }
        }
        $this->settings->set(self::SETTING, (string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Saves heading or body of a text block in one language; the cleaned value, or null. */
    public function setField(string $id, string $locale, string $field, string $value): ?string
    {
        $blocks = $this->blocks();
        foreach ($blocks as $i => $block) {
            if ($block['id'] !== $id) {
                continue;
            }
            if ($block['type'] !== 'text' || !in_array($field, ['heading', 'body', 'button_label', 'button_url'], true)) {
                return null;
            }
            $clean = HomeLayout::clean('text', $field, $value);
            $blocks[$i]['texts'][$locale][$field] = $clean;
            $this->save($blocks);

            return $clean;
        }

        return null;
    }

    public function reset(): void
    {
        $this->settings->forget(self::SETTING);
    }
}
