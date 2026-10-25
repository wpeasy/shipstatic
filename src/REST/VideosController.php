<?php
/**
 * Videos (iframe embeds) REST controller.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\REST;

use WP_REST_Request;
use WP_REST_Response;
use WPEasy\ShipStatic\Media\VideoCollector;
use WPEasy\ShipStatic\Discovery\UrlCollector;
use WPEasy\ShipStatic\Sync\Manifest;

defined('ABSPATH') || exit;

/**
 * Serves the iframe embeds discovered in the last render, for the video replacer UI.
 */
final class VideosController {

    /**
     * REST namespace.
     */
    private const NS = 'shipstatic/v1';

    /**
     * Register routes.
     */
    public static function register(): void {
        register_rest_route(self::NS, '/videos', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'list_videos'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);
    }

    /**
     * Capability gate.
     */
    public static function can_manage(): bool {
        return current_user_can('manage_options');
    }

    /**
     * GET /videos — the exported pages (for the selector) and, when a `page` is
     * given, the videos on that page. Video replacement is per page, so no videos
     * are returned until a page is selected.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function list_videos(WP_REST_Request $request): WP_REST_Response {
        $page = (string) $request->get_param('page');

        return new WP_REST_Response([
            'pages'  => VideoCollector::pages(),
            // Published pages missing from the export, shown greyed out in the page list.
            'excluded' => UrlCollector::published_excluded(array_keys(Manifest::load(Manifest::RENDER_OPTION))),
            'page'   => $page,
            'videos' => $page !== '' ? VideoCollector::collect($page) : [],
        ]);
    }
}
