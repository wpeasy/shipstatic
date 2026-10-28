<?php
/**
 * Rewrites absolute source-origin URLs to root-relative.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Render;

use WPEasy\ShipStatic\Support\Url;

defined('ABSPATH') || exit;

/**
 * Replaces every absolute reference to the source origin with a root-relative
 * path, so the output resolves correctly when served from the destination
 * regardless of its domain.
 *
 * Works on whole documents (HTML or CSS) so it also catches origins embedded in
 * inline scripts, JSON-LD, inline styles, and data-attributes — not just
 * href/src. The match handles JSON-escaped slashes (https:\/\/host) and
 * protocol-relative (//host) forms, leaving the path (escaped or not) intact.
 */
final class UrlRewriter {

    /**
     * Rewrite all source-origin URLs in a document to root-relative.
     *
     * @param string $content HTML or CSS.
     * @return string Rewritten content.
     */
    public static function rewrite(string $content): string {
        // The render serves every destination, so links get a placeholder
        // for "this destination's sub-path" (Url::BASE_TOKEN), swapped per
        // destination at deploy (Url::rebase()). With WordPress at the domain
        // root it goes where the origin was: "https://host/about" →
        // "/__shipstatic_base__/about"; with a home sub-path, apply_base_path()
        // puts it in place of that sub-path instead.
        $prefix  = Url::home_base() === '' ? Url::BASE_TOKEN : '';
        $escaped = str_replace('/', '\/', $prefix);

        // Replacing the origin with '' (or the placeholder) leaves the following
        // path (escaped or not): "https://host/about" → "/about", "https:\/\/host\/x" → "\/x".
        // A bare origin ("https://host", "https://host?x", "https://host#top" —
        // Bricks writes the logo link that way) gets a "/" too: the placeholder
        // alone becomes "" for a root destination, and an empty href is the
        // current page (the logo on /about/ pointed at /about/).
        $content = self::replace_origins(
            $content,
            static function (bool $json, bool $bare, bool $has_path) use ($prefix, $escaped): string {
                if ($json) {
                    return $has_path ? $escaped : $escaped . '\/';
                }
                return $has_path ? $prefix : $prefix . '/';
            }
        );

        // Now swap the source home sub-path for the placeholder, so e.g.
        // "/test-for-vid/about/" becomes "/__shipstatic_base__/about/".
        return self::apply_base_path($content);
    }

    /**
     * Swap the source home sub-path (home_url()'s path, e.g. "/test-for-vid")
     * for the destination-base placeholder (Url::BASE_TOKEN) in
     * already-root-relative document URLs.
     *
     * Each occurrence must be preceded by a URL-context delimiter (quote, paren,
     * "=", comma or whitespace), so only path-initial matches are touched — an
     * external URL such as https://other.com/test-for-vid/x is left intact because
     * its sub-path is preceded by the host, not a delimiter. Handles both plain
     * ("/x") and JSON-escaped ("\/x") slash forms found in inline scripts/JSON-LD.
     *
     * @param string $content HTML or CSS with root-relative URLs.
     */
    private static function apply_base_path(string $content): string {
        $home = Url::home_base();
        if ($home === '') {
            return $content; // Site installed at domain root — nothing to strip.
        }

        $base = Url::BASE_TOKEN;
        // A path-initial URL is either at the very start of the string (\A — this
        // method also runs on standalone URLs like wp_get_attachment_url(), not just
        // documents) or preceded by a URL-context delimiter. ";" covers HTML-entity
        // quotes (e.g. url(&quot;/path…) in data-style attributes).
        $pre  = '(?:\A|(?<=[\'"(=,;\s]))';

        // [ separator, escaped home sub-path, escaped base path ] for plain and
        // JSON-escaped forms.
        $forms = [
            ['/',   $home,                          $base],
            ['\\/', str_replace('/', '\\/', $home), str_replace('/', '\\/', $base)],
        ];

        foreach ($forms as [$sep, $hb, $bp]) {
            $rep_sep = preg_quote($sep, '#');
            $rep     = $bp === '' ? $sep : $bp . $sep;

            // "/test-for-vid/…" → base + "/…" (the trailing slash is a safe boundary).
            $content = (string) preg_replace(
                '#' . $pre . preg_quote($hb, '#') . $rep_sep . '#',
                $rep,
                $content
            );

            // Bare home link ("/test-for-vid" with no further path) → base + "/".
            // Negative lookahead keeps a sibling like "/test-for-vid-2" untouched.
            $content = (string) preg_replace(
                '#' . $pre . preg_quote($hb, '#') . '(?![\w' . $rep_sep . '-])#',
                $rep,
                $content
            );
        }

        return $content;
    }

    /**
     * Rewrite source-origin URLs to ABSOLUTE destination URLs.
     *
     * Used for files that must carry absolute links — sitemaps and robots.txt —
     * where the root-relative form rewrite() produces is invalid (a `<loc>` and a
     * robots `Sitemap:` line must be a full URL). Replaces each source origin with
     * the destination's origin, preserving the path and the //, http(s) and
     * JSON-escaped forms.
     *
     * @param string $content   Document text (XML or plain text).
     * @param string $dest_base Destination base URL (e.g. "https://example.com").
     * @param string $base      Path the destination serves the site from ("" or "/sub").
     * @return string Rewritten content (unchanged if $dest_base has no host).
     */
    public static function to_absolute(string $content, string $dest_base, string $base = ''): string {
        $parts = wp_parse_url($dest_base);
        if (empty($parts['host'])) {
            return $content;
        }

        $scheme    = $parts['scheme'] ?? 'https';
        $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $origin    = $scheme . '://' . $authority;
        $base      = Url::normalize_base($base);

        // Sitemap/robots entries are SOURCE URLs (home_url + render path), so
        // this destination's sub-path goes after its origin — directly for a
        // root install, in place of the home sub-path otherwise (below).
        $prefix  = Url::home_base() === '' ? $base : '';
        $content = self::replace_origins(
            $content,
            static function (bool $json, bool $bare) use ($origin, $authority, $prefix): string {
                $to = ($bare ? '//' . $authority : $origin) . $prefix;
                return $json ? str_replace('/', '\/', $to) : $to;
            }
        );

        // Swap the source home sub-path for this destination's sub-path
        // ("/test-for-vid/about/" → "/about/" or "/repo/about/"). Anchored to the
        // destination origin, so nothing else is affected.
        $home = Url::home_base();
        if ($home === '') {
            return $content;
        }

        foreach ([$origin, '//' . $authority] as $anchor) {
            $content = str_replace($anchor . $home . '/', $anchor . $base . '/', $content);
            // A callback, not a replacement string: the base comes from the
            // Destination URL, and "$0" or "\1" in it would expand (§12).
            $with    = $anchor . $base . '/';
            $content = (string) preg_replace_callback(
                '#' . preg_quote($anchor . $home, '#') . '(?![\w/-])#',
                static fn(): string => $with,
                $content
            );
        }

        return $content;
    }

    /**
     * Replace each reference to the source origin — `https://host`, `http://host`,
     * `//host`, and their JSON-escaped (`\/\/`) forms, for every source host —
     * with what `$to(json, bare)` returns, leaving the path after it intact.
     *
     * Only whole references to THIS site are touched (tester report: external
     * URLs were being mangled):
     * - the host must end there: not `host.evil.com`, `hostname`, `host:8443`
     *   (another port is another site) or `host@evil.com`;
     * - nothing may run into it from the left (`xhttps://`, `ftp://host`);
     * - it must start its own URL, not sit inside another one — e.g. the
     *   `?u=https://host/page/` of a share link, a Wayback Machine URL, or an
     *   embed's `origin=` — which is when the text since the last delimiter
     *   (quote, whitespace, bracket, comma, semicolon) already holds a `//`.
     *
     * @param string                    $content Document text.
     * @param callable(bool,bool,bool):string $to Replacement for (JSON-escaped?, protocol-relative?, a path follows?).
     */
    private static function replace_origins(string $content, callable $to): string {
        $hosts = implode('|', array_map(static fn(string $h): string => preg_quote($h, '#'), self::source_hosts()));
        if ($hosts === '') {
            return $content;
        }

        // (?<!…) nothing runs into it from the left; (\\\\?/)\2 is "//" or "\/\/";
        // (?!…) the host ends there.
        $pattern = '#(?<![\w.@%+:/\\\\-])(https?:)?(\\\\?/)\2(?:' . $hosts . ')(?![\w.:@-])#i';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $m) use ($content, $to): string {
                [$match, $offset] = $m[0];
                if (self::inside_another_url($content, (int) $offset)) {
                    return $match;
                }

                $next = substr($content, (int) $offset + strlen($match), 2);
                $path = $next !== '' && ($next[0] === '/' || $next === '\\/');

                return $to($m[2][0] === '\\/', $m[1][0] === '', $path);
            },
            $content,
            -1,
            $count,
            PREG_OFFSET_CAPTURE
        );
    }

    /**
     * Whether the URL starting at $offset is part of a longer URL: the text back
     * to the last delimiter already contains "//" (plain or JSON-escaped).
     *
     * @param string $content Document text.
     * @param int    $offset  Byte offset of the match.
     */
    private static function inside_another_url(string $content, int $offset): bool {
        $start  = max(0, $offset - 2048);
        $before = substr($content, $start, $offset - $start);
        if (preg_match('#[\s"\'()<>,;`\[\]{}]([^\s"\'()<>,;`\[\]{}]*)$#', $before, $tail)) {
            $before = $tail[1];
        }

        return strpos($before, '//') !== false || strpos($before, '\\/\\/') !== false;
    }

    /**
     * Source hostnames (with www/non-www variants), longest first.
     *
     * @return array<int,string>
     */
    public static function source_hosts(): array {
        $hosts = [];

        foreach (array_unique([home_url(), site_url()]) as $base) {
            $host = wp_parse_url($base, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                continue;
            }
            $hosts[$host] = true;
            $hosts[strpos($host, 'www.') === 0 ? substr($host, 4) : 'www.' . $host] = true;
        }

        $list = array_keys($hosts);
        usort($list, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $list;
    }
}
