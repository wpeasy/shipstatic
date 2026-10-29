<?php
/**
 * Public site-search index for the live WordPress site.
 *
 * @package WPEasy\ShipStatic
 * @since   2.3.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPEasy\ShipStatic\Search\LiveIndex;

defined('ABSPATH') || exit;

/**
 * GET /shipstatic/v1/search-index — the ONE public route (SECURITY_PATTERNS.md
 * §0): read-only, and returns only the text of published pages already in the
 * export, which any visitor can read on the pages themselves. 404 unless
 * "Enable JavaScript site search" is on and a render exists.
 */
final class SearchIndexController {

    private const NS = 'shipstatic/v1';

    /**
     * Register the route.
     */
    public static function register(): void {
        register_rest_route(self::NS, '/search-index', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get'],
            'permission_callback' => [self::class, 'is_public'],
        ]);
    }

    /**
     * Public on purpose: the visitor's browser fetches it (see the class doc).
     */
    public static function is_public(): bool {
        return true;
    }

    /**
     * GET /search-index.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function get(WP_REST_Request $request) {
        $payload = LiveIndex::enabled() ? LiveIndex::payload() : null;
        if ($payload === null) {
            return new WP_Error('shipstatic_no_search_index', __('Site search is not available.', 'shipstatic'), ['status' => 404]);
        }

        // The script fetches with cache: 'no-cache', so an unchanged index costs a 304.
        $etag = '"' . md5((string) wp_json_encode($payload)) . '"';
        if (trim((string) $request->get_header('if_none_match')) === $etag) {
            $response = new WP_REST_Response(null, 304);
        } else {
            $response = new WP_REST_Response($payload);
        }
        $response->header('ETag', $etag);
        $response->header('Cache-Control', 'public, max-age=300');

        return $response;
    }
}
