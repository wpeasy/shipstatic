<?php
/**
 * What the last render shows about a site's Bricks forms and filters, and where
 * its destinations are — the facts the Bricks guide uses to pre-answer its
 * questions.
 *
 * @package WPEasy\ShipStatic
 * @since   2.5.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Bricks;

use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Sync\Manifest;

defined('ABSPATH') || exit;

/**
 * Read from the rendered pages themselves (nothing is fetched): a real Bricks
 * form tag, a Bricks filter element, or a loop that loads more. Kept in a
 * transient keyed by the render's hashes, like RelayReplacer's own scan.
 */
final class Usage {

    /** Option: '1' once the Bricks guide has been run or dismissed. */
    public const SEEN_OPTION = 'shipstatic_bricks_guide_seen';

    /**
     * What the last render contains.
     *
     * @return array{rendered:bool,formPages:int,filterPages:int}
     */
    public static function found(): array {
        $render = (array) Manifest::load(Manifest::RENDER_OPTION);
        if (!$render) {
            return ['rendered' => false, 'formPages' => 0, 'filterPages' => 0];
        }

        $key    = md5((string) wp_json_encode(array_map(static fn($m) => $m['hash'] ?? '', $render)));
        $cached = get_transient('shipstatic_bricks_usage');
        if (is_array($cached) && ($cached['key'] ?? '') === $key) {
            return $cached['found'];
        }

        $forms   = 0;
        $filters = 0;
        foreach ($render as $relative => $meta) {
            $src = (string) ($meta['src'] ?? '');
            if (substr((string) $relative, -5) !== '.html' || !is_file($src)) {
                continue;
            }
            $html = (string) file_get_contents($src);
            // A real form tag: "brxe-form" alone also appears in the stylesheet.
            if (preg_match('/<form\b[^>]*\bclass="[^"]*\bbrxe-form\b/i', $html) === 1) {
                $forms++;
            }
            if (strpos($html, 'data-brx-filter') !== false || strpos($html, 'brx-infinite-scroll') !== false || strpos($html, 'brx-ajax-pagination') !== false) {
                $filters++;
            }
        }

        $found = ['rendered' => true, 'formPages' => $forms, 'filterPages' => $filters];
        set_transient('shipstatic_bricks_usage', ['key' => $key, 'found' => $found], DAY_IN_SECONDS);

        return $found;
    }

    /**
     * How each enabled destination is reached, for the guide's advice: a
     * destination on another domain, or one with no PHP (Cloudflare, GitHub
     * Pages), can't lean on this WordPress for its filters.
     *
     * @return array<int,array{transport:string,crossDomain:bool}>
     */
    public static function destinations(): array {
        $home = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $out  = [];
        foreach (Destinations::enabled_ids() as $id) {
            $dest = Destinations::get($id);
            if ($dest === null) {
                continue;
            }
            $host  = (string) wp_parse_url((string) $dest->get('destinationUrl'), PHP_URL_HOST);
            $out[] = ['transport' => (string) $dest->get('transport'), 'crossDomain' => $host !== '' && strcasecmp($host, $home) !== 0];
        }

        return $out;
    }

    /**
     * The block of the status payload.
     *
     * @return array<string,mixed>
     */
    public static function status(): array {
        return [
            'seen'         => get_option(self::SEEN_OPTION, '0') === '1',
            'found'        => self::found(),
            'destinations' => self::destinations(),
            'woo'          => class_exists('WooCommerce'),
        ];
    }
}
