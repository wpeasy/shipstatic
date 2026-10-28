<?php
/**
 * Filesystem paths for the staging cache.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Support;

defined('ABSPATH') || exit;

/**
 * Resolves and prepares the local staging cache directory.
 *
 * Rendered HTML, gzip siblings, mirrored assets, and the current-render manifest
 * live here. It is regenerable and must never hold durable state (settings, the
 * pushed manifest) — those live in the database so a third-party cache purge of
 * wp-content/cache cannot cause drift.
 */
final class Paths {

    /**
     * Memoised resolved cache directory (no trailing slash).
     *
     * @var string|null
     */
    private static ?string $resolved = null;

    /**
     * Absolute path to the staging cache directory (no trailing slash).
     */
    public static function cache_dir(): string {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        self::$resolved = untrailingslashit(wp_normalize_path(self::resolve()));

        return self::$resolved;
    }

    /**
     * Absolute path to the rendered output directory (no trailing slash).
     *
     * Kept in a `site/` subdirectory so the actual deliverable is isolated from
     * the staging guards (index.html / .htaccess) in the parent, and so M3 can
     * upload the whole subtree without filtering meta files.
     */
    public static function output_dir(): string {
        return self::cache_dir() . '/site';
    }

    /**
     * Absolute path to the Export ZIP staging directory (no trailing slash).
     *
     * A subdirectory of the cache dir, so it inherits the same `.htaccess`
     * "deny all" guard written by {@see write_guards()} — built zips are only
     * ever served through the gated REST download endpoint, never directly.
     */
    public static function export_dir(): string {
        return self::cache_dir() . '/exports';
    }

    /**
     * Create the cache directory, output subdir, and access guards if needed.
     *
     * @return bool True if the directories exist and are writable.
     */
    public static function ensure(): bool {
        $dir = self::cache_dir();

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        self::write_guards($dir);

        $output = self::output_dir();
        if (!is_dir($output) && !wp_mkdir_p($output)) {
            return false;
        }

        return wp_is_writable($output);
    }

    /**
     * Whether the cache directory is (or can be made) writable.
     */
    public static function is_ready(): bool {
        $dir = self::cache_dir();

        return is_dir($dir) ? wp_is_writable($dir) : wp_is_writable(dirname($dir));
    }

    /**
     * Resolve an asset URL to its local source file on disk, if one exists.
     *
     * Lets the sync upload binary assets straight from the source filesystem
     * instead of duplicating them into the cache. Returns null for URLs that
     * don't map to an existing static file (dynamic endpoints, off-tree paths).
     *
     * @param string $url Absolute or root-relative asset URL.
     * @return string|null Absolute local file path, or null.
     */
    public static function source_file(string $url): ?string {
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $path = rawurldecode($path);

        // Page content decides which URLs get here, so only plain static files
        // are ever read from disk (SECURITY_PATTERNS.md §10): no traversal or
        // dot files, a static-asset extension, and a real path that stays
        // inside the mapped directory (symlinks included).
        if (Url::is_unsafe_path($path) || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::STATIC_EXTENSIONS, true)) {
            return null;
        }

        // Never the plugin's own cache folder: its renders, packages, exports
        // and manifest are not site assets (the folder denies web access, but a
        // file read from disk would bypass that).
        $cache = realpath(self::cache_dir());
        $cache = $cache === false ? '' : trailingslashit(wp_normalize_path($cache));

        foreach (self::url_dir_map() as [$url_prefix, $dir]) {
            if ($url_prefix === '' || strpos($path, $url_prefix) !== 0) {
                continue;
            }

            $relative = ltrim(substr($path, strlen($url_prefix)), '/');
            $file     = realpath(trailingslashit($dir) . $relative);
            $base     = realpath($dir);
            if ($file === false || $base === false || !is_file($file)) {
                continue;
            }

            $file = wp_normalize_path($file);
            if ($cache !== '' && strpos($file, $cache) === 0) {
                return null;
            }
            if (strpos($file, trailingslashit(wp_normalize_path($base))) === 0) {
                return $file;
            }
        }

        return null;
    }

    /**
     * File types read straight from disk into the export. Anything else is
     * fetched over HTTP, so it is published exactly as the web server serves it.
     */
    private const STATIC_EXTENSIONS = [
        'css', 'js', 'mjs', 'map', 'json', 'xml', 'txt', 'webmanifest', 'html', 'htm',
        'ico', 'svg', 'svgz', 'png', 'jpg', 'jpeg', 'jpe', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'heic',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp4', 'm4v', 'webm', 'ogv', 'mov', 'mp3', 'm4a', 'ogg', 'oga', 'wav', 'flac',
        'pdf', 'csv', 'vtt', 'srt',
    ];

    /**
     * URL-path prefix → filesystem directory map, longest prefix first.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private static function url_dir_map(): array {
        $uploads = wp_get_upload_dir();

        $map = [
            [self::url_path($uploads['baseurl']), $uploads['basedir']],
            [self::url_path(content_url('/')), WP_CONTENT_DIR],
            [self::url_path(includes_url('/')), ABSPATH . WPINC],
            [self::url_path(home_url('/')), untrailingslashit(ABSPATH)],
        ];

        $map = array_values(array_filter($map, static fn(array $row): bool => $row[0] !== ''));
        usort($map, static fn(array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));

        return $map;
    }

    /**
     * Path component of a URL (always with a leading slash, no trailing-only).
     *
     * @param string $url URL.
     */
    private static function url_path(string $url): string {
        $path = wp_parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /**
     * Resolve the cache directory: constant → filter → wp-content/cache, with a
     * fallback to uploads/ when wp-content is not writable.
     */
    private static function resolve(): string {
        if (defined('SHIPSTATIC_CACHE_DIR') && SHIPSTATIC_CACHE_DIR) {
            /** This filter is documented below. */
            return (string) apply_filters('shipstatic_cache_dir', (string) SHIPSTATIC_CACHE_DIR);
        }

        $dir = trailingslashit(WP_CONTENT_DIR) . 'cache/shipstatic';

        $content_ok = wp_is_writable(WP_CONTENT_DIR)
            || is_dir(trailingslashit(WP_CONTENT_DIR) . 'cache');

        if (!$content_ok) {
            $uploads = wp_upload_dir();
            if (empty($uploads['error'])) {
                $dir = trailingslashit($uploads['basedir']) . 'shipstatic';
            }
        }

        // One folder per site on a network: every site renders its own pages.
        if (Environment::is_multisite()) {
            $dir .= '/site-' . get_current_blog_id();
        }

        /**
         * Filters the absolute path to the staging cache directory.
         *
         * @param string $dir Resolved cache directory.
         */
        return (string) apply_filters('shipstatic_cache_dir', $dir);
    }

    /**
     * Drop in guards so the staging dir is not served or directory-listed.
     *
     * @param string $dir Cache directory.
     */
    private static function write_guards(string $dir): void {
        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            file_put_contents($index, '');
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
    }
}
