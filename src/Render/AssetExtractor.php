<?php
/**
 * Extracts internal links and same-origin assets from rendered output.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Render;

use WPEasy\ShipStatic\Support\Url;

defined('ABSPATH') || exit;

/**
 * Regex-based discovery of links (for crawling) and assets (to mirror).
 *
 * Covers href/src/srcset, <link href>, and CSS url()/@import (inline and in CSS
 * files). JS-constructed URLs are not statically detectable — that gap is
 * reported, not silently hidden.
 */
final class AssetExtractor {

    /**
     * Internal page links from an HTML document (absolute URLs, deduped).
     *
     * @param string $html HTML.
     * @param string $base Absolute URL of the document.
     * @return array<int,string>
     */
    public static function extract_links(string $html, string $base): array {
        $raw = self::match($html, '#<a\b[^>]*?\shref\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i');

        return self::resolve($raw, $base);
    }

    /**
     * Same-origin asset URLs referenced by an HTML document (absolute, deduped).
     *
     * @param string $html HTML.
     * @param string $base Absolute URL of the document.
     * @return array<int,string>
     */
    public static function extract_assets(string $html, string $base): array {
        $raw = array_merge(
            // src, data-src, data-lazy-src, lazy-src (covers <img>/<script>/<source>/<iframe>).
            self::match($html, '#\s(?:data-(?:lazy-)?|lazy-)?src\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i'),
            // Common single-URL lazy attributes and video posters.
            self::match($html, '#\s(?:data-original|data-lazy|data-poster|poster)\s*=\s*("[^"]*"|\'[^\']*\')#i'),
            self::link_assets($html),
            // srcset, data-srcset, data-lazy-srcset, lazy-srcset, data-original-set.
            self::srcset($html, '#\s(?:data-(?:lazy-)?|lazy-)?srcset\s*=\s*("[^"]*"|\'[^\']*\')#i'),
            self::srcset($html, '#\sdata-original-set\s*=\s*("[^"]*"|\'[^\']*\')#i'),
            self::background_refs($html),
            self::css_refs($html)
        );

        return self::resolve($raw, $base);
    }

    /**
     * href values of <link> tags whose rel marks them as assets (stylesheets,
     * icons, preloads, manifest). Excludes canonical/alternate/shortlink/etc.,
     * which point at pages or dynamic endpoints — not files to mirror.
     *
     * @param string $html HTML.
     * @return array<int,string>
     */
    private static function link_assets(string $html): array {
        static $asset_rels = [
            'stylesheet', 'icon', 'shortcut', 'apple-touch-icon',
            'apple-touch-icon-precomposed', 'mask-icon', 'preload', 'prefetch', 'manifest',
        ];

        $out = [];

        if (!preg_match_all('#<link\b[^>]*>#i', $html, $tags)) {
            return $out;
        }

        foreach ($tags[0] as $tag) {
            if (!preg_match('#\srel\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', $tag, $rel_m)) {
                continue;
            }

            $rel    = strtolower(trim(trim($rel_m[1]), "\"'"));
            $tokens = preg_split('/\s+/', $rel) ?: [];

            if (!array_intersect($tokens, $asset_rels)) {
                continue;
            }

            if (preg_match('#\shref\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', $tag, $href_m)) {
                $out[] = trim(trim($href_m[1]), "\"'");
            }
        }

        return $out;
    }

    /**
     * Same-origin asset URLs referenced inside a CSS file (absolute, deduped).
     *
     * @param string $css  CSS source.
     * @param string $base Absolute URL of the CSS file (for relative url()).
     * @return array<int,string>
     */
    public static function extract_css_assets(string $css, string $base): array {
        return self::resolve(self::css_refs($css), $base);
    }

    /**
     * url(...) and @import targets in CSS or inline styles.
     *
     * Inline <script> blocks are removed first: JavaScript routinely contains
     * `…URL(x)` calls (e.g. `URL.createObjectURL(a)` in WordPress's emoji
     * detection script), which the case-insensitive `url()` pattern would
     * otherwise mistake for a CSS asset and try to mirror (creating junk like
     * a `/a` request). Stripping scripts is harmless for pure-CSS callers.
     *
     * @param string $content CSS or HTML.
     * @return array<int,string>
     */
    private static function css_refs(string $content): array {
        $content = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $content) ?? $content;

        return array_merge(
            self::match($content, '#url\(\s*("[^"]*"|\'[^\']*\'|[^)\s"\']+)\s*\)#i'),
            self::match($content, '#@import\s+("[^"]*"|\'[^\']*\')#i')
        );
    }

    /**
     * Parse srcset-style attributes (matched by $regex) into candidate URLs.
     *
     * @param string $html  HTML.
     * @param string $regex Regex whose group 1 captures the attribute value.
     * @return array<int,string>
     */
    private static function srcset(string $html, string $regex): array {
        $out = [];

        foreach (self::match($html, $regex) as $value) {
            foreach (self::srcset_urls($value) as $url) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * The URLs in one srcset value, parsed as the HTML spec does: a URL runs to
     * the next whitespace (so a CDN's "?w=100,200" keeps its comma), trailing
     * commas on it end the candidate, otherwise its descriptor runs to the
     * next comma. Splitting on every comma instead turned "…?w=100,200 1x"
     * into a bogus "200" asset.
     *
     * @param string $value Attribute value.
     * @return array<int,string>
     */
    private static function srcset_urls(string $value): array {
        $urls = [];
        $len  = strlen($value);
        $i    = 0;
        while ($i < $len) {
            $i += strspn($value, " \t\n\r\f,", $i);
            if ($i >= $len) {
                break;
            }
            $url_len = strcspn($value, " \t\n\r\f", $i);
            $url     = substr($value, $i, $url_len);
            $i      += $url_len;
            if (substr($url, -1) === ',') {
                $url = rtrim($url, ',');
            } else {
                // Skip the descriptor ("2x", "800w") up to the next comma.
                $comma = strpos($value, ',', $i);
                $i     = $comma === false ? $len : $comma + 1;
            }
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * URLs from lazy-load background attributes (data-bg / data-background…),
     * whose value is either a plain URL or a CSS url(...) expression.
     *
     * @param string $html HTML.
     * @return array<int,string>
     */
    private static function background_refs(string $html): array {
        $out = [];

        foreach (self::match($html, '#\sdata-(?:bg|background|background-image|bg-image)\s*=\s*("[^"]*"|\'[^\']*\')#i') as $value) {
            if (stripos($value, 'url(') !== false) {
                foreach (self::match($value, '#url\(\s*("[^"]*"|\'[^\']*\'|[^)\s"\']+)\s*\)#i') as $inner) {
                    $out[] = $inner;
                }
            } else {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Run a capture-group regex and return cleaned (unquoted) values.
     *
     * @param string $content Content to search.
     * @param string $regex   Regex whose group 1 holds the (possibly quoted) value.
     * @return array<int,string>
     */
    private static function match(string $content, string $regex): array {
        $out = [];

        if (preg_match_all($regex, $content, $matches)) {
            foreach ($matches[1] as $raw) {
                $out[] = self::clean($raw);
            }
        }

        return array_values(array_filter($out, static fn(string $v): bool => $v !== ''));
    }

    /**
     * Normalise a captured reference: decode HTML entities (inline-style values
     * carry entity-encoded quotes — `url(&quot;…&quot;)` — and `&amp;` in query
     * strings), then strip surrounding quotes/whitespace. Without the decode, an
     * entity-quoted url() value is captured whole (quotes and all) and mis-parsed
     * as a relative path.
     *
     * @param string $raw Raw captured value.
     */
    private static function clean(string $raw): string {
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);

        return trim(trim($raw), "\"'");
    }

    /**
     * Absolutise, filter to internal, and dedupe a list of raw references.
     *
     * @param array<int,string> $raw  Raw URLs/paths.
     * @param string            $base Document base URL.
     * @return array<int,string>
     */
    private static function resolve(array $raw, string $base): array {
        $out = [];

        foreach ($raw as $ref) {
            if (!Url::is_internal($ref)) {
                continue;
            }
            $abs = Url::absolutize($ref, $base);
            if ($abs !== '') {
                $out[$abs] = true;
            }
        }

        return array_keys($out);
    }
}
