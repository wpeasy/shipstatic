<?php
/**
 * Lockdown's WordPress side.
 *
 * @package WPEasy\ShipStatic
 * @since   2.3.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Lockdown;

use WPEasy\ShipStatic\Render\PageRenderer;
use WPEasy\ShipStatic\Support\Environment;

defined('ABSPATH') || exit;

/**
 * What needs WordPress loaded (the gate decides who gets in; this runs only
 * for requests it let through):
 *
 * - the unlock link adds the visitor's address, sets the unlock cookie and
 *   reloads the page without the key (docs/LOCKDOWN_PLAN.md §5);
 * - the dashboard's check request answers 204, so the browser can tell it
 *   got in (§8);
 * - the site's requests to itself carry the render token, so WP-Cron and
 *   Site Health get through (§6).
 */
final class Guard {

    /** Query parameter of the check request. */
    public const CHECK_PARAM = 'shipstatic_lockdown_check';

    /** Response header naming what answered (gate / wordpress / open). */
    public const HEADER = 'X-ShipStatic-Lockdown';

    /**
     * Hook in (from Plugin::init(), on plugins_loaded).
     */
    public static function init(): void {
        // Every loopback carries the render token (PageRenderer::request_args()),
        // so it comes off a redirect to another host even while Lockdown is off:
        // a token that leaked then would still open the site once it's on.
        add_action('requests-requests.before_redirect', [self::class, 'unsign_on_redirect'], 10, 2);

        // Off on multisite: its files are shared by the whole network.
        if (!Settings::enabled() || Environment::is_multisite()) {
            return;
        }

        if (isset($_GET[self::CHECK_PARAM])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- answers 204 and changes nothing.
            self::answer_check();
        }

        add_action('init', [self::class, 'handle_unlock'], 0);
        add_filter('http_request_args', [self::class, 'sign_own_requests'], 10, 2);
        add_action('admin_init', [self::class, 'refresh_files']);
    }

    /**
     * The unlock link: add this address, set the cookie, reload without the key.
     * The key itself is the credential (no nonce: whoever holds the link is
     * meant to get in, and it is checked with hash_equals()).
     */
    public static function handle_unlock(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the unlock key is the credential (see above).
        $key = isset($_GET['unlock']) && is_string($_GET['unlock']) ? sanitize_text_field(wp_unslash($_GET['unlock'])) : '';
        if ($key === '' || !hash_equals(Settings::key(), $key)) {
            return;
        }

        $ip   = self::remote_addr();
        $cidr = $ip !== '' ? Cidr::for_visitor($ip) : null;
        if ($cidr !== null && !Cidr::any(Settings::cidrs(), $ip) && Settings::add_entry($cidr, __('Added with the unlock link', 'shipstatic'), 'key')) {
            Writer::apply();
            self::notify($cidr);
        }

        self::set_cookie();
        nocache_headers();
        wp_safe_redirect(remove_query_arg('unlock'));
        exit;
    }

    /**
     * Set the unlock cookie (12 hours).
     */
    public static function set_cookie(): void {
        if (headers_sent()) {
            return;
        }
        setcookie(Settings::COOKIE, Settings::cookie_value(), [
            'expires'  => time() + Settings::COOKIE_LIFETIME,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Add the render token to the site's requests to itself (WP-Cron's spawn,
     * Site Health's loopback tests, other plugins' loopbacks), so the gate lets
     * them through. Only to this site's own host.
     *
     * @param array<string,mixed> $args HTTP request args.
     * @param string              $url  Request URL.
     * @return array<string,mixed>
     */
    public static function sign_own_requests($args, $url): array {
        $args = (array) $args;
        if (empty($args[Probe::UNSIGNED]) && self::same_host((string) $url)) {
            $args['headers']                              = (array) ($args['headers'] ?? []);
            $args['headers'][PageRenderer::TOKEN_HEADER] = PageRenderer::token();
        }

        return $args;
    }

    /**
     * Never carry the token to another host when a request is redirected
     * (the HTTP library keeps the original headers across redirects).
     *
     * @param string              $location Redirect target (by reference).
     * @param array<string,mixed> $headers  Request headers (by reference).
     */
    public static function unsign_on_redirect(&$location, &$headers): void {
        if (self::same_host((string) $location) || !is_array($headers)) {
            return;
        }
        foreach (array_keys($headers) as $name) {
            if (strcasecmp((string) $name, PageRenderer::TOKEN_HEADER) === 0) {
                unset($headers[$name]);
            }
        }
    }

    /**
     * Rewrite the files once after a plugin update changed their templates.
     */
    public static function refresh_files(): void {
        if (Settings::get()['written'] !== Writer::VERSION && current_user_can('manage_options')) {
            Writer::apply();
        }
    }

    /**
     * The request's own address (REMOTE_ADDR, never a forwarding header: those
     * can be set by anyone), or '' when it isn't one.
     */
    public static function remote_addr(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        return Cidr::parse($ip) !== null ? $ip : '';
    }

    /**
     * An address a proxy or CDN reports for the visitor, when it differs from
     * REMOTE_ADDR — a sign that the address the server sees isn't the
     * visitor's. Only ever shown as a warning; never trusted for access.
     */
    public static function forwarded_addr(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }
            $first = trim(explode(',', sanitize_text_field(wp_unslash((string) $_SERVER[$header])))[0]);
            // Both loopback (a local dev proxy) isn't a different visitor.
            $loopback = Cidr::any(['127.0.0.0/8', '::1/128'], $first) && Cidr::any(['127.0.0.0/8', '::1/128'], self::remote_addr());
            if (Cidr::parse($first) !== null && $first !== self::remote_addr() && !$loopback) {
                return $first;
            }
        }

        return '';
    }

    /**
     * Answer the dashboard's check request: it got in.
     */
    private static function answer_check(): void {
        nocache_headers();
        status_header(204);
        header(self::HEADER . ': open');
        header('X-ShipStatic-Address: ' . self::remote_addr());
        exit;
    }

    /**
     * Tell the site admin an address was added with the unlock link.
     *
     * @param string $cidr Added range.
     */
    private static function notify(string $cidr): void {
        if (!Settings::get()['notify']) {
            return;
        }

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        wp_mail(
            (string) get_option('admin_email'),
            /* translators: %s: site name. */
            sprintf(__('[%s] An address was added to Lockdown', 'shipstatic'), $site),
            sprintf(
                /* translators: 1: IP address or range, 2: date and time, 3: dashboard URL. */
                __("%1\$s was added to the Lockdown list with the unlock link on %2\$s.\n\nIf that wasn’t you or your team, remove it and generate a new unlock link:\n%3\$s", 'shipstatic'),
                Cidr::display($cidr),
                wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format')),
                admin_url('admin.php?page=shipstatic')
            )
        );
    }

    /**
     * Whether a URL is on this site's own host.
     *
     * @param string $url URL.
     */
    private static function same_host(string $url): bool {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

        return $host !== ''
            && $host === strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))
            && self::port($url) === self::port(home_url());
    }

    /**
     * A URL's port, with the scheme's default filled in (another port on the
     * same host can be another service).
     *
     * @param string $url URL.
     */
    private static function port(string $url): int {
        $port = wp_parse_url($url, PHP_URL_PORT);
        if (is_int($port)) {
            return $port;
        }

        return strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443;
    }
}
