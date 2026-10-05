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

    /** The bar of a sign-in as another account: on every theme. */
    private const SIGN_IN_AS_CSS = <<<'CSS'
.impersonation { position: sticky; top: 0; z-index: 40; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem 1rem; padding: .5rem 1rem; background: #f0a500; color: #1c1600; font-weight: 600; }
.impersonation .inline-form { margin: 0; }
CSS;

    /** The look of the in-place editing tools and of the text editor, for every theme. */
    private const EDITING_CSS = <<<'CSS'
/* Editing a page in place (administrators, ?edit=1). The tools work without scripts. */
.inline-bar { margin: 0 0 1.25rem; padding: .75rem 1rem; border: 2px dashed var(--accent); border-radius: .6rem; background: var(--surface); display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center; justify-content: space-between; }
.inline-bar p { margin: 0; }
.inline-add { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin: 0; }
.inline-add select { width: auto; }
.inline-tools { margin: 1rem 0 .5rem; padding: .75rem 1rem; border: 1px dashed var(--accent); border-radius: .5rem; background: var(--surface); font-size: .9rem; }
.inline-tools.is-hidden-block { opacity: .6; }
.inline-tools p { margin: 0 0 .4rem; }
.inline-name .badge { margin-left: .4rem; }
.inline-steps { display: flex; flex-wrap: wrap; gap: .4rem; margin: 0 0 .5rem; }
.inline-steps button { min-width: 2.4rem; }
.inline-edit summary { cursor: pointer; color: var(--accent); font-weight: 600; }
.inline-edit-form { display: grid; gap: .6rem; margin-top: .6rem; }
.inline-edit-form .field { display: grid; gap: .25rem; }
.inline-edit-form input, .inline-edit-form textarea, .inline-edit-form select { width: 100%; }

/* The text editor of the in-place editing (editor.js). */
.editor { border: 1px solid var(--line); border-radius: 6px; background: var(--card); }
.editor-bar { display: flex; flex-wrap: wrap; gap: .25rem; padding: .4rem; border-bottom: 1px solid var(--line); background: var(--bg); border-radius: 6px 6px 0 0; }
.editor-button { padding: .25rem .55rem; border: 1px solid var(--line); border-radius: 4px; background: var(--card); color: var(--text); font-size: .8rem; cursor: pointer; }
.editor-button:hover, .editor-button[aria-pressed="true"] { border-color: var(--primary); color: var(--link); }
.editor-bold { font-weight: 700; }
.editor-italic { font-style: italic; }
.editor-area { min-height: 16rem; max-height: 40rem; overflow-y: auto; padding: .75rem 1rem; color: var(--text); line-height: 1.6; }
.editor-area:focus { outline: 2px solid var(--primary); outline-offset: -2px; }
.editor-area blockquote { margin: .5rem 0; padding-left: 1rem; border-left: 3px solid var(--line); color: var(--muted); }
.editor textarea { display: none; }
.editor.editor-source .editor-area { display: none; }
.editor.editor-source textarea { display: block; width: 100%; min-height: 16rem; border: 0; border-radius: 0 0 6px 6px; font-family: ui-monospace, monospace; font-size: .85rem; }
/* Texts on the spot, the "+" between blocks, the status line. */
.inline-editable { border-radius: .2rem; cursor: text; }
.inline-editable:hover, .inline-editable:focus-visible { outline: 1px dashed var(--accent); outline-offset: .3rem; }
.inline-editable:hover::after { content: " ✎"; color: var(--accent); font-size: .6em; vertical-align: middle; }
.inline-editable.is-editing { outline: 2px solid var(--accent); background: var(--surface); }
.inline-block { position: relative; }
.inline-off { margin: .5rem 0; color: var(--muted); font-size: .9rem; }
.inline-plus { margin: .75rem 0; text-align: center; }
.inline-plus summary { list-style: none; display: inline-flex; align-items: center; justify-content: center; width: 2.2rem; height: 2.2rem; border-radius: 50%; border: 2px dashed var(--accent); color: var(--accent); font-size: 1.3rem; font-weight: 700; cursor: pointer; }
.inline-plus summary::-webkit-details-marker { display: none; }
.inline-plus[open] summary { background: var(--accent); color: #fff; border-style: solid; }
.inline-plus-form { display: flex; flex-wrap: wrap; gap: .4rem; justify-content: center; margin-top: .5rem; }
.inline-plus-form button { padding: .35rem .8rem; border: 1px solid var(--accent); border-radius: 999px; background: var(--ground); color: var(--accent); font: inherit; cursor: pointer; }
.inline-plus-form button:hover { background: var(--accent); color: #fff; }
.inline-status { position: fixed; left: 50%; bottom: 1rem; transform: translateX(-50%); margin: 0; padding: .5rem 1rem; border-radius: .5rem; background: var(--ground); box-shadow: var(--shadow); z-index: 50; }
.inline-status.is-error { color: var(--error); }
.inline-status:empty { display: none; }
/* The toolbar of a rich text, and the line with the name and the steps of a block. */
.inline-wysiwyg { display: flex; flex-wrap: wrap; gap: .3rem; margin: 0 0 .4rem; padding: .3rem; border: 1px solid var(--accent); border-radius: .4rem; background: var(--ground); }
.inline-wysiwyg button { min-width: 2rem; padding: .25rem .5rem; border: 1px solid var(--line); border-radius: .3rem; background: var(--surface); color: var(--text); font: inherit; font-size: .85rem; cursor: pointer; }
.inline-wysiwyg button:hover { border-color: var(--accent); color: var(--accent); }
.inline-wysiwyg .inline-wysiwyg-bold { font-weight: 700; }
.inline-wysiwyg .inline-wysiwyg-italic { font-style: italic; }
.inline-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .4rem .75rem; }
.inline-head p { margin: 0; }
.inline-steps { display: flex; flex-wrap: wrap; gap: .3rem; margin: 0; }
.inline-steps button { padding: .2rem .55rem; font-size: .8rem; }
.inline-tools { padding: .5rem .75rem; }
.inline-tools .inline-edit { margin-top: .4rem; }
.inline-widget { margin-top: .4rem; }
.inline-widget summary { cursor: pointer; color: var(--accent); font-weight: 600; font-size: .9rem; }
.inline-widget-form { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; margin-top: .4rem; }
.inline-widget-form input { min-width: 14rem; }
.inline-widget-choice { font-style: italic; }
CSS;

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
            . ":root[data-theme=\"light\"] {\n" . $this->colours($v) . "}\n"
            . $this->controls();
    }

    /** A name for the stylesheet that changes with its content, so a new design is never served from a cache. */
    public function fileName(): string
    {
        return substr(sha1($this->css()), 0, 16) . '.css';
    }

    /**
     * Rules for the administration's own controls on the site (the edit link), so
     * that they look the same with every theme. Written once here, not per theme.
     */
    private function controls(): string
    {
        return ".edit-page { display: inline-flex; align-items: center; justify-content: center; width: 2.4rem; height: 2.4rem; border-radius: 50%; background: var(--accent, #1f5fbf); color: #fff; box-shadow: 0 .25rem .9rem rgb(0 0 0 / .25); text-decoration: none; }\n"
            . ".edit-page:hover, .edit-page:focus-visible { filter: brightness(1.15); color: #fff; }\n"
            . ".edit-page svg { width: 1.15rem; height: 1.15rem; }\n"
            . self::EDITING_CSS
            . self::SIGN_IN_AS_CSS;
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
