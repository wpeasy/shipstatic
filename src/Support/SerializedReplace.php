<?php
/**
 * Search and replace through serialized values in the database.
 *
 * @package WPEasy\ShipStatic
 * @since   2.4.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Support;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a one-off, admin-started migration over core tables; nothing here may be cached.

/**
 * Rewrites strings in core tables with a callback, descending into
 * serialized PHP values (Bricks keeps its page data serialized) and
 * re-serializing only what changed, so string lengths stay correct. Objects
 * inside serialized data are left alone (they can't be rebuilt safely without
 * their classes). Used by Deploy\WordPressMove.
 */
final class SerializedReplace {

    /**
     * Table → [primary key, columns] for the core tables that hold content
     * and settings.
     *
     * @return array<string,array{0:string,1:array<int,string>}>
     */
    public static function targets(): array {
        global $wpdb;

        return [
            $wpdb->posts    => ['ID', ['post_content', 'post_excerpt', 'post_content_filtered']],
            $wpdb->postmeta => ['meta_id', ['meta_value']],
            $wpdb->options  => ['option_id', ['option_value']],
            $wpdb->termmeta => ['meta_id', ['meta_value']],
        ];
    }

    /**
     * Run a replacement over the target tables.
     *
     * @param callable(string):string $fn   Rewrites one plain string.
     * @param callable(string,string,array<string,mixed>):bool|null $skip Row filter (table, column, row) → true to leave it alone.
     * @return int Rows changed.
     */
    public static function run(callable $fn, ?callable $skip = null): int {
        global $wpdb;

        $changed = 0;
        foreach (self::targets() as $table => [$key, $columns]) {
            $last = 0;
            do {
                // Option names are needed by the skip filter.
                $fields = array_merge([$key], $columns, $table === $wpdb->options ? ['option_name'] : []);
                $list   = implode(', ', array_fill(0, count($fields), '%i'));
                $rows   = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %i per field, built above.
                    "SELECT {$list} FROM %i WHERE %i > %d ORDER BY %i ASC LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only %i placeholders are interpolated.
                    array_merge($fields, [$table, $key, $last, $key])
                ), ARRAY_A);
                foreach ((array) $rows as $row) {
                    $last   = (int) $row[$key];
                    $update = [];
                    foreach ($columns as $column) {
                        $value = $row[$column];
                        if (!is_string($value) || $value === '' || ($skip !== null && $skip($table, $column, $row))) {
                            continue;
                        }
                        $new = self::value($value, $fn);
                        if ($new !== $value) {
                            $update[$column] = $new;
                        }
                    }
                    if ($update !== []) {
                        $wpdb->update($table, $update, [$key => $last]);
                        $changed++;
                    }
                }
            } while (is_array($rows) && count($rows) === 500);
        }
        wp_cache_flush();

        return $changed;
    }

    /**
     * Rewrite one stored value (plain or serialized).
     *
     * @param string                  $value Stored value.
     * @param callable(string):string $fn    Rewrites one plain string.
     */
    public static function value(string $value, callable $fn): string {
        if (is_serialized($value)) {
            $data = @unserialize($value, ['allowed_classes' => false]); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- broken data is left as it is; no classes are instantiated.
            if ($data === false && $value !== serialize(false)) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- comparing with a literal false.
                return $value;
            }
            $changed = false;
            $new     = self::walk($data, $fn, $changed);

            return $changed ? serialize($new) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- written back in the format it was stored in.
        }

        return $fn($value);
    }

    /**
     * Recurse through unserialized data.
     *
     * @param mixed                   $data    Data.
     * @param callable(string):string $fn      Rewrites one plain string.
     * @param bool                    $changed Set when anything changed.
     * @return mixed
     */
    private static function walk($data, callable $fn, bool &$changed) {
        if (is_string($data)) {
            $new = self::value($data, $fn); // Serialized data inside serialized data.
            if ($new !== $data) {
                $changed = true;
            }
            return $new;
        }
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = self::walk($v, $fn, $changed);
            }
        }

        return $data; // Objects and scalars are left alone.
    }

    /**
     * A callback that adds a sub-path to every URL on a host, also in its
     * JSON-escaped form (https:\/\/host), never twice: "https://example.com"
     * and "https://example.com/about/" get "/wp", "https://example.com/wp/…"
     * and "https://example.com.au" don't. A port is part of the host: with
     * "example.com:8090" the sub-path goes after the port, and without one a
     * URL with a port ("https://example.com:8443/") is another site and is
     * left alone. With $remove it takes the sub-path off again.
     *
     * @param string $host   Host, with ":port" when the site uses one (www. is matched too).
     * @param string $sub    Sub-path name, e.g. "wp".
     * @param bool   $remove Take it off instead.
     * @return callable(string):string
     */
    public static function url_subpath(string $host, string $sub, bool $remove = false): callable {
        $h   = preg_quote(preg_replace('/^www\./i', '', $host) ?? $host, '~');
        $s   = preg_quote($sub, '~');
        $end = '(?=[/\\\\"\'?#\s<>)\],]|$)';
        if ($remove) {
            $re = '~((?:https?:)?(?://|\\\\/\\\\/)(?:www\.)?' . $h . ')(/|\\\\/)' . $s . $end . '~i';

            return static fn(string $v): string => (string) preg_replace($re, '$1', $v);
        }
        $re = '~((?:https?:)?(//|\\\\/\\\\/)(?:www\.)?' . $h . ')(?![\w.-])(?!:\d)(?!(?:/|\\\\/)' . $s . $end . ')~i';

        return static function (string $v) use ($re, $sub): string {
            return (string) preg_replace_callback($re, static fn(array $m): string => $m[1] . ($m[2] === '//' ? '/' : '\\/') . $sub, $v);
        };
    }
}
