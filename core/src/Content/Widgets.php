<?php

declare(strict_types=1);

namespace Modulento\Core\Content;

use Closure;
use Modulento\Core\Support\Settings;

/**
 * Ready-made blocks an administrator inserts with one click: the widgets the core
 * ships, and the ones an administrator saved from a block of their own. A widget is
 * a block with its kind, its texts in every language and its settings; inserting it
 * makes a new block with a new id, so the copies do not share anything.
 */
final class Widgets
{
    public const SETTING = 'core.widgets';
    private const MAX_OWN = 50;

    /** The widgets the core ships, with their texts in both languages. */
    private const SHIPPED = [
        'cta' => [
            'label' => 'core.widget.cta',
            'texts' => [
                'de' => ['heading' => 'Bereit loslegen?', 'body' => '<p>Starte in wenigen Minuten und sieh sofort, was möglich ist.</p>', 'button_label' => 'Jetzt starten', 'button_url' => '/register'],
                'en' => ['heading' => 'Ready to start?', 'body' => '<p>Get going in a few minutes and see at once what is possible.</p>', 'button_label' => 'Get started', 'button_url' => '/register'],
            ],
        ],
        'note' => [
            'label' => 'core.widget.note',
            'texts' => [
                'de' => ['heading' => 'Gut zu wissen', 'body' => '<p>Hier steht eine wichtige Information, die nicht untergehen soll.</p>'],
                'en' => ['heading' => 'Good to know', 'body' => '<p>Here stands an important piece of information that should not be missed.</p>'],
            ],
        ],
        'benefits' => [
            'label' => 'core.widget.benefits',
            'texts' => [
                'de' => ['heading' => 'Ihre Vorteile', 'body' => '<ul><li>Klare Preise ohne versteckte Kosten</li><li>Zahlung direkt an den Anbieter</li><li>Bewertungen aus echten Bestellungen</li></ul>'],
                'en' => ['heading' => 'Your benefits', 'body' => '<ul><li>Clear prices, no hidden costs</li><li>Payment goes directly to the provider</li><li>Reviews from real orders</li></ul>'],
            ],
        ],
        'faq' => [
            'label' => 'core.widget.faq',
            'texts' => [
                'de' => ['heading' => 'Häufige Fragen', 'body' => '<h3>Wie bezahle ich?</h3><p>Je nach Anbieter per Überweisung, PayPal oder Stripe.</p><h3>Was passiert bei Problemen?</h3><p>Du kannst Korrekturen anfordern oder ein Anliegen melden.</p>'],
                'en' => ['heading' => 'Frequently asked questions', 'body' => '<h3>How do I pay?</h3><p>Depending on the provider: by bank transfer, PayPal or Stripe.</p><h3>What happens if something goes wrong?</h3><p>You can ask for revisions or report a concern.</p>'],
            ],
        ],
        'more' => [
            'label' => 'core.widget.more',
            'texts' => [
                'de' => ['heading' => 'Mehr erfahren', 'body' => '<p>Schau dir an, wer hier anbietet.</p>', 'button_label' => 'Anbieter ansehen', 'button_url' => '/providers'],
                'en' => ['heading' => 'Learn more', 'body' => '<p>See who offers services here.</p>', 'button_label' => 'See providers', 'button_url' => '/providers'],
            ],
        ],
    ];

    public function __construct(private Settings $settings)
    {
    }

    /**
     * Every widget that can be inserted: the shipped ones, then the own ones.
     *
     * @param Closure(string): string $trans
     * @return list<array{key: string, label: string, own: bool}>
     */
    public function all(Closure $trans): array
    {
        $list = [];
        foreach (self::SHIPPED as $key => $widget) {
            $list[] = ['key' => 'builtin:' . $key, 'label' => $trans($widget['label']), 'own' => false];
        }
        foreach ($this->own() as $widget) {
            $list[] = ['key' => 'own:' . $widget['id'], 'label' => $widget['name'], 'own' => true];
        }

        return $list;
    }

    /** A new block made from a widget, with a new id; null for an unknown widget. */
    public function block(string $key): ?array
    {
        if (preg_match('/^(builtin|own):([a-z0-9-]{1,40})$/', $key, $match) !== 1) {
            return null;
        }
        if ($match[1] === 'builtin') {
            $widget = self::SHIPPED[$match[2]] ?? null;
            if ($widget === null) {
                return null;
            }

            return ['id' => bin2hex(random_bytes(4)), 'type' => 'text', 'enabled' => true, 'texts' => $widget['texts'], 'settings' => []];
        }
        foreach ($this->own() as $widget) {
            if ($widget['id'] === $match[2]) {
                return ['id' => bin2hex(random_bytes(4)), 'type' => $widget['type'], 'enabled' => true, 'texts' => $widget['texts'], 'settings' => $widget['settings']];
            }
        }

        return null;
    }

    /** Keeps a block as a widget of its own; its texts in every language come with it. */
    public function saveOwn(string $name, array $block): bool
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        $own = $this->own();
        if ($name === '' || mb_strlen($name) > 60 || count($own) >= self::MAX_OWN) {
            return false;
        }

        $own[] = [
            'id' => bin2hex(random_bytes(4)),
            'name' => $name,
            'type' => $block['type'],
            'texts' => $block['texts'] ?? [],
            'settings' => $block['settings'] ?? [],
        ];
        $this->store($own);

        return true;
    }

    public function removeOwn(string $id): void
    {
        $this->store(array_values(array_filter($this->own(), fn (array $widget) => $widget['id'] !== $id)));
    }

    /** @return list<array{id: string, name: string, type: string, texts: array<string, array<string, string>>, settings: array<string, mixed>}> */
    public function own(): array
    {
        $data = json_decode($this->settings->get(self::SETTING), true);

        return is_array($data) ? array_values(array_filter($data, fn ($widget) => is_array($widget) && isset($widget['id'], $widget['name'], $widget['type']))) : [];
    }

    private function store(array $own): void
    {
        $this->settings->set(self::SETTING, (string) json_encode($own, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
