<?php
/**
 * URL helpers: origins, internal detection, absolutising, and URL→file mapping.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Support;


defined('ABSPATH') || exit;

/**
 * Pure URL utilities shared by discovery, rendering, and rewriting.
 */
final class Url {

    /**
     * The site's home sub-path — the path component of home_url(), normalised to
     * a leading-slash prefix with no trailing slash. Empty string when the site is
     * installed at the domain root.
     *
     * On this platform the dev host mounts each site under /<name>/, so home_url()
     * carries that prefix; it must be stripped from the static output (which is
     * served from the destination's own root, or its configured sub-path).
     */
    public static function home_base(): string {
        $path = (string) (wp_parse_url((string) home_url(), PHP_URL_PATH) ?? '');
        $path = '/' . trim($path, '/');

        return $path === '/' ? '' : $path;
    }

    /**
     * Placeholder the render puts where a link's site address was. The render
     * is built ONCE for every destination, so it can't contain any one
     * destination's sub-path; each destination swaps this for its own at
     * deploy (see rebase()): "/__shipstatic_base__/about/" becomes "/about/" on
     * a site served from the domain root and "/repo/about/" on one served from
     * /repo/. Only ever inserted by Render\UrlRewriter, exactly where the
     * source origin (or home sub-path) was — so the swap never touches
     * anything else.
     */
    public const BASE_TOKEN = '/__shipstatic_base__';

    /**
     * Extensions a web server may run as code. A static export never contains
     * them, so a URL ending in one is never mapped to an export file
     * (SECURITY_PATTERNS.md §10).
     */
    private const SERVER_SCRIPT_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht', 'phpt',
        'cgi', 'fcgi', 'pl', 'py', 'rb', 'sh', 'bash',
        'asp', 'aspx', 'ashx', 'asmx', 'axd', 'cshtml', 'vbhtml', 'jsp', 'jspx', 'cfm', 'cfc',
        'shtml', 'shtm', 'stm', 'ini', 'htaccess', 'htpasswd', 'env', 'log', 'sql', 'bak',
    ];

    /**
     * A path as plain segments joined by "/": no leading or trailing slash, and
     * every empty, "." and ".." segment (and NUL byte) dropped, backslashes read
     * as slashes. ".." does NOT climb, it is simply removed, so the result can
     * never leave the folder it is joined under. For a destination's sub-path,
     * which becomes a LOCAL folder name in the deploy copy as well as a remote one.
     *
     * @param string $path Any path ("/a/../b/", "a\\b", "").
     */
    public static function clean_path(string $path): string {
        $path = str_replace(['\\', "\0"], ['/', ''], $path);
        $keep = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment !== '' && $segment !== '.' && $segment !== '..') {
                $keep[] = $segment;
            }
        }

        return implode('/', $keep);
    }

    /**
     * A URL with its path run through clean_path() — only when the path actually
     * climbs or hides ({@see has_traversal()}), so every ordinary URL comes back
     * byte for byte. Anything else about the URL is kept.
     *
     * @param string $url An absolute URL (already esc_url_raw()'d).
     */
    public static function without_dot_segments(string $url): string {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !isset($parts['path']) || !self::has_traversal(rawurldecode((string) $parts['path']))) {
            return $url;
        }

        $path = self::clean_path(rawurldecode((string) $parts['path']));
        $out  = isset($parts['scheme']) ? $parts['scheme'] . '://' : '//';
        if (isset($parts['user'])) {
            $out .= $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }
        $out .= (string) ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $out .= $path === '' ? '' : '/' . $path . '/';
        $out .= isset($parts['query']) ? '?' . $parts['query'] : '';

        return $out . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    /**
     * Whether a (decoded) path climbs or hides: a "." or ".." segment, a NUL
     * byte or a backslash. Such a path must never become a local or remote
     * file path — page content controls the URLs the crawler follows.
     *
     * @param string $path URL path or relative file path, already decoded.
     */
    public static function has_traversal(string $path): bool {
        if (strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            return true;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a crawled URL path must not be exported: traversal, a hidden
     * (dot) file or folder other than /.well-known/, or a server-side script
     * or secret file type (x.php, x.php.txt, .env, debug.log…).
     *
     * @param string $path Decoded URL path.
     */
    public static function is_unsafe_path(string $path): bool {
        if (self::has_traversal($path)) {
            return true;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment !== '' && $segment[0] === '.' && $segment !== '.well-known') {
                return true;
            }
        }
        $parts = explode('.', strtolower(basename($path)));
        array_shift($parts); // the name before the first dot
        foreach ($parts as $ext) {
            if (in_array($ext, self::SERVER_SCRIPT_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise a served-from path to "" (domain root) or "/sub" (leading
     * slash, no trailing slash) — the form rebase() and file relocation use.
     *
     * @param string $path e.g. "/", "/repo/", "repo".
     */
    public static function normalize_base(string $path): string {
        $path = trim($path, '/');

        return $path === '' ? '' : '/' . $path;
    }

    /**
     * Put a destination's sub-path into rendered content: every BASE_TOKEN
     * (plain or JSON-escaped) becomes $base.
     *
     * @param string $content HTML, CSS, XML…
     * @param string $base    Normalised base ("" or "/sub").
     */
    public static function rebase(string $content, string $base): string {
        if (strpos($content, '__shipstatic_base__') === false) {
            return $content;
        }

        // Escaped form first: the plain token also matches inside it, which
        // left a stray backslash ("\\/x") for a root destination.
        return str_replace(
            [str_replace('/', '\\/', self::BASE_TOKEN), self::BASE_TOKEN],
            [str_replace('/', '\\/', $base), $base],
            $content
        );
    }

    /**
     * A rendered link path without the placeholder, as a person would read it
     * ("/__shipstatic_base__/about/" → "/about/"). Used for display and for
     * keys that must not depend on any destination's sub-path (link swaps).
     *
     * @param string $path A leading-slash URL path.
     */
    public static function strip_base_token(string $path): string {
        if ($path !== self::BASE_TOKEN && strpos($path, self::BASE_TOKEN . '/') !== 0) {
            return $path;
        }
        $rest = substr($path, strlen(self::BASE_TOKEN));

        return $rest === '' ? '/' : $rest;
    }

    /**
     * Map a rendered link path back to where it lives on the source site, so
     * code reading the cached render can find attachments and load thumbnails
     * ("/__shipstatic_base__/wp-content/x.jpg" → "/wp-content/x.jpg", or
     * "/home-sub/wp-content/x.jpg" when WordPress itself has a home sub-path).
     *
     * @param string $path A leading-slash URL path (no scheme/host).
     */
    public static function to_source_path(string $path): string {
        if ($path !== self::BASE_TOKEN && strpos($path, self::BASE_TOKEN . '/') !== 0) {
            return $path;
        }
        $rest = self::strip_base_token($path);

        return self::home_base() === '' ? $rest : self::home_base() . ($rest === '/' ? '/' : $rest);
    }

    /**
     * Map a source site path to its path in the render: strip the home
     * sub-path ("/test-for-vid/about/" → "/about/"). The render is the same
     * for every destination, so no destination sub-path is added here —
     * Sync\Runner relocates files into each destination's sub-path at deploy.
     * A path outside the home sub-path is returned unchanged.
     *
     * @param string $path A leading-slash URL path (no scheme/host).
     */
    public static function remap_site_path(string $path): string {
        $home = self::home_base();
        if ($home !== '' && ($path === $home || strpos($path, $home . '/') === 0)) {
            $path = substr($path, strlen($home));
        }

        return '/' . ltrim($path, '/');
    }

    /**
     * Whether a URL's path is inside WordPress's address. With WordPress on a
     * sub-path (`/wp`), the rest of the host is not WordPress — after "This
     * server" publishes to the domain root it is the static site itself — and
     * `/` there would map to the same index.html as WordPress's home, so the
     * crawler rendered the published (holding) page over the real home.
     *
     * @param string $url Internal URL or root-relative path.
     */
    public static function in_home(string $url): bool {
        $home = self::home_base();
        if ($home === '') {
            return true;
        }

        $path = rawurldecode((string) (wp_parse_url($url, PHP_URL_PATH) ?? ''));

        return $path === $home || strpos($path, $home . '/') === 0;
    }

    /**
     * Scheme+host origin prefixes for this site (http/https, www/non-www,
     * protocol-relative), used to detect and strip the source origin.
     *
     * @return array<int,string>
     */
    public static function origins(): array {
        $out = [];

        foreach (array_unique([home_url(), site_url()]) as $base) {
            $host = wp_parse_url($base, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                continue;
            }

            $hosts = [$host];
            $hosts[] = strpos($host, 'www.') === 0 ? substr($host, 4) : 'www.' . $host;

            foreach (array_unique($hosts) as $candidate) {
                $out[] = 'https://' . $candidate;
                $out[] = 'http://' . $candidate;
                $out[] = '//' . $candidate;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Whether a URL points at this site (or is a site-relative path).
     *
     * @param string $url URL or path.
     */
    public static function is_internal(string $url): bool {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        if (strpos($url, '#') === 0) {
            return false;
        }

        if (strpos($url, '//') === 0) {
            $parts = wp_parse_url('https:' . $url);
        } elseif (preg_match('#^https?://#i', $url)) {
            $parts = wp_parse_url($url);
        } elseif (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            // Any other scheme (mailto:, tel:, sms:, data:, javascript:, ftp://,
            // whatsapp://, a malformed "https:/x") is never a page of this site.
            return false;
        } else {
            return true; // Relative or root-relative path → internal.
        }

        if (!is_array($parts) || !isset($parts['host']) || isset($parts['user'])) {
            return false;
        }

        // Another port is another site (http and https on their default ports
        // are the same one — the scheme isn't compared).
        $self_port = wp_parse_url(home_url(), PHP_URL_PORT);
        if (($parts['port'] ?? null) !== $self_port) {
            return false;
        }

        $self = wp_parse_url(home_url(), PHP_URL_HOST);

        return self::normalize_host((string) $parts['host']) === self::normalize_host((string) $self);
    }

    /**
     * Resolve a (possibly relative) URL against a base URL into an absolute URL.
     * Fragments are dropped. Returns '' if it cannot be resolved.
     *
     * @param string $url  URL or path found in markup.
     * @param string $base Absolute URL of the document it was found in.
     */
    public static function absolutize(string $url, string $base): string {
        $url = trim($url);
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (strpos($url, '//') === 0) {
            return (wp_parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $url;
        }

        $parts = wp_parse_url($base);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $root = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (strpos($url, '/') === 0) {
            return $root . $url;
        }

        // Relative to the base directory.
        $base_path = isset($parts['path']) ? $parts['path'] : '/';
        $dir = (substr($base_path, -1) === '/') ? $base_path : dirname($base_path) . '/';

        return $root . self::collapse_dots($dir . $url);
    }

    /**
     * Map a URL (or path) to a relative file path under the cache root.
     *
     * Directory-style URLs (trailing slash or no extension) become index.html;
     * file-style URLs keep their path. Returns null if the URL carries a query
     * string for a page (cannot be made static).
     *
     * A page URL's query string is part of its identity — `/?page_id=9` and
     * `/?p=1` are different pages that share the path `/`. Mapping those on path
     * alone would resolve every one of them to the same `index.html`, so each
     * would overwrite the last (the whole export collapsing to one file under
     * plain permalinks; a `/?s=term` link clobbering the home page under pretty
     * ones). They are unmappable, so callers skip them. A query on a real *file*
     * is still ignored — `style.css?ver=1` is just cache-busting.
     *
     * @param string $url URL or path.
     * @return string|null Relative file path, or null if unmappable.
     */
    public static function to_relative_path(string $url): ?string {
        $path  = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '');
        $query = wp_parse_url($url, PHP_URL_QUERY);
        $path  = rawurldecode($path);
        $path  = self::remap_site_path($path);

        // Never a path that climbs out of the export or names a script/secret.
        if (self::is_unsafe_path($path)) {
            return null;
        }

        $has_query = is_string($query) && $query !== '';

        if ($path === '' || $path === '/') {
            return $has_query ? null : 'index.html';
        }

        $path     = ltrim($path, '/');
        $basename = basename($path);

        // Directory style → index.html (unless a query makes it non-static).
        if (substr($path, -1) === '/' || strpos($basename, '.') === false) {
            return $has_query ? null : rtrim($path, '/') . '/index.html';
        }

        // File style (has an extension) — the query is cache-busting, ignore it.
        return $path;
    }

    /**
     * Resolve an internal URL to its WordPress post id.
     *
     * Wraps `url_to_postid()`, but also resolves the static front page and the
     * "Posts page" — both serve archives, so `url_to_postid()` returns 0 for them
     * even though each is a real, toggleable Page. Returns 0 when no post matches.
     *
     * @param string $url Internal URL.
     */
    public static function to_post_id(string $url): int {
        $id = (int) url_to_postid($url);
        if ($id > 0) {
            return $id;
        }

        $rel = self::to_relative_path($url);
        if ($rel === null) {
            return 0;
        }

        foreach (['page_on_front', 'page_for_posts'] as $option) {
            $pid = (int) get_option($option);
            if ($pid > 0 && self::to_relative_path((string) get_permalink($pid)) === $rel) {
                return $pid;
            }
        }

        return 0;
    }

    /**
     * Lower-case a host with any leading "www." removed.
     *
     * @param string $host Hostname.
     */
    private static function normalize_host(string $host): string {
        return preg_replace('#^www\.#i', '', strtolower($host)) ?? strtolower($host);
    }

    /**
     * Collapse ./ and ../ segments in a path.
     *
     * @param string $path Path possibly containing dot segments.
     */
    private static function collapse_dots(string $path): string {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }
}
