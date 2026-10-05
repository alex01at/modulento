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
            $heading = (string) (($block['texts'][$locale]['heading'] ?? '') ?: ($block['texts'][$default]['heading'] ?? ''));
            $body = (string) (($block['texts'][$locale]['body'] ?? '') ?: ($block['texts'][$default]['body'] ?? ''));
            $view[] = ['id' => $block['id'], 'type' => $block['type'], 'enabled' => $block['enabled'], 'heading' => $heading, 'body' => $body];
        }

        return $view;
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

    public function reset(): void
    {
        $this->settings->forget(self::SETTING);
    }
}
