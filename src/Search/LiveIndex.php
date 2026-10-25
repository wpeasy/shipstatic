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
     * What each rendered page is — relative path => "post:<id>", "term:<id>",
     * "home", "archive" or "none" — as the render request reported it
     * (PageRenderer::OBJECT_HEADER). Written by the Runner at the end of a render.
     */
    public const OBJECTS_OPTION = 'shipstatic_render_objects';

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
     * Save what each page of a render is (see OBJECTS_OPTION).
     *
     * @param array<string,string> $objects Relative path => object.
     * @param bool                 $merge   Single-page render: merge into the stored map.
     * @param array<int,string>    $remove  Relative paths no longer in the render.
     */
    public static function save_objects(array $objects, bool $merge, array $remove = []): void {
        $clean = [];
        foreach ($objects as $relative => $object) {
            if (preg_match('/^(post|term):\d+$|^(home|archive|none)$/', (string) $object)) {
                $clean[(string) $relative] = (string) $object;
            }
        }
        if ($merge) {
            $stored = get_option(self::OBJECTS_OPTION, []);
            $clean  = array_merge(is_array($stored) ? $stored : [], $clean);
        }
        foreach ($remove as $relative) {
            unset($clean[(string) $relative]);
        }
        update_option(self::OBJECTS_OPTION, $clean, false);
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
     * @return array<int,array<string,mixed>> Records, each with an internal `o` (what the page is, see OBJECTS_OPTION).
     */
    private static function records(array $render, string $served): array {
        $pages = array_filter($render, static fn($meta, $rel): bool => strtolower((string) pathinfo((string) $rel, PATHINFO_EXTENSION)) === 'html', ARRAY_FILTER_USE_BOTH);
        $objects = get_option(self::OBJECTS_OPTION, []);
        $objects = is_array($objects) ? $objects : [];
        $key     = md5((string) wp_json_encode([IndexBuilder::VERSION, $served, array_map(static fn(array $m): string => (string) $m['hash'], $pages), $objects]));

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
                // What the page is, as its render reported it. Never resolved from
                // the URL here: this runs in a visitor's request, where a private or
                // draft post doesn't resolve and would look like an archive.
                $record['o'] = (string) ($objects[$relative] ?? '');
                $records[]   = $record;
            }
        }
        usort($records, static fn(array $a, array $b): int => strcmp((string) $a['u'], (string) $b['u']));

        set_transient(self::TRANSIENT, ['key' => $key, 'records' => $records], WEEK_IN_SECONDS);

        return $records;
    }

    /**
     * Keep pages still public now: a post unpublished, made private or
     * password-protected since the last Process — or deleted — is left out,
     * and so is a deleted term's archive. Fails closed: a page whose render
     * didn't report what it is (a render from before this was recorded) is
     * left out until the next Process.
     *
     * @param array<int,array<string,mixed>> $records Records with `o`.
     * @return array<int,array<string,mixed>> Records without `o`.
     */
    private static function published(array $records): array {
        $ids = [];
        foreach ($records as $record) {
            if (preg_match('/^post:(\d+)$/', (string) ($record['o'] ?? ''), $m)) {
                $ids[] = (int) $m[1];
            }
        }
        if ($ids !== []) {
            _prime_post_caches($ids, false, false);
        }

        $out = [];
        foreach ($records as $record) {
            $object = (string) ($record['o'] ?? '');
            unset($record['o']);

            if (preg_match('/^post:(\d+)$/', $object, $m)) {
                $post = get_post((int) $m[1]);
                if ($post === null || $post->post_status !== 'publish' || $post->post_password !== '' || !is_post_publicly_viewable($post)) {
                    continue;
                }
            } elseif (preg_match('/^term:(\d+)$/', $object, $m)) {
                $term = get_term((int) $m[1]);
                if (!$term instanceof \WP_Term) {
                    continue;
                }
            } elseif (!in_array($object, ['home', 'archive'], true)) {
                continue;
            }
            $out[] = $record;
        }

        return $out;
    }
}
