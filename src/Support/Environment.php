<?php
/**
 * Hosting-environment detection.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Support;

use WPEasy\ShipStatic\System\WpCli;

defined('ABSPATH') || exit;

/**
 * Detects environments where browser-driven loopback rendering is unreliable —
 * notably Local (which serializes PHP requests on Windows), so the dashboard
 * can steer the user to the WP-CLI command instead.
 */
final class Environment {

    /**
     * Whether this looks like a Local by Flywheel (or similar) dev environment.
     */
    public static function is_local(): bool {
        $local = false;

        if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local') {
            $local = true;
        }

        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        if (preg_match('/\.local$/i', $host)) {
            $local = true;
        }

        if (strpos(wp_normalize_path((string) ABSPATH), '/Local Sites/') !== false) {
            $local = true;
        }

        $software = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))) : '';
        if (strpos($software, 'flywheel') !== false) {
            $local = true;
        }

        /**
         * Filters whether the environment is treated as a local dev host.
         *
         * @param bool $local Detected value.
         */
        return (bool) apply_filters('shipstatic_is_local', $local);
    }

    /**
     * Whether WordPress is using a "pretty" permalink structure.
     *
     * With plain permalinks (Settings → Permalinks → Plain) every page URL is a
     * query string on the site root — `/?page_id=9`, `/?p=1` — so the whole site
     * maps to a single path, `/`. Nothing can be exported: {@see Url::to_relative_path()}
     * has only the path to work with, so every page would resolve to `index.html`
     * and overwrite the home page. The dashboard warns on this; the crawler skips
     * the unmappable URLs rather than colliding on them.
     */
    public static function pretty_permalinks(): bool {
        return (string) get_option('permalink_structure') !== '';
    }

    /**
     * Whether this is a multisite network. Features that write server files
     * or folders the whole network shares — Lockdown (.htaccess, .user.ini,
     * the gate), "This server" and Move WordPress into /wp — are off there:
     * a sub-site administrator holds manage_options, so they would control
     * files for every site (SECURITY_PATTERNS.md §0).
     */
    public static function is_multisite(): bool {
        return function_exists('is_multisite') && is_multisite();
    }

    /**
     * The notice shown wherever a feature is off on multisite.
     */
    public static function multisite_notice(): string {
        return __('Disabled on Multisite', 'shipstatic');
    }

    /**
     * A REST error for a feature that is off on multisite (403).
     */
    public static function multisite_error(): \WP_Error {
        return new \WP_Error('shipstatic_multisite', self::multisite_notice(), ['status' => 403]);
    }

    /**
     * The WP-CLI command users should run for a reliable sync.
     */
    public static function cli_command(): string {
        return 'wp shipstatic sync';
    }

    /**
     * WP-CLI availability for the dashboard, via full cross-environment
     * detection (system PATH or Local's bundled runtime).
     *
     * @return array{detected:bool,version:string,runtime:string}
     */
    public static function wp_cli(): array {
        $status = WpCli::status();

        return [
            'detected' => $status['available'] === true,
            'version'  => (string) ($status['version'] ?? ''),
            'runtime'  => is_array($status['runtime'] ?? null) ? (string) ($status['runtime']['type'] ?? '') : '',
        ];
    }
}

