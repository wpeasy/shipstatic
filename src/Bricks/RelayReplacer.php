<?php
/**
 * Points the static pages' Bricks addresses back at WordPress.
 *
 * @package WPEasy\ShipStatic
 * @since   2.4.9
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Bricks;

use WPEasy\ShipStatic\Render\UrlRewriter;
use WPEasy\ShipStatic\Settings\Destination;
use WPEasy\ShipStatic\Support\Paths;
use WPEasy\ShipStatic\Support\Url;
use WPEasy\ShipStatic\Sync\DeployReplacer;
use WPEasy\ShipStatic\Sync\Manifest;

defined('ABSPATH') || exit;

/**
 * The render turns WordPress's addresses into placeholder paths
 * (`/__shipstatic_base__/wp-admin/admin-ajax.php`,
 * `/__shipstatic_base__/wp-json/bricks/v1/`), like every other link to the
 * site, so after rebase they pointed at the static site — where they don't
 * exist, and Bricks forms and filters silently did nothing. Per destination
 * this:
 *
 * - swaps the admin-ajax placeholder (plain and JSON-escaped) for
 *   WordPress's endpoint;
 * - swaps `bricksData.restApiUrl` for this destination's relay
 *   ({@see Relay::rest_base()});
 * - adds a small script to pages with Bricks filters, which applies the
 *   filters in the page's address (`?brx_…`) — WordPress does that while it
 *   renders, a static copy can't;
 * - when any page has filters or an AJAX loop, uploads the featured images
 *   (every size) of every published post, so the results WordPress sends
 *   back — posts never shown on an exported page — have their images.
 *
 * Only the rewriter inserts the placeholder, so nothing else can match.
 */
final class RelayReplacer implements DeployReplacer {

    /** Text that marks a page whose Bricks loops load more results from WordPress. */
    private const DYNAMIC_MARKERS = ['data-brx-filter', 'loadMore', 'infinite_scroll', 'infiniteScroll'];

    /**
     * {@inheritDoc}
     */
    public function key(): string {
        return 'bricksRelay';
    }

    /**
     * {@inheritDoc}
     */
    public function prepare(Destination $dest, string $dest_base): array {
        if (!Relay::enabled()) {
            return ['ctx' => null, 'files' => []];
        }

        $swaps = [];
        $ajax  = UrlRewriter::rewrite(Relay::endpoint());
        if ($ajax !== Relay::endpoint()) {
            $to                                  = Relay::url_for(Relay::endpoint(), $dest_base);
            $swaps[$ajax]                        = $to;
            $swaps[str_replace('/', '\\/', $ajax)] = str_replace('/', '\\/', $to);
        }

        $rest      = UrlRewriter::rewrite(rest_url('bricks/v1/'));
        $relay     = Relay::rest_base($dest->id());
        $rest_swap = $rest !== rest_url('bricks/v1/') && $relay !== '' ? [$rest, Relay::url_for($relay, $dest_base)] : null;

        $dynamic = self::has_dynamic_loops();

        return [
            'ctx'   => ['swaps' => $swaps, 'rest' => $rest_swap, 'script' => $dynamic],
            'files' => $dynamic ? self::featured_image_files() : [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function apply(string $html, $ctx, string $relative): string {
        if ($ctx['swaps'] !== [] && strpos($html, 'admin-ajax.php') !== false) {
            $html = strtr($html, $ctx['swaps']);
        }

        if ($ctx['rest'] !== null && strpos($html, '"restApiUrl"') !== false) {
            [$from, $to] = $ctx['rest'];
            $html        = (string) preg_replace_callback(
                '/("restApiUrl"\s*:\s*")(' . preg_quote($from, '/') . '|' . preg_quote(str_replace('/', '\\/', $from), '/') . ')(")/',
                static fn(array $m): string => $m[1] . ($m[2] === $from ? $to : str_replace('/', '\\/', $to)) . $m[3],
                $html
            );
        }

        if ($ctx['script'] && strpos($html, 'data-brx-filter') !== false && stripos($html, '</body>') !== false) {
            $script = wp_get_inline_script_tag(self::filters_script(), ['id' => 'shipstatic-bricks-filters']);
            $at     = (int) strripos($html, '</body>');
            $html   = substr($html, 0, $at) . $script . substr($html, $at);
        }

        return $html;
    }

    /**
     * {@inheritDoc}
     */
    public function signature(Destination $dest) {
        if (!Relay::enabled()) {
            return null;
        }

        // Not the featured-image list: this runs on every dashboard status
        // check. A new image is picked up by the next Sync anyway.
        return [Relay::endpoint(), Relay::rest_base($dest->id()), md5(self::filters_script()), self::has_dynamic_loops()];
    }

    /**
     * Whether a page in the current render has Bricks filters or a loop that
     * loads more results (load more, infinite scroll). Scanned once per
     * render (kept in a transient keyed by the render's hashes).
     */
    private static function has_dynamic_loops(): bool {
        static $known = null;
        if ($known !== null) {
            return $known;
        }
        $render = (array) Manifest::load(Manifest::RENDER_OPTION);
        $key    = md5((string) wp_json_encode(array_map(static fn($m) => $m['hash'] ?? '', $render)));
        $cached = get_transient('shipstatic_bricks_dynamic');
        if (is_array($cached) && ($cached['key'] ?? '') === $key) {
            return $known = !empty($cached['dynamic']);
        }

        $known = false;
        foreach ($render as $relative => $meta) {
            $src = (string) ($meta['src'] ?? '');
            if (substr((string) $relative, -5) !== '.html' || !is_file($src)) {
                continue;
            }
            $html = (string) file_get_contents($src);
            foreach (self::DYNAMIC_MARKERS as $marker) {
                if (strpos($html, $marker) !== false) {
                    $known = true;
                    break 2;
                }
            }
        }
        set_transient('shipstatic_bricks_dynamic', ['key' => $key, 'dynamic' => $known], DAY_IN_SECONDS);

        return $known;
    }

    /**
     * Every size of every published post's featured image, as export path =>
     * local file. Read once per request.
     *
     * @return array<string,string>
     */
    private static function featured_image_files(): array {
        static $files = null;
        if ($files !== null) {
            return $files;
        }
        $files = [];

        $ids = get_posts([
            'post_type'        => array_values(get_post_types(['public' => true])),
            'post_status'      => 'publish',
            'has_password'     => false,
            'meta_key'         => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one query per sync, ids only.
            'fields'           => 'ids',
            'numberposts'      => -1, // get_posts() suppresses filters by default.
        ]);
        foreach ($ids as $post_id) {
            $attachment = (int) get_post_thumbnail_id((int) $post_id);
            $url        = $attachment > 0 ? wp_get_attachment_url($attachment) : false;
            if (!is_string($url) || $url === '') {
                continue;
            }
            $urls = [$url];
            $meta = wp_get_attachment_metadata($attachment);
            foreach ((array) ($meta['sizes'] ?? []) as $size) {
                if (!empty($size['file']) && is_string($size['file'])) {
                    $urls[] = trailingslashit(dirname($url)) . rawurlencode($size['file']);
                }
            }
            foreach ($urls as $u) {
                $rel = Url::to_relative_path($u);
                $src = $rel !== null ? Paths::source_file($u) : null;
                if ($rel !== null && $src !== null && !isset($files[$rel])) {
                    $files[$rel] = $src;
                }
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * The script that applies `?brx_…` filters on a static page.
     */
    private static function filters_script(): string {
        return (string) file_get_contents(__DIR__ . '/templates/filters-from-url.js');
    }
}
