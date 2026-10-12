<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Reduces HTML entered in the administration (page texts) to a fixed set
 * of formatting elements. Whatever is not on the list is removed: scripts,
 * styles, frames, forms, event handlers, "javascript:" links. The text is
 * cleaned once, when it is saved, and can then be output as it is.
 *
 * `class` and `style` are the one exception to plain attribute filtering:
 * general CSS would be an injection surface (fixed overlays, UI redressing),
 * so a `span`'s class is rebuilt from SPAN_CLASSES and its style from a
 * single validated `color: #rrggbb` declaration - never the submitted
 * string itself. See cleanSpanClass()/cleanSpanStyle().
 */
final class HtmlSanitizer
{
    /** Element => allowed attributes. */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [],
        'h2' => [], 'h3' => [], 'h4' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'small' => [], 'sub' => [], 'sup' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'blockquote' => [], 'pre' => [], 'code' => [],
        'a' => ['href', 'title'],
        // Only pictures of the media library, see cleanImage().
        'img' => ['src', 'alt', 'width', 'height'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['scope', 'colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        // class/style are not just filtered - cleanSpanClass()/cleanSpanStyle()
        // rebuild them from a fixed allow-list, see the class docblock.
        'span' => ['class', 'style'],
    ];
    /** Removed together with everything inside them. */
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'template', 'svg', 'math', 'noscript', 'head', 'title'];
    /** The only span classes a text-formatting toolbar may produce (font, then size) - see Design::FONTS for the font keys. */
    private const SPAN_CLASSES = ['text-font-system', 'text-font-serif', 'text-font-humanist', 'text-font-mono', 'text-size-sm', 'text-size-md', 'text-size-lg', 'text-size-xl'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The XML declaration is the documented way to make loadHTML read
        // the input as UTF-8.
        $document->loadHTML('<?xml encoding="UTF-8"><div id="sanitizer-root">' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('sanitizer-root');
        if ($root === null) {
            return '';
        }

        self::cleanChildren($root);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return trim($result);
    }

    private static function cleanChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                // Comments, processing instructions, CDATA.
                $node->removeChild($child);
                continue;
            }

            $name = strtolower($child->nodeName);

            if ($name === 'img' && !self::isLibraryImage($child)) {
                $node->removeChild($child);
                continue;
            }

            if (in_array($name, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);
                continue;
            }

            self::cleanChildren($child);

            if (!isset(self::ALLOWED[$name])) {
                // Unknown element: keep what is inside it, drop the element.
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                if (!in_array(strtolower($attribute->nodeName), self::ALLOWED[$name], true)) {
                    $child->removeAttributeNode($attribute);
                }
            }

            if ($name === 'a') {
                self::cleanLink($child);
            }
            if ($name === 'span') {
                self::cleanSpan($node, $child);
            }
        }
    }

    /**
     * Rebuilds a span's class/style from the submitted string - never keeps
     * it as is, that string is exactly the CSS-injection surface this class
     * otherwise refuses by never allowing `style`/`class` at all. A span
     * left with neither, once rebuilt, is unwrapped like an unknown element.
     */
    private static function cleanSpan(DOMNode $parent, DOMElement $span): void
    {
        $classes = array_values(array_intersect(
            preg_split('/\s+/', trim($span->getAttribute('class'))) ?: [],
            self::SPAN_CLASSES
        ));
        if ($classes !== []) {
            $span->setAttribute('class', implode(' ', $classes));
        } else {
            $span->removeAttribute('class');
        }

        $colour = self::spanColour($span->getAttribute('style'));
        if ($colour !== null) {
            $span->setAttribute('style', 'color: ' . $colour);
        } else {
            $span->removeAttribute('style');
        }

        if (!$span->hasAttributes()) {
            while ($span->firstChild !== null) {
                $parent->insertBefore($span->firstChild, $span);
            }
            $parent->removeChild($span);
        }
    }

    /** The one declaration a span's style may carry, read out of the submitted string - or null. */
    private static function spanColour(string $style): ?string
    {
        foreach (explode(';', $style) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            if (strtolower(trim($property)) !== 'color') {
                continue;
            }
            $value = strtolower(trim($value));
            if (preg_match('/^#[0-9a-f]{6}$/', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    /** A picture of the media library: the random name it was given, and nothing else. */
    private static function isLibraryImage(DOMElement $image): bool
    {
        return preg_match('#^/media/library/[a-f0-9]{32}\.(webp|png)$#', $image->getAttribute('src')) === 1;
    }

    private static function cleanLink(DOMElement $link): void
    {
        $href = trim($link->getAttribute('href'));
        // Control characters and whitespace inside a scheme ("java\tscript:")
        // are ignored by browsers, so they are ignored here too.
        $compact = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $href));

        $isSafe = preg_match('#^(https?://|mailto:|tel:|/(?!/)|\#)#', $compact) === 1;
        if (!$isSafe) {
            $link->removeAttribute('href');
            return;
        }

        if (preg_match('#^https?://#', $compact) === 1) {
            $link->setAttribute('rel', 'noopener noreferrer');
        }
    }
}
