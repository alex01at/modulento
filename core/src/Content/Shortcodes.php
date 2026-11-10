<?php

declare(strict_types=1);

namespace Modulento\Core\Content;

use Modulento\Core\App;
use Throwable;

/**
 * Placeholders in a text block's body ("[gallery summer]", "[faq extension=shop]")
 * that an extension turns into its own HTML (Registrar::shortcode()). Only
 * names an extension registered are touched - any other "[...]" in a text
 * stays as typed.
 *
 * Syntax: "[name]", "[name:value]", "[name value]" and "[name key=value
 * key2=\"two words\"]". A bare value is argument 0 (so "[gallery:summer]" and
 * "[gallery summer]" mean the same), named ones are keyed by their name.
 */
final class Shortcodes
{
    public const MAX_PER_TEXT = 30;
    private const NAME = '[a-z][a-z0-9_-]{0,39}';

    /**
     * @param string $html a text block's already-cleaned HTML
     * @param array<string, array{render: \Closure, usage_key: string}> $registered App::shortcodes()
     */
    public static function expand(string $html, App $app, array $registered): string
    {
        if ($registered === [] || !str_contains($html, '[')) {
            return $html;
        }

        $count = 0;
        $replace = function (array $match) use ($app, $registered, &$count): string {
            $name = $match[1];
            if (!isset($registered[$name]) || $count >= self::MAX_PER_TEXT) {
                return $match[0];
            }
            $count++;
            $raw = ($match[2] !== '' ? $match[2] . ' ' : '') . ($match[3] ?? '');
            try {
                return (string) ($registered[$name]['render'])($app, self::arguments($raw), $name);
            } catch (Throwable $e) {
                error_log('Shortcode [' . $name . '] failed: ' . $e->getMessage());

                return '';
            }
        };

        // A shortcode alone in its paragraph is not wrapped in a <p> (its output is usually a block).
        $html = (string) preg_replace_callback(
            '#<p>\s*(\[(' . self::NAME . ')(?::([^\]\s]+))?((?:\s[^\]]*)?)\])\s*</p>#',
            fn (array $m) => isset($registered[$m[2]]) ? $replace([$m[1], $m[2], $m[3], $m[4]]) : $m[0],
            $html
        );

        return (string) preg_replace_callback('#\[(' . self::NAME . ')(?::([^\]\s]+))?((?:\s[^\]]*)?)\]#', fn (array $m) => $replace([$m[0], $m[1], $m[2], $m[3] ?? '']), $html);
    }

    /** @return array<int|string, string> */
    public static function arguments(string $raw): array
    {
        $raw = html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5);
        $args = [];
        $position = 0;
        preg_match_all('/([a-z][a-z0-9_-]*)=(?:"([^"]*)"|\'([^\']*)\'|(\S+))|"([^"]*)"|(\S+)/i', $raw, $found, PREG_SET_ORDER);
        foreach ($found as $token) {
            if (($token[1] ?? '') !== '') {
                $args[strtolower($token[1])] = (string) (($token[2] ?? '') !== '' ? $token[2] : (($token[3] ?? '') !== '' ? $token[3] : ($token[4] ?? '')));
            } else {
                $args[$position++] = (string) (($token[5] ?? '') !== '' ? $token[5] : ($token[6] ?? ''));
            }
        }

        return $args;
    }
}
