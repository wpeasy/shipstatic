<?php
/**
 * Checks which Lockdown layer actually stops a stranger.
 *
 * @package WPEasy\ShipStatic
 * @since   2.3.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Lockdown;

use WPEasy\ShipStatic\Render\PageRenderer;

defined('ABSPATH') || exit;

/**
 * The server asks itself for a page without the render token, the way a
 * stranger would. Whatever answers says which layer is working
 * (docs/LOCKDOWN_PLAN.md §8): `.htaccess` blocks without our header, the
 * gate says `gate` when PHP prepended it and `wordpress` when only the
 * mu-plugin ran it.
 */
final class Probe {

    /** Request arg that keeps Guard::sign_own_requests() from adding the token. */
    public const UNSIGNED = 'shipstatic_unsigned';

    /**
     * Run the probe.
     *
     * @return array{result:string,address:string,message:string}
     *         result: server | gate | wordpress | allowed | open | error.
     */
    public static function run(): array {
        $args = PageRenderer::request_args();
        unset($args['headers'][PageRenderer::TOKEN_HEADER]);
        $args['redirection']  = 0;
        $args['timeout']      = 15;
        $args[self::UNSIGNED] = true;

        $response = wp_remote_get(add_query_arg(Guard::CHECK_PARAM, wp_generate_password(8, false, false), home_url('/')), $args);
        if (is_wp_error($response)) {
            return ['result' => 'error', 'address' => '', 'message' => $response->get_error_message()];
        }

        $code    = (int) wp_remote_retrieve_response_code($response);
        $by      = strtolower((string) wp_remote_retrieve_header($response, Guard::HEADER));
        $address = (string) wp_remote_retrieve_header($response, 'X-ShipStatic-Address');

        if ($code === 204 && $by === 'open') {
            // Nothing stopped it. Fine when the server's own address is allowed
            // (we just can't tell from here); otherwise no layer is working.
            $allowed = $address !== '' && Cidr::any(Settings::cidrs(), $address);
            return ['result' => $allowed ? 'allowed' : 'open', 'address' => $address, 'message' => ''];
        }
        if (in_array($code, [302, 403], true)) {
            $result = $by === 'gate' ? 'gate' : ($by === 'wordpress' ? 'wordpress' : 'server');
            return ['result' => $result, 'address' => '', 'message' => ''];
        }

        /* translators: %d: HTTP status code. */
        return ['result' => 'error', 'address' => '', 'message' => sprintf(__('Unexpected answer (HTTP %d).', 'shipstatic'), $code)];
    }
}
