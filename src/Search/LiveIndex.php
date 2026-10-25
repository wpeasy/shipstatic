<?php
/**
 * The site-search index for the live WordPress site.
 *
 * @package WPEasy\ShipStatic
 * @since   2.3.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Search;

use WPEasy\ShipStatic\Support\Paths;
use WPEasy\ShipStatic\Support\Url;
use WPEasy\ShipStatic\Sync\Manifest;

defined('ABSPATH') || exit;

/**
 * Lets the ShipStatic Search element search as you type on the live WordPress
 * site too, served by GET /shipstatic/v1/search-index (REST\SearchIndexController).
 *
 * Built from the render cache, not WP_Query: Bricks keeps page content in post
 * meta, so post_content is mostly empty, and the render already holds each
 * page's final text and the same page set as the export. It uses the site's own
 * URLs and wording (no destination replacements or sub-path). It is as fresh as
 * the last Process, except that each page's post status is checked on every
 * request, so a page unpublished since then drops out at once.
 */
final class LiveIndex {

    /** '1' / '0'. Only takes effect while Site search is on. */
    public const OPTION = 'shipstatic_search_live';

    /** Cached records for the current render: {key, records}. */
    private const TRANSIENT = 'shipstatic_live_search_index';

    /**
     * Whether the live site uses ShipStatic search.
     */
    public static function enabled(): bool {
        return IndexBuilder::enabled() && get_option(self::OPTION, '0') === '1';
    }

    /**
     * Turn live-site search on or off (the stored choice, independent of Site search).
     *
     * @param bool $on New state.
     */
    public static function set_enabled(bool $on): void {
        update_option(self::OPTION, $on ? '1' : '0', false);
    }

    /**
     * The stored choice, ignoring whether Site search is on.
     */
    public static function chosen(): bool {
        return get_option(self::OPTION, '0') === '1';
    }

    /**
     * The index as `{v, pages}`, or null when there is no render yet.
     *
     * @return array{v:int,pages:array<int,array<string,mixed>>}|null
     */
    public static function payload(): ?array {
        $render = Manifest::load(Manifest::RENDER_OPTION);
        if ($render === []) {
            return null;
        }

        $served  = Url::normalize_base((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
        $records = self::records($render, $served);

        return ['v' => IndexBuilder::VERSION, 'pages' => self::published($records)];
    }

    /**
     * Records for every rendered page, cached until the render changes.
     *
     * @param array<string,array{size:int,hash:string,src?:string}> $render Render manifest.
     * @param string                                                $served Site's home path ("" or "/sub").
     * @return array<int,array<string,mixed>> Records, each with an internal `id` (post id, 0 if none).
     */
    private static function records(array $render, string $served): array {
        $pages = array_filter($render, static fn($meta, $rel): bool => strtolower((string) pathinfo((string) $rel, PATHINFO_EXTENSION)) === 'html', ARRAY_FILTER_USE_BOTH);
        $key   = md5((string) wp_json_encode([IndexBuilder::VERSION, $served, array_map(static fn(array $m): string => (string) $m['hash'], $pages)]));

        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached) && ($cached['key'] ?? '') === $key && is_array($cached['records'] ?? null)) {
            return $cached['records'];
        }

        $root    = realpath(Paths::cache_dir());
        $records = [];
        foreach ($pages as $relative => $meta) {
            // Read only cache files the render wrote (the manifest is ours, but
            // never read a path outside the cache folder).
            $src = realpath((string) ($meta['src'] ?? ''));
            if ($root === false || $src === false || strpos(wp_normalize_path($src), wp_normalize_path($root) . '/') !== 0) {
                continue;
            }
            $html   = Url::rebase((string) file_get_contents($src), $served);
            $record = IndexBuilder::record($html, (string) $relative, $served);
            if ($record !== null) {
                $record['id'] = url_to_postid(home_url('/' . preg_replace('#(^|/)index\.html$#i', '$1', ltrim((string) $relative, '/'))));
                $records[]    = $record;
            }
        }
        usort($records, static fn(array $a, array $b): int => strcmp((string) $a['u'], (string) $b['u']));

        set_transient(self::TRANSIENT, ['key' => $key, 'records' => $records], WEEK_IN_SECONDS);

        return $records;
    }

    /**
     * Keep pages still public now: a post unpublished, made private or
     * password-protected since the last Process is left out. Pages that aren't
     * a single post (home, archives) stay.
     *
     * @param array<int,array<string,mixed>> $records Records with `id`.
     * @return array<int,array<string,mixed>> Records without `id`.
     */
    private static function published(array $records): array {
        $ids = array_values(array_filter(array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $records)));
        if ($ids !== []) {
            _prime_post_caches($ids, false, false);
        }

        $out = [];
        foreach ($records as $record) {
            $id = (int) ($record['id'] ?? 0);
            unset($record['id']);
            if ($id > 0) {
                $post = get_post($id);
                if ($post === null || $post->post_status !== 'publish' || $post->post_password !== '' || !is_post_publicly_viewable($post)) {
                    continue;
                }
            }
            $out[] = $record;
        }

        return $out;
    }
}
