<?php
/**
 * Keeps relay results to the posts the static site has pages for.
 *
 * @package WPEasy\ShipStatic
 * @since   2.5.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Bricks;

use WP_Query;
use WPEasy\ShipStatic\Search\LiveIndex;

defined('ABSPATH') || exit;

/**
 * While the relay asks Bricks for a filter result, post queries are limited to
 * the posts in the last render. A post published (or included) since then has
 * no page on the static site and no image files there, so listing it would
 * give a link that 404s and a broken image; it appears once the next Process
 * and Sync has put it there.
 *
 * Limiting the query itself (not the reply) keeps the counts, the pagination
 * and the options consistent with the items shown.
 *
 * Only post types that have at least one post in the render are limited: a type
 * whose posts have no pages of their own (the loop's items link nowhere) is
 * listed as before. With no render recorded, nothing is limited.
 */
final class ExportedOnly {

    /** Ids of the posts in the render, and the post types they belong to; null = no limit. */
    private static ?array $scope = null;

    /**
     * Start limiting post queries.
     */
    public static function begin(): void {
        self::$scope = self::scope();
        if (self::$scope !== null) {
            add_filter('posts_where', [self::class, 'where'], 10, 2);
        }
    }

    /**
     * Stop limiting.
     */
    public static function end(): void {
        remove_filter('posts_where', [self::class, 'where'], 10);
        self::$scope = null;
    }

    /**
     * The SQL condition: a limited type's posts must be in the render.
     *
     * @param string   $where SQL.
     * @param WP_Query $query Query.
     */
    public static function where($where, $query): string {
        $where = (string) $where;
        if (self::$scope === null || !$query instanceof WP_Query) {
            return $where;
        }
        global $wpdb;
        $types = self::$scope['types'];
        $ids   = self::$scope['ids'];

        $type_sql = implode(',', array_fill(0, count($types), '%s'));
        $id_sql   = implode(',', array_map('absint', $ids));
        // Ids are integers (absint); the post types are bound.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder -- built above from integers and %s placeholders.
        $limit = $wpdb->prepare(" AND ({$wpdb->posts}.post_type NOT IN ($type_sql) OR {$wpdb->posts}.ID IN ($id_sql))", $types);

        return $where . $limit;
    }

    /**
     * Posts in the last render and their types, or null when there is no render
     * to limit to.
     *
     * @return array{ids:array<int,int>,types:array<int,string>}|null
     */
    private static function scope(): ?array {
        $objects = get_option(LiveIndex::OBJECTS_OPTION, []);
        $ids     = [];
        foreach (is_array($objects) ? $objects : [] as $object) {
            if (preg_match('/^post:(\d+)$/', (string) $object, $m)) {
                $ids[(int) $m[1]] = (int) $m[1];
            }
        }
        if ($ids === []) {
            return null;
        }

        $cached = get_transient('shipstatic_exported_scope');
        $key    = md5((string) wp_json_encode(array_keys($ids)));
        if (is_array($cached) && ($cached['key'] ?? '') === $key) {
            return ['ids' => array_values($ids), 'types' => (array) $cached['types']];
        }

        _prime_post_caches(array_values($ids), false, false);
        $types = [];
        foreach ($ids as $id) {
            $type = get_post_type($id);
            if (is_string($type) && $type !== '') {
                $types[$type] = $type;
            }
        }
        if ($types === []) {
            return null;
        }
        set_transient('shipstatic_exported_scope', ['key' => $key, 'types' => array_values($types)], DAY_IN_SECONDS);

        return ['ids' => array_values($ids), 'types' => array_values($types)];
    }
}
