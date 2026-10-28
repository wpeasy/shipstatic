<?php
/**
 * Public relay for Bricks query filters and AJAX loops on the static site.
 *
 * @package WPEasy\ShipStatic
 * @since   2.4.9
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPEasy\ShipStatic\Bricks\Relay;
use WPEasy\ShipStatic\Settings\Destination;
use WPEasy\ShipStatic\Sync\Runner;

defined('ABSPATH') || exit;

/**
 * `POST /shipstatic/v1/bricks/<destination id>/<endpoint>` — the address the
 * static pages' `bricksData.restApiUrl` points at (Bricks appends the
 * endpoint). It hands the request, unchanged, to Bricks' own public route
 * `/bricks/v1/<endpoint>` — Bricks' permission check (its nonce) and
 * validation run exactly as for a visitor of the WordPress site — and then
 * rewrites the HTML and CSS in the reply for that destination, so its links
 * and images point at the static site. The second public route
 * (SECURITY_PATTERNS.md §0); keep its invariants:
 *
 * - only the three endpoints in Relay::ENDPOINTS, only POST;
 * - 404 unless the relay is on and the destination exists;
 * - nothing of its own is read or written: what Bricks returns is what a
 *   visitor of the WordPress site would get, with addresses changed.
 */
final class BricksRelayController {

    private const NS = 'shipstatic/v1';

    /** Reply keys holding stylesheet text, not HTML. */
    private const CSS_KEYS = ['styles'];

    /** Reply keys whose values are query data, never rewritten. */
    private const DATA_KEYS = ['updated_query'];

    /**
     * Register the route.
     */
    public static function register(): void {
        register_rest_route(self::NS, '/' . Relay::ROUTE . '/(?P<dest>' . Relay::DEST_ID . ')/(?P<endpoint>' . implode('|', Relay::ENDPOINTS) . ')', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'relay'],
            'permission_callback' => [self::class, 'is_public'],
        ]);
    }

    /**
     * Public: Bricks' own route checks its nonce (see the class comment).
     */
    public static function is_public(): bool {
        return true;
    }

    /**
     * Hand the request to Bricks and rewrite its reply.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function relay(WP_REST_Request $request) {
        $dest     = Relay::destination((string) $request['dest']);
        $endpoint = (string) $request['endpoint'];
        if ($dest === null || !in_array($endpoint, Relay::ENDPOINTS, true)) {
            return new WP_Error('shipstatic_no_relay', __('Not available.', 'shipstatic'), ['status' => 404]);
        }

        $inner = new WP_REST_Request('POST', '/bricks/v1/' . $endpoint);
        $inner->set_header('content-type', 'application/json');
        $inner->set_body($request->get_body());
        $lang = $request->get_param('lang');
        if (is_string($lang) && $lang !== '') {
            $inner->set_query_params(['lang' => sanitize_key($lang)]);
        }

        $response = rest_do_request($inner);
        if ($response->is_error() || $response->get_status() >= 400) {
            return $response; // Bricks' own error (an expired nonce: Bricks then asks for a new one).
        }

        $response->set_data(self::rewrite($response->get_data(), $dest, ''));
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Rewrite every HTML/CSS string in the reply for the destination.
     *
     * @param mixed       $value Reply value.
     * @param Destination $dest  Destination.
     * @param string      $key   This value's key in its parent.
     * @return mixed
     */
    private static function rewrite($value, Destination $dest, string $key) {
        if (in_array($key, self::DATA_KEYS, true)) {
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::rewrite($v, $dest, is_string($k) ? $k : $key);
            }
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (in_array($key, self::CSS_KEYS, true)) {
            return Runner::transform_for_destination($value, $dest, 'css');
        }

        // Only markup: plain strings (ids, counts) are left as they are.
        return strpos($value, '<') !== false ? Runner::transform_for_destination($value, $dest, 'html') : $value;
    }
}
