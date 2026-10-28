<?php
/**
 * Plain-text helpers.
 *
 * @package WPEasy\ShipStatic
 * @since   2.4.10
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Support;

defined('ABSPATH') || exit;

/**
 * Text for the dashboard, REST and WP-CLI, which show it as text.
 */
final class Text {

    /**
     * A post's title as plain text. get_the_title() is HTML: texturize turns
     * "&" into "&#038;" and quotes into entities, which a text node shows
     * literally ("Facets &#038; Forms").
     *
     * @param int|\WP_Post $post Post or id.
     */
    public static function title($post): string {
        return html_entity_decode(wp_strip_all_tags((string) get_the_title($post)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
