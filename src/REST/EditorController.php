<?php
/**
 * Editor REST controller — per-post "Include in static export" toggle.
 *
 * Backs the "Include" switch shown in the post editor metabox and the FAB sync
 * modal when the discovery mode is `manual`. Admin-only (consistent with the
 * rest of the plugin) plus a per-post `edit_post` ownership check.
 *
 * @package WPEasy\ShipStatic
 * @since   1.0.2
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\REST;

use WP_REST_Request;
use WP_REST_Response;
use WPEasy\ShipStatic\Discovery\UrlCollector;
use WPEasy\ShipStatic\Render\AssetExtractor;
use WPEasy\ShipStatic\Render\PageRenderer;
use WPEasy\ShipStatic\Support\Text;
use WPEasy\ShipStatic\Support\Url;
use WPEasy\ShipStatic\Sync\LinkGraph;
use WPEasy\ShipStatic\Sync\Manifest;
use WPEasy\ShipStatic\Sync\Runner;

defined('ABSPATH') || exit;

/**
 * Reads/writes the `_shipstatic_manual_include` post meta for `manual` discovery mode.
 */
final class EditorController {

    /**
     * REST namespace.
     */
    private const NS = 'shipstatic/v1';

    /**
     * Register routes.
     */
    public static function register(): void {
        register_rest_route(self::NS, '/editor/include', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'set_include'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        // Bulk include (the "Include all" cascade).
        register_rest_route(self::NS, '/editor/include-bulk', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'include_bulk'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        // Link-integrity: pages THIS page links to that aren't included (enable).
        register_rest_route(self::NS, '/editor/links', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'outbound_links'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        // Link-integrity: included pages that link TO this page (disable).
        register_rest_route(self::NS, '/editor/inbound', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'inbound_links'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);
    }

    /**
     * Admin-only, matching the plugin's REST model. The per-post `edit_post`
     * check happens in the handler (the post id is in the body).
     */
    public static function can_manage(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Set whether a post is included in the static export (manual mode).
     *
     * @param WP_REST_Request $request Request with { postId, include }.
     */
    public static function set_include(WP_REST_Request $request): WP_REST_Response {
        $params  = (array) $request->get_json_params();
        $post_id = (int) ($params['postId'] ?? 0);

        if ($post_id <= 0 || get_post($post_id) === null) {
            return new WP_REST_Response(['message' => 'A valid post id is required.'], 400);
        }
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_REST_Response(['message' => 'You cannot edit this post.'], 403);
        }

        $include = !empty($params['include']);

        // Stored as '0' (excluded) or '1' (included).
        update_post_meta($post_id, UrlCollector::INCLUDE_META, $include ? '1' : '0');

        return new WP_REST_Response(self::payload($include));
    }

    /**
     * Include a batch of posts (the "Include all" cascade). Already-included
     * posts are no-ops.
     *
     * @param WP_REST_Request $request Request with { postIds: int[] }.
     */
    public static function include_bulk(WP_REST_Request $request): WP_REST_Response {
        $params = (array) $request->get_json_params();
        $ids    = array_map('intval', (array) ($params['postIds'] ?? []));

        $included = [];
        $skipped  = [];

        foreach ($ids as $pid) {
            if ($pid <= 0 || get_post($pid) === null || !current_user_can('edit_post', $pid)) {
                continue;
            }
            if (UrlCollector::is_included($pid)) {
                continue; // already in
            }
            update_post_meta($pid, UrlCollector::INCLUDE_META, '1');
            $included[] = $pid;
        }

        return new WP_REST_Response(self::payload(true) + ['included' => $included, 'skipped' => $skipped]);
    }

    /**
     * Pages that the given post links to which are NOT currently included — the
     * candidates offered when a page is enabled. Reads cached render HTML when
     * available, otherwise renders once on demand (skipped while a sync runs).
     *
     * @param WP_REST_Request $request Request with { postId }.
     */
    public static function outbound_links(WP_REST_Request $request): WP_REST_Response {
        $params  = (array) $request->get_json_params();
        $post_id = (int) ($params['postId'] ?? 0);

        if ($post_id <= 0 || get_post($post_id) === null) {
            return new WP_REST_Response(['message' => 'A valid post id is required.'], 400);
        }
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_REST_Response(['message' => 'You cannot edit this post.'], 403);
        }

        $url  = (string) get_permalink($post_id);
        $html = $url !== '' ? self::page_html($url) : null;
        if ($html === null) {
            // No cached copy and rendering wasn't possible (e.g. a sync is running).
            return new WP_REST_Response(['pages' => [], 'busy' => true]);
        }

        $public = UrlCollector::public_post_types();
        $seen   = [];
        $pages  = [];
        foreach (AssetExtractor::extract_links($html, $url) as $link) {
            $pid = Url::to_post_id($link);
            if ($pid <= 0 || $pid === $post_id || isset($seen[$pid])) {
                continue;
            }
            $post = get_post($pid);
            if ($post === null || $post->post_status !== 'publish' || !in_array($post->post_type, $public, true)) {
                continue;
            }
            if (UrlCollector::is_effective($pid)) {
                continue; // already exporting
            }
            $seen[$pid] = true;
            $pages[]    = ['postId' => $pid, 'title' => Text::title($pid)];
        }

        return new WP_REST_Response(['pages' => $pages]);
    }

    /**
     * Currently-included pages that link TO the given post — surfaced as a
     * warning when a page is excluded. Sourced from the last render's link graph.
     *
     * @param WP_REST_Request $request Request with ?postId=.
     */
    public static function inbound_links(WP_REST_Request $request): WP_REST_Response {
        $post_id = (int) $request->get_param('postId');

        if ($post_id <= 0 || get_post($post_id) === null) {
            return new WP_REST_Response(['message' => 'A valid post id is required.'], 400);
        }
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_REST_Response(['message' => 'You cannot edit this post.'], 403);
        }

        $rel = Url::to_relative_path((string) get_permalink($post_id));
        if ($rel === null) {
            return new WP_REST_Response(['pages' => []]);
        }

        $pages = [];
        foreach (LinkGraph::inbound_post_ids($rel) as $pid) {
            if ($pid === $post_id || !UrlCollector::is_effective($pid)) {
                continue;
            }
            $post = get_post($pid);
            if ($post === null || $post->post_status !== 'publish') {
                continue;
            }
            $pages[] = ['postId' => $pid, 'title' => Text::title($pid)];
        }

        return new WP_REST_Response(['pages' => $pages]);
    }

    /**
     * The page's HTML — the cached render copy if present, else a one-off
     * on-demand render. Returns null if it can't be obtained (e.g. a sync is
     * running, so an on-demand loopback would contend for workers).
     *
     * @param string $url Page permalink.
     */
    private static function page_html(string $url): ?string {
        $rel      = Url::to_relative_path($url);
        $manifest = Manifest::load(Manifest::RENDER_OPTION);
        if ($rel !== null && isset($manifest[$rel]['src']) && is_file((string) $manifest[$rel]['src'])) {
            $html = file_get_contents((string) $manifest[$rel]['src']);
            if (is_string($html)) {
                return $html;
            }
        }

        $phase = Runner::status()['phase'] ?? 'idle';
        if (!in_array($phase, ['idle', 'done', 'error', 'cancelled'], true)) {
            return null; // a sync is in progress — don't add a competing loopback
        }

        try {
            $result = PageRenderer::render($url);
            if ($result['code'] === 200 && stripos((string) $result['contentType'], 'html') !== false) {
                return (string) $result['body'];
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * Standard response: the post's include state plus the current include count
     * so the UI can keep its counts accurate without a reload.
     *
     * @param bool $included Whether the post is now included.
     * @return array<string,mixed>
     */
    private static function payload(bool $included): array {
        return [
            'included'      => $included,
            'includedCount' => UrlCollector::effective_count(),
            'savedCount'    => UrlCollector::included_count(),
        ];
    }
}
