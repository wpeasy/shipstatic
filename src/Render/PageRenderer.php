<?php
/**
 * Loopback page/asset renderer.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Render;

use WPEasy\ShipStatic\Support\Url;
use WPEasy\ShipStatic\Support\UrlSafety;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text for JSON responses (rendered as text by the dashboard) and WP-CLI, never echoed into HTML; esc_html() here would show entities to the user.

/**
 * Fetches the final rendered output of a URL via an internal HTTP request.
 *
 * Builder-agnostic: it captures whatever the site outputs (Bricks, Gutenberg,
 * etc.). SSL verification is relaxed for local/dev hosts (self-signed certs) so
 * loopback requests to *.local / *.test / localhost succeed; override via the
 * `shipstatic_render_sslverify` and `shipstatic_render_request_args` filters.
 */
final class PageRenderer {

    /**
     * User-agent prefix sent on every loopback request, so the site can tell a
     * static-render request from a real visitor (see is_render_request()).
     */
    public const USER_AGENT_PREFIX = 'ShipStatic/';

    /**
     * Header carrying the per-site shared secret on loopback render requests.
     * Reaches PHP as `$_SERVER['HTTP_X_BS_RENDER_TOKEN']`.
     */
    public const TOKEN_HEADER = 'X-BS-Render-Token';

    /**
     * Response header naming what a render request rendered ("post:<id>",
     * "term:<id>", "home", "archive" or "none"), sent only on trusted render
     * requests ({@see Plugin::announce_render_object()}). Lets the live-site
     * search index check each page's CURRENT status without resolving URLs in
     * a visitor's request, where private posts can't be resolved.
     */
    public const OBJECT_HEADER = 'X-ShipStatic-Object';

    /**
     * Option holding that shared secret (not autoloaded).
     */
    private const TOKEN_OPTION = 'shipstatic_render_token';

    /**
     * Whether the CURRENT request is one of our loopback render requests.
     * Lets render-time hooks (e.g. stripping the emoji loader) apply only to the
     * static output, never to the live front end.
     *
     * User-agent only, so it is trivially spoofable — fine for cosmetic tweaks to
     * the captured output, NOT for anything that changes what a visitor may see.
     * Use {@see is_trusted_render_request()} for those.
     */
    public static function is_render_request(): bool {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        return strpos($ua, self::USER_AGENT_PREFIX) === 0;
    }

    /**
     * Whether the CURRENT request is a render request that proves it came from
     * this site, by presenting the shared secret. Required before letting a
     * request past a gate the site owner put up (maintenance / coming-soon mode),
     * so a passer-by can't read a hidden site by copying our user agent.
     */
    public static function is_trusted_render_request(): bool {
        if (!self::is_render_request()) {
            return false;
        }

        $sent = isset($_SERVER['HTTP_X_BS_RENDER_TOKEN'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_BS_RENDER_TOKEN']))
            : '';

        // Read only — never mint a token on an inbound request.
        $token = (string) get_option(self::TOKEN_OPTION, '');

        return $sent !== '' && $token !== '' && hash_equals($token, $sent);
    }

    /**
     * The site's render token, generated on first use.
     */
    public static function token(): string {
        $token = (string) get_option(self::TOKEN_OPTION, '');

        if ($token === '') {
            $token = wp_generate_password(40, false, false);
            update_option(self::TOKEN_OPTION, $token, false);
        }

        return $token;
    }

    /**
     * Fetch a page URL and return its rendered HTML.
     *
     * `location` is where a redirect that wasn't followed points (absolute;
     * another site, or '' when there was none).
     *
     * @param string $url Absolute URL.
     * @return array{code:int,body:string,contentType:string,object:string,location:string}
     * @throws \RuntimeException On transport error.
     */
    public static function render(string $url): array {
        self::release_session();
        $response = self::get($url, self::request_args($url), $location);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        return [
            'code'        => (int) wp_remote_retrieve_response_code($response),
            'body'        => (string) wp_remote_retrieve_body($response),
            'contentType' => (string) wp_remote_retrieve_header($response, 'content-type'),
            'object'      => (string) wp_remote_retrieve_header($response, self::OBJECT_HEADER),
            'location'    => $location,
        ];
    }

    /**
     * Fetch a binary asset URL.
     *
     * @param string $url Absolute URL.
     * @return array{code:int,body:string,contentType:string,location:string}
     * @throws \RuntimeException On transport error.
     */
    public static function fetch_asset(string $url): array {
        self::release_session();
        $args = self::request_args($url);
        /**
         * Filters the per-asset fetch timeout (seconds). Raise it on slow hosts
         * that serve large/generated assets; keep it below the sync watchdog
         * window ({@see shipstatic_stale_seconds}) so an in-flight fetch isn't reaped.
         *
         * @param int    $timeout Seconds.
         * @param string $url     Asset URL.
         */
        $args['timeout'] = max(5, (int) apply_filters('shipstatic_asset_timeout', 60, $url));

        $response = self::get($url, $args, $location);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        return [
            'code'        => (int) wp_remote_retrieve_response_code($response),
            'body'        => (string) wp_remote_retrieve_body($response),
            'contentType' => (string) wp_remote_retrieve_header($response, 'content-type'),
            'location'    => $location,
        ];
    }

    /**
     * GET a URL of this site, following redirects only while they stay on this
     * site. A redirect to another site (a redirect plugin, a "/go/" link) ends
     * there: its 3xx is returned, so the page is skipped instead of the other
     * site's HTML being saved as ours — and the render token never leaves.
     *
     * @param string              $url      Absolute URL.
     * @param array<string,mixed> $args     Request args (`redirection` = how many to follow).
     * @param string|null         $location Set to where an unfollowed redirect points (absolute), or ''.
     * @return array<string,mixed>|\WP_Error
     */
    private static function get(string $url, array $args, ?string &$location = null) {
        $hops                = max(0, (int) ($args['redirection'] ?? 5));
        $args['redirection'] = 0;
        $location            = '';

        $response = wp_remote_get($url, $args);
        while (!is_wp_error($response)) {
            $code   = (int) wp_remote_retrieve_response_code($response);
            $header = (string) wp_remote_retrieve_header($response, 'location');
            if ($code < 300 || $code > 399 || $header === '') {
                break;
            }
            $next = Url::absolutize($header, $url);
            if ($next === '' || !Url::is_internal($next) || $hops-- <= 0) {
                $location = $next;
                break;
            }
            $url      = $next;
            $response = wp_remote_get($url, $args);
        }

        return $response;
    }

    /**
     * Build the HTTP request args used for loopback requests.
     *
     * @param string $url The URL being requested (for filters).
     * @return array<string,mixed>
     */
    public static function request_args(string $url = ''): array {
        $args = [
            /**
             * Filters the per-page loopback render timeout (seconds). Raise it for
             * heavy pages; keep it below the sync watchdog window
             * ({@see shipstatic_stale_seconds}) so an in-flight render isn't reaped.
             *
             * @param int    $timeout Seconds.
             * @param string $url     Page URL.
             */
            'timeout'     => max(5, (int) apply_filters('shipstatic_render_timeout', 60, $url)),
            'redirection' => 5,
            'sslverify'   => self::should_verify_ssl(),
            'user-agent'  => self::USER_AGENT_PREFIX . SHIPSTATIC_VERSION,
            // Proves the request originated here, so it may pass gates the site
            // owner put up for visitors (see is_trusted_render_request()).
            'headers'     => [self::TOKEN_HEADER => self::token()],
        ];

        /**
         * Filters the loopback HTTP request args (auth headers, timeout, etc.).
         *
         * @param array<string,mixed> $args Request args.
         * @param string              $url  Requested URL.
         */
        return (array) apply_filters('shipstatic_render_request_args', $args, $url);
    }

    /**
     * Release the PHP session lock before a loopback request, so the rendered
     * page (a request to the same site) isn't blocked waiting for our session.
     */
    private static function release_session(): void {
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
    }

    /**
     * Whether to verify TLS for loopback requests. Relaxed for local/dev hosts.
     */
    private static function should_verify_ssl(): bool {
        $is_local    = in_array(wp_get_environment_type(), ['local', 'development'], true);
        // Exact dev-host match (not a prefix), so a public host that merely starts
        // with "127.0.0.1" isn't exempted from TLS verification.
        $is_dev_host = UrlSafety::is_dev_host((string) wp_parse_url(home_url(), PHP_URL_HOST));

        $verify = !($is_local || $is_dev_host);

        /**
         * Filters whether loopback requests verify TLS certificates.
         *
         * @param bool $verify Whether to verify.
         */
        return (bool) apply_filters('shipstatic_render_sslverify', $verify);
    }
}
