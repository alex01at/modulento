<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * The design values an administrator can change without touching a theme:
 * the accent and background colours, the text colour, the font and the
 * corner radius. They are written as one small stylesheet that a theme's
 * page loads after its own; the theme's templates stay as they are.
 *
 * Colours apply to the light colour scheme. Where the device or the account
 * asks for dark, the theme's dark colours stay in charge.
 */
final class Design
{
    public const SETTING = 'core.design';
    public const FONTS = [
        'system' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        'serif' => 'Georgia, "Times New Roman", serif',
        'humanist' => '"Gill Sans", "Gill Sans MT", "Trebuchet MS", sans-serif',
        'mono' => 'ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace',
    ];
    public const DEFAULTS = [
        'accent' => '#1f5fbf',
        'ground' => '#ffffff',
        'text' => '#1c2430',
        'font' => 'system',
        'radius' => 5,
    ];
    private const MAX_RADIUS = 24;

    public function __construct(private Settings $settings)
    {
    }

    public function isCustomized(): bool
    {
        return $this->settings->has(self::SETTING);
    }

    /** @return array{accent: string, ground: string, text: string, font: string, radius: int} */
    public function values(): array
    {
        $stored = json_decode($this->settings->get(self::SETTING), true);
        $stored = is_array($stored) ? $stored : [];

        $values = self::DEFAULTS;
        foreach (['accent', 'ground', 'text'] as $key) {
            if (isset($stored[$key]) && self::validColour((string) $stored[$key])) {
                $values[$key] = strtolower((string) $stored[$key]);
            }
        }
        if (isset($stored['font']) && isset(self::FONTS[$stored['font']])) {
            $values['font'] = (string) $stored['font'];
        }
        if (isset($stored['radius']) && is_numeric($stored['radius'])) {
            $values['radius'] = max(0, min(self::MAX_RADIUS, (int) $stored['radius']));
        }

        return $values;
    }

    /**
     * Saves what an administrator sent. Anything not valid keeps the value it
     * had: a colour that is not a hex colour, an unknown font.
     *
     * @return list<string> the names of the values that were refused
     */
    public function save(array $input): array
    {
        $current = $this->values();
        $refused = [];
        $new = $current;

        foreach (['accent', 'ground', 'text'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            if (self::validColour($value)) {
                $new[$key] = strtolower($value);
            } else {
                $refused[] = $key;
            }
        }
        $font = (string) ($input['font'] ?? '');
        if (isset(self::FONTS[$font])) {
            $new['font'] = $font;
        } else {
            $refused[] = 'font';
        }
        $radius = $input['radius'] ?? null;
        if (is_numeric($radius) && (int) $radius >= 0 && (int) $radius <= self::MAX_RADIUS) {
            $new['radius'] = (int) $radius;
        } else {
            $refused[] = 'radius';
        }

        $this->settings->set(self::SETTING, (string) json_encode($new, JSON_THROW_ON_ERROR));

        return $refused;
    }

    /** Back to the design the theme ships with. */
    public function reset(): void
    {
        $this->settings->forget(self::SETTING);
    }

    /** The stylesheet for the current values. */
    public function css(): string
    {
        $v = $this->values();

        return ":root {\n    --font: " . self::FONTS[$v['font']] . ";\n    --radius: " . $v['radius'] . "px;\n}\n"
            . "@media (prefers-color-scheme: light) {\n    :root:not([data-theme=\"dark\"]) {\n" . $this->colours($v) . "    }\n}\n"
            . ":root[data-theme=\"light\"] {\n" . $this->colours($v) . "}\n";
    }

    /** A name for the stylesheet that changes with its content, so a new design is never served from a cache. */
    public function fileName(): string
    {
        return substr(sha1($this->css()), 0, 16) . '.css';
    }

    private function colours(array $v): string
    {
        return "        --accent: {$v['accent']};\n        --ground: {$v['ground']};\n        --text: {$v['text']};\n";
    }

    private static function validColour(string $value): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }
}
