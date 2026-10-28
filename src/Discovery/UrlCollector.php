<?php
/**
 * Seeds the crawl.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Discovery;

use WPEasy\ShipStatic\Support\Text;
use WPEasy\ShipStatic\Support\Url;

defined('ABSPATH') || exit;

/**
 * Provides the crawl's starting points. Three modes:
 *
 *  - `linked` (default): seed only the home page. Every other URL is reached by
 *    following internal links — a page nothing links to isn't reachable by a
 *    visitor either, so it stays out of the static copy. This also keeps
 *    builder-only URLs (e.g. Bricks `/template/...` previews) out by construction.
 *  - `all`: additionally enumerate every published public post and taxonomy term,
 *    so even unlinked/orphaned content is captured. Builder/preview post types
 *    (see excluded_post_types()) are still skipped.
 *  - `manual`: render an authoritative set — only the published public posts whose
 *    per-post "Include" switch ({@see INCLUDE_META} meta) is on. The switch is OFF
 *    by default for every post EXCEPT the site's static front page (which defaults
 *    on). The {@see \WPEasy\ShipStatic\Sync\Runner} locks crawl expansion to the
 *    effective set so an unincluded page can't return via a link.
 */
final class UrlCollector {

    /**
     * Option storing the discovery mode (`linked` | `all` | `manual`).
     */
    public const MODE_OPTION = 'shipstatic_discovery_mode';

    /**
     * Per-post meta flag for `manual` mode. '1' includes, '0' excludes. When the
     * meta is absent the post is excluded by default — except the static front
     * page, which defaults to included (see {@see is_included()}).
     */
    public const INCLUDE_META = '_shipstatic_manual_include';

    /**
     * Current discovery mode, defaulting to link-driven crawling.
     */
    public static function mode(): string {
        $mode = (string) get_option(self::MODE_OPTION);

        return in_array($mode, ['all', 'manual'], true) ? $mode : 'linked';
    }

    /**
     * Persist the discovery mode.
     *
     * @param string $mode `linked` | `all` | `manual`.
     */
    public static function set_mode(string $mode): void {
        $clean = in_array($mode, ['all', 'manual'], true) ? $mode : 'linked';
        update_option(self::MODE_OPTION, $clean, false);
    }

    /**
     * Collect seed URLs.
     *
     * @return array<int,string> Absolute URLs (deduped).
     */
    public static function collect(): array {
        $mode = self::mode();

        if ($mode === 'manual') {
            // Authoritative list — no forced home seed (see class docblock).
            $urls = self::manual_urls();
        } else {
            $urls = [home_url('/')];
            if ($mode === 'all') {
                $urls = array_merge($urls, self::post_urls(), self::term_urls());
            }
        }

        /**
         * Filters the seed URLs before crawling. Use this to add entry points
         * that aren't linked from the home page (the crawler follows links from
         * each seed onward).
         *
         * @param array<int,string> $urls Seed URLs.
         */
        $urls = (array) apply_filters('shipstatic_seed_urls', $urls);

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * Public post types eligible for the static export — every `public` type bar
     * the builder/preview ones (see excluded_post_types()). Shared with the
     * editor metabox so per-post inclusion is offered on exactly this set.
     *
     * @return array<int,string>
     */
    public static function public_post_types(): array {
        $types = get_post_types(['public' => true], 'names');
        foreach (self::excluded_post_types() as $skip) {
            unset($types[$skip]);
        }

        return array_values($types);
    }

    /**
     * Post types that are registered `public` (so they'd be enumerated) but are
     * not real site content — builder previews, design templates, etc. Bricks
     * registers `bricks_template` as public with a `/template/...` rewrite; those
     * URLs 301-redirect and must never end up in the static export.
     *
     * @return array<int,string>
     */
    private static function excluded_post_types(): array {
        /**
         * Filters post types excluded from the crawl seed.
         *
         * @param array<int,string> $types Post type names to skip.
         */
        return (array) apply_filters('shipstatic_excluded_post_types', ['attachment', 'bricks_template']);
    }

    /**
     * Permalinks of all published public posts (public types bar builder/preview
     * types — see excluded_post_types()).
     *
     * @return array<int,string>
     */
    private static function post_urls(): array {
        $types = self::public_post_types();
        if (empty($types)) {
            return [];
        }

        $ids = get_posts([
            'post_type'        => $types,
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);

        return self::permalinks($ids);
    }

    /**
     * Permalinks of the published public posts included in `manual` mode.
     *
     * @return array<int,string>
     */
    private static function manual_urls(): array {
        return self::permalinks(self::effective_included_ids());
    }

    /**
     * The included posts that actually export — every included post.
     *
     * @return array<int,int>
     */
    public static function effective_included_ids(): array {
        return self::included_ids();
    }

    /**
     * Whether a post is in the effective (exporting) set.
     *
     * @param int $post_id Post id.
     */
    public static function is_effective(int $post_id): bool {
        return $post_id > 0 && in_array($post_id, self::effective_included_ids(), true);
    }

    /**
     * How many included posts actually export.
     */
    public static function effective_count(): int {
        return count(self::effective_included_ids());
    }

    /**
     * Ids of the published public posts whose "Include" switch is on.
     *
     * @return array<int,int>
     */
    public static function included_ids(): array {
        $types = self::public_post_types();
        if (empty($types)) {
            return [];
        }

        $ids = get_posts([
            'post_type'        => $types,
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);

        // Prime the meta cache so is_included()'s get_post_meta() calls don't N+1.
        if (!empty($ids)) {
            update_meta_cache('post', $ids);
        }

        return array_values(array_filter($ids, [self::class, 'is_included']));
    }

    /**
     * How many posts are currently included.
     */
    public static function included_count(): int {
        return count(self::included_ids());
    }

    /**
     * How many PUBLISHED public pages are NOT in the current render — published
     * content that isn't in the static export. Mode-agnostic: in `linked` these
     * are orphan pages nothing links to; in `manual`, ones with the Include
     * switch off. Powers the dashboard's "published but excluded" hint so an
     * in-sync export never silently hides pages the user just published.
     *
     * @param array<int,string> $rendered Relative paths present in the render manifest.
     */
    public static function published_excluded_count(array $rendered): int {
        return count(self::published_excluded($rendered));
    }

    /**
     * Published pages/posts that aren't in the render — for the replacers'
     * page lists, which show them greyed out so it's clear why a page is
     * missing (not linked from anything, or left out by the discovery mode).
     *
     * @param array<int,string> $rendered Relative paths in the render manifest.
     * @return array<int,array{label:string,title:string}> Sorted by label.
     */
    public static function published_excluded(array $rendered): array {
        $types = self::public_post_types();
        if (empty($types)) {
            return [];
        }

        $ids = get_posts([
            'post_type'        => $types,
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);
        if (empty($ids)) {
            return [];
        }

        $have = array_flip($rendered);
        $out  = [];
        foreach ($ids as $pid) {
            $link = (string) get_permalink((int) $pid);
            $rel  = Url::to_relative_path($link);
            if ($rel !== null && isset($have[$rel])) {
                continue;
            }
            $path  = $rel !== null ? '/' . preg_replace('#/?index\.html$#i', '/', $rel) : (string) wp_parse_url($link, PHP_URL_PATH);
            $out[] = [
                'label' => $path === '/' ? '/' : rtrim($path, '/') . '/',
                'title' => Text::title((int) $pid),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * Whether a post is included in `manual` mode. Off by default, except the
     * static front page (on by default). An explicit '1'/'0' meta always wins.
     *
     * @param int $post_id Post id.
     */
    public static function is_included(int $post_id): bool {
        $meta = get_post_meta($post_id, self::INCLUDE_META, true);
        if ($meta === '1') {
            return true;
        }
        if ($meta === '0') {
            return false;
        }

        return $post_id > 0 && $post_id === self::front_page_id();
    }

    /**
     * The static front-page post id, or 0 when the home is "latest posts".
     */
    public static function front_page_id(): int {
        return get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;
    }

    /**
     * Map post ids to their non-empty permalinks.
     *
     * @param array<int,int> $ids Post ids.
     * @return array<int,string>
     */
    private static function permalinks(array $ids): array {
        $urls = [];
        foreach ($ids as $id) {
            $link = get_permalink($id);
            if (is_string($link) && $link !== '') {
                $urls[] = $link;
            }
        }

        return $urls;
    }

    /**
     * Term archive links for all public taxonomies (non-empty terms).
     *
     * @return array<int,string>
     */
    private static function term_urls(): array {
        $taxonomies = get_taxonomies(['public' => true], 'names');
        if (empty($taxonomies)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => array_values($taxonomies),
            'hide_empty' => true,
            'number'     => 0,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        $urls = [];
        foreach ($terms as $term) {
            $link = get_term_link($term);
            if (is_string($link) && $link !== '') {
                $urls[] = $link;
            }
        }

        return $urls;
    }
}
