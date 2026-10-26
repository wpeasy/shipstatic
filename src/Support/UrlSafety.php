<?php
/**
 * SSRF guard for server-side fetches of user-supplied URLs.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Support;

use WP_Error;

defined('ABSPATH') || exit;

/**
 * Validates that a URL is safe to fetch server-side, and performs the fetch with
 * redirects disabled.
 *
 * SSRF risk is asymmetric: a remote browser can't reach `127.0.0.1` or the cloud
 * metadata endpoint `169.254.169.254`, but the WordPress server can — so any URL
 * that originates from a request (here: a destination's public URL) is routed
 * through `is_safe_url()` before a server-side fetch. The host is resolved to its
 * IP(s) and rejected if any land in a private/reserved/link-local range. Loopback
 * is intentionally allowed so local-dev destinations (e.g. *.local mapped to
 * 127.0.0.1) keep working.
 *
 * `is_safe_url()` alone is not enough — the fetch does an independent DNS lookup
 * (DNS-rebinding TOCTOU) and WordPress follows redirects by default. `guarded_post()`
 * therefore also forces `redirection => 0` and rejects 3xx, so a public URL can't
 * 302 to an internal address. (IP-pinning via a one-shot http_api_curl filter is a
 * further hardening TODO; redirects-off + range check is the baseline.)
 */
final class UrlSafety {

    /**
     * Whether a URL is safe to fetch (public http/https, not a private/reserved IP).
     *
     * @param string $url URL to check.
     */
    public static function is_safe_url(string $url): bool {
        $parts = wp_parse_url(trim($url));
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if ($host === '') {
            return false;
        }

        $ips = self::resolve_ips($host);
        if ($ips === []) {
            return false; // Unresolvable — fail closed.
        }

        foreach ($ips as $ip) {
            if (self::is_loopback($ip)) {
                continue; // Allowed for local development.
            }
            if (!self::is_global_ip($ip)) {
                return false; // Private / reserved / link-local (incl. cloud metadata).
            }
        }

        return true;
    }

    /**
     * POST to a user-supplied URL with SSRF protection: validate, disable redirect
     * following, and reject a 3xx (which would otherwise bounce to an internal host).
     *
     * @param string              $url  URL to POST to.
     * @param array<string,mixed> $args wp_remote_post args (redirection is forced to 0).
     * @return array<mixed>|WP_Error The response, or a WP_Error on block / redirect / transport failure.
     */
    public static function guarded_post(string $url, array $args = []) {
        $args['method'] = 'POST';

        return self::guarded_request($url, $args);
    }

    /**
     * GET (or HEAD, via `$args['method']`) a user-supplied URL with the same SSRF
     * protection as `guarded_post()`. Used by the destination server probe.
     *
     * @param string              $url  URL to fetch.
     * @param array<string,mixed> $args wp_remote_request args (redirection is forced to 0).
     * @return array<mixed>|WP_Error The response, or a WP_Error on block / redirect / transport failure.
     */
    public static function guarded_get(string $url, array $args = []) {
        if (!isset($args['method'])) {
            $args['method'] = 'GET';
        }

        return self::guarded_request($url, $args);
    }

    /**
     * Shared guarded fetch: validate, force `redirection => 0`, reject 3xx.
     *
     * @param string              $url  URL to fetch.
     * @param array<string,mixed> $args wp_remote_request args (`method` set by the caller).
     * @return array<mixed>|WP_Error
     */
    private static function guarded_request(string $url, array $args) {
        if (!self::is_safe_url($url)) {
            return new WP_Error(
                'shipstatic_unsafe_url',
                __('That URL resolves to a private or reserved network address (cloud metadata, LAN, loopback proxy, etc.) and cannot be used. Use a public HTTP(S) URL.', 'shipstatic')
            );
        }

        $args['redirection'] = 0; // Do NOT follow redirects (defeats the IP check).
        if (!isset($args['reject_unsafe_urls'])) {
            // WordPress's own check refuses every loopback address and any port
            // but 80/443/8080, which would undo the local-dev allowance above
            // (Herd/Valet *.test, Local *.local, localhost:8088). Skip it only for
            // a dev host that resolves to loopback alone; is_safe_url() has
            // already refused every private, reserved and link-local range.
            $args['reject_unsafe_urls'] = !self::is_local_dev_url($url);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 300 && $code < 400) {
            return new WP_Error(
                'shipstatic_redirect',
                __('The destination URL returned a redirect, which is not followed for safety.', 'shipstatic')
            );
        }

        return $response;
    }

    /**
     * Whether a host is a local/development host (cannot hold a public CA cert), used
     * to scope TLS-verification relaxation. Exact matches only — NOT a prefix test,
     * so `127.0.0.1.attacker.com` is treated as the public host it is.
     *
     * @param string $host Hostname (no scheme).
     */
    public static function is_dev_host(string $host): bool {
        $host = strtolower(trim($host, '[]'));
        if ($host === '') {
            return false;
        }
        if ($host === 'localhost') {
            return true;
        }
        // .local / .test are reserved special-use TLDs — never publicly certifiable.
        if (preg_match('/\.(local|test)$/', $host)) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::is_loopback($host);
        }

        return false;
    }

    /**
     * Whether a URL points at a development host on this machine: is_dev_host()
     * and every address it resolves to is loopback (a *.test name pointing at a
     * LAN machine is not).
     *
     * @param string $url URL.
     */
    private static function is_local_dev_url(string $url): bool {
        $host = trim((string) wp_parse_url($url, PHP_URL_HOST), '[]');
        if ($host === '' || !self::is_dev_host($host)) {
            return false;
        }
        $ips = self::resolve_ips($host);

        return $ips !== [] && count(array_filter($ips, [self::class, 'is_loopback'])) === count($ips);
    }

    /**
     * Resolve a host to its IP addresses (the literal IP if it is one).
     *
     * @param string $host Hostname or IP literal.
     * @return array<int,string>
     */
    private static function resolve_ips(string $host): array {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $v4  = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Whether an IP is loopback (127.0.0.0/8 or ::1).
     *
     * @param string $ip IP address.
     */
    private static function is_loopback(string $ip): bool {
        if ($ip === '::1') {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && strpos($ip, '127.') === 0;
    }

    /**
     * Whether an IP is a globally-routable (public) address — rejects private,
     * reserved, link-local (incl. 169.254.169.254), ULA, and unspecified ranges.
     *
     * @param string $ip IP address.
     */
    private static function is_global_ip(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            // Link-local (fe80::/10), ULA (fc00::/7), unspecified, IPv4-mapped/compat.
            if (preg_match('/^fe[89ab]/', $lower)
                || preg_match('/^f[cd]/', $lower)
                || $lower === '::'
                || strpos($lower, '::ffff:') === 0
                || strpos($lower, '::127.') !== false
            ) {
                return false;
            }

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return false;
    }
}
