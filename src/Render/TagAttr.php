<?php
/**
 * Read and write one attribute of an HTML tag string.
 *
 * @package WPEasy\ShipStatic
 * @since   2.3.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Render;

defined('ABSPATH') || exit;

/**
 * Shared by the deploy replacers (Media, Links, Videos), which rewrite single
 * attributes of matched tags.
 *
 * - The name must start the attribute: `src` never matches `data-src`, and
 *   `srcset` never matches `data-srcset`.
 * - New values are inserted with a callback, never as a preg_replace()
 *   replacement string: esc_attr() leaves `$` and `\` alone, so a value
 *   containing `$0` would otherwise expand to the matched text (quotes and
 *   all) and break out of the attribute (security review, stored XSS via a
 *   video URL).
 */
final class TagAttr {

    /**
     * An attribute's raw value (as in the HTML), or '' when absent.
     *
     * @param string $tag  Opening tag.
     * @param string $name Attribute name.
     */
    public static function get(string $tag, string $name): string {
        if (preg_match(self::pattern($name, true), $tag, $m)) {
            return $m[2] !== '' ? $m[2] : ($m[3] ?? '');
        }

        return '';
    }

    /**
     * Set an attribute (replace it, or add it before the closing `>`), as a
     * double-quoted, esc_attr()'d value.
     *
     * @param string $tag   Opening tag.
     * @param string $name  Attribute name.
     * @param string $value New value (unescaped).
     */
    public static function set(string $tag, string $name, string $value): string {
        $attr = $name . '="' . esc_attr($value) . '"';

        if (preg_match(self::pattern($name, false), $tag)) {
            return (string) preg_replace_callback(self::pattern($name, false), static fn(): string => $attr, $tag, 1);
        }

        return (string) preg_replace_callback('#\s*/?>$#', static fn(array $m): string => ' ' . $attr . $m[0], $tag, 1);
    }

    /**
     * Remove an attribute.
     *
     * @param string $tag  Opening tag.
     * @param string $name Attribute name.
     */
    public static function remove(string $tag, string $name): string {
        return (string) preg_replace('#\s+' . substr(self::pattern($name, false), 1), '', $tag);
    }

    /**
     * The attribute pattern (with value groups 2/3 when $groups).
     *
     * @param string $name   Attribute name.
     * @param bool   $groups Capture the quoted value.
     */
    private static function pattern(string $name, bool $groups): string {
        $value = $groups ? '("([^"]*)"|\'([^\']*)\')' : '("[^"]*"|\'[^\']*\')';

        return '#(?<![\w-])' . preg_quote($name, '#') . '\s*=\s*' . $value . '#i';
    }
}
