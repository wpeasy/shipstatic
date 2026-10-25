<?php
/**
 * Per-destination link (href) swapping in rendered HTML.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Render;

use WPEasy\ShipStatic\Support\Url;

defined('ABSPATH') || exit;

/**
 * Rewrites the `href` of <a> and <button> elements whose value matches a
 * replacement. Tag-scoped (never touches matching text in body content), so only
 * real link targets are swapped. (Iframe embeds are handled by VideoReplacer.)
 */
final class LinkReplacer {

    /**
     * Apply link swaps to HTML.
     *
     * @param string                $html  HTML.
     * @param array<string,string>  $swaps Original href => replacement href (keys decoded).
     * @return string
     */
    public static function apply(string $html, array $swaps): string {
        if (empty($swaps)) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#<(?:a|button)\b[^>]*>#i',
            static function (array $m) use ($swaps): string {
                $tag = $m[0];
                // Decode entities (hrefs can carry &amp; in query strings) so they
                // match the stored, decoded swap key.
                // Keys are plain paths (LinkCollector strips the destination-base
                // placeholder), so compare in the same form.
                $href = Url::strip_base_token(html_entity_decode(TagAttr::get($tag, 'href'), ENT_QUOTES | ENT_HTML5));
                if ($href === '' || !isset($swaps[$href])) {
                    return $tag;
                }

                // A site path ("/contact/") gets the placeholder back, so each
                // destination's sub-path is filled in at deploy like every
                // other link; external and protocol-relative URLs stay as typed.
                $to = (string) $swaps[$href];
                if (str_starts_with($to, '/') && !str_starts_with($to, '//')) {
                    $to = Url::BASE_TOKEN . $to;
                }

                return TagAttr::set($tag, 'href', $to);
            },
            $html
        );
    }
}
