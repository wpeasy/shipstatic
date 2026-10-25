<?php
/**
 * Media REST controller — the per-page media replacer UI.
 *
 * @package WPEasy\ShipStatic
 * @since   1.1.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\REST;

use WP_REST_Request;
use WP_REST_Response;
use WPEasy\ShipStatic\Media\MediaCollector;
use WPEasy\ShipStatic\Discovery\UrlCollector;
use WPEasy\ShipStatic\Sync\Manifest;

defined('ABSPATH') || exit;

/**
 * Serves the exported page list and the images on a chosen page for the media
 * replacer UI.
 */
final class MediaController {

    /**
     * REST namespace.
     */
    private const NS = 'shipstatic/v1';

    /**
     * Register routes.
     */
    public static function register(): void {
        register_rest_route(self::NS, '/media', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'list_media'],
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
     * GET /media — the exported pages (for the selector) and, when a `page` is
     * given, the images on that page. Media replacement is per page, so no images
     * are returned until a page is selected.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function list_media(WP_REST_Request $request): WP_REST_Response {
        $page = (string) $request->get_param('page');

        return new WP_REST_Response([
            'pages' => MediaCollector::pages(),
            // Published pages missing from the export, shown greyed out in the page list.
            'excluded' => UrlCollector::published_excluded(array_keys(Manifest::load(Manifest::RENDER_OPTION))),
            'page'  => $page,
            'media' => $page !== '' ? MediaCollector::collect($page) : [],
        ]);
    }
}
