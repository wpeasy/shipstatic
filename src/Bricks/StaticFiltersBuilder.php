<?php
/**
 * Builds the JSON file one Bricks query loop needs to answer its own filter
 * requests on the static site.
 *
 * @package WPEasy\ShipStatic
 * @since   2.5.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Bricks;

use WPEasy\ShipStatic\Render\PageRenderer;
use WPEasy\ShipStatic\Sync\Job;

defined('ABSPATH') || exit;

/**
 * One loop per call of {@see tick()}, resumable (a loop with many pages of
 * results is fetched over several ticks), so the render job's phases and
 * heartbeat keep working. What goes in the file (the shape is
 * src-svelte/static-filters/types.ts):
 *
 * - the posts the loop can show, in its own order, with just the fields the
 *   filters read (title, date, excerpt, content, author, the meta keys used);
 * - Bricks' own filter index for each filter element (its table, restricted
 *   to those posts) — the same data Bricks counts from;
 * - the filter elements' settings, and their markup as Bricks prints it with
 *   nothing selected;
 * - every item's markup: Bricks is asked, page by page, for the loop's
 *   unfiltered results in a trusted loopback request that marks each item with
 *   its object id ({@see StaticFilters::mark_item()}), and the answer is cut
 *   up at those marks.
 *
 * Only what a logged-out visitor can read goes in: published posts, and no
 * content for password-protected ones. Anything the data files can't carry
 * (item-specific CSS, popups, an item count that does not add up) refuses the
 * loop with a reason, and it keeps using the relay.
 */
final class StaticFiltersBuilder {

    /** Seconds one tick may spend asking Bricks for pages of results. */
    private const TICK_SECONDS = 10;

    /**
     * Do one step of the build.
     *
     * @param Job $job The render job.
     * @return bool True when there is nothing left to build.
     */
    public static function tick(Job $job): bool {
        $cur = $job->data['sfCur'] ?? null;

        if (!is_array($cur)) {
            $queue = (array) ($job->data['sfQueue'] ?? []);
            if (!$queue) {
                return true;
            }
            $loop                = array_shift($queue);
            $job->data['sfQueue'] = $queue;
            $cur                 = self::begin($job, (array) $loop);
            if ($cur === null) {
                return empty($job->data['sfQueue']);
            }
        }

        $cur = self::fetch_pages($job, $cur);
        if ($cur === null) {
            unset($job->data['sfCur']);
        } else {
            $job->data['sfCur'] = $cur;
        }

        return empty($job->data['sfQueue']) && !isset($job->data['sfCur']);
    }

    /**
     * Record a loop's outcome for the registry.
     *
     * @param Job                 $job    Job.
     * @param array<string,mixed> $loop   Queued loop.
     * @param array<string,mixed> $result {ok, reason, items, hash, id}.
     */
    private static function done(Job $job, array $loop, array $result): void {
        $job->data['sfDone'][(string) $loop['rel']][(string) $loop['qid']] = $result;
    }

    /**
     * Refuse a loop.
     *
     * @param Job                 $job    Job.
     * @param array<string,mixed> $loop   Queued loop.
     * @param string              $reason Plain English.
     */
    private static function refuse(Job $job, array $loop, string $reason): void {
        self::done($job, $loop, ['ok' => false, 'reason' => $reason, 'items' => 0, 'hash' => '', 'id' => '']);
    }

    /**
     * Read the loop's population, filters and index; refuse it if it can't be
     * answered statically. Returns the in-progress state, or null (refused).
     *
     * @param Job                 $job  Job.
     * @param array<string,mixed> $loop Queued loop.
     * @return array<string,mixed>|null
     */
    private static function begin(Job $job, array $loop): ?array {
        global $wpdb;

        $vars = json_decode((string) $loop['vars'], true);
        if (!is_array($vars) || !class_exists('\\Bricks\\Query_Filters')) {
            self::refuse($job, $loop, 'The loop’s query could not be read');
            return null;
        }

        // The filter elements, from Bricks' own registry.
        $ids      = array_keys((array) $loop['filters']);
        $table    = \Bricks\Query_Filters::get_table_name('element');
        $elements = [];
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '%s'));
            // The table name is Bricks' own (prefix + fixed suffix) and goes in as an identifier; the values are bound.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results($wpdb->prepare("SELECT filter_id, filter_type, filter_action, settings FROM %i WHERE filter_id IN ({$marks})", array_merge([$table], $ids)), ARRAY_A);
            foreach ((array) $rows as $row) {
                $settings                     = json_decode((string) $row['settings'], true);
                $elements[$row['filter_id']] = ['type' => (string) $row['filter_type'], 'action' => (string) $row['filter_action'], 'settings' => is_array($settings) ? $settings : []];
            }
        }

        $reason = StaticFilters::unsupported($loop, $vars, $elements);
        if ($reason !== null) {
            self::refuse($job, $loop, $reason);
            return null;
        }

        // Everything the loop can show (what a logged-out visitor can read).
        $population = $vars;
        unset($population['_bricks_query_signature']);
        $population = array_merge($population, ['paged' => 1, 'posts_per_page' => -1, 'nopaging' => true, 'fields' => 'ids', 'no_found_rows' => true, 'post_status' => 'publish']);
        $order      = array_map('intval', (new \WP_Query($population))->posts);
        $max        = max(1, (int) apply_filters('shipstatic_static_filters_max_items', StaticFilters::MAX_ITEMS));
        if (!$order) {
            self::refuse($job, $loop, 'The loop shows no posts');
            return null;
        }
        if (count($order) > $max) {
            self::refuse($job, $loop, count($order) . ' items is more than the limit of ' . $max);
            return null;
        }
        $in_loop = array_flip($order);

        $filters = [];
        $meta    = [];
        foreach (array_keys((array) $loop['filters']) as $fid) {
            if (!isset($elements[$fid])) {
                continue;
            }
            $def = self::definition((string) $fid, $elements[$fid]);
            if ($def['metaKey'] !== '') {
                $meta[$def['metaKey']] = true;
            }
            foreach ((array) ($def['sorts'] ?? []) as $sort) {
                if ($sort['key'] !== '') {
                    $meta[$sort['key']] = true;
                }
            }
            $filters[$fid] = $def;
        }

        // Only the list filters (checkbox, radio, select) read Bricks' index; range, search and date
        // pickers read the posts themselves.
        $rows  = [];
        $index = \Bricks\Query_Filters::get_table_name('index');
        foreach ($filters as $fid => $def) {
            if ($def['action'] !== 'filter' || !in_array($def['element'], ['filter-checkbox', 'filter-radio', 'filter-select'], true)) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $all        = $wpdb->get_results($wpdb->prepare('SELECT object_id, filter_value, filter_value_display, filter_value_id, filter_value_parent FROM %i WHERE filter_id = %s', $index, $fid), ARRAY_A);
            $rows[$fid] = [];
            foreach ((array) $all as $r) {
                if (isset($in_loop[(int) $r['object_id']])) {
                    $rows[$fid][] = [(int) $r['object_id'], (string) $r['filter_value'], (string) $r['filter_value_display'], (int) $r['filter_value_id'], (int) $r['filter_value_parent']];
                }
            }
        }
        // A filter element with no index rows at all hasn't been indexed: Bricks would show nothing either.
        foreach ($filters as $fid => $def) {
            if (in_array($def['element'], ['filter-checkbox', 'filter-radio', 'filter-select'], true) && $def['action'] === 'filter' && empty($rows[$fid])) {
                self::refuse($job, $loop, 'The Bricks filter index has no data for filter ' . $fid . ' (regenerate it in Bricks → Settings)');
                return null;
            }
        }

        // The text is only there for a search filter to match words in; without one it is not written at all.
        // (It is the same text WordPress's own search reads, never a password-protected post's.)
        $searchable = (bool) array_filter($filters, static fn(array $f): bool => $f['element'] === 'filter-search');
        $posts      = [];
        foreach ($order as $id) {
            $p = get_post($id);
            if (!$p) {
                continue;
            }
            $text       = $searchable && $p->post_password === '';
            $posts[$id] = [
                'title'   => (string) $p->post_title,
                'date'    => (string) $p->post_date,
                'content' => $text ? (string) apply_filters('shipstatic_static_filters_post_text', $p->post_content, $p, 'content') : '',
                'excerpt' => $text ? (string) apply_filters('shipstatic_static_filters_post_text', $p->post_excerpt, $p, 'excerpt') : '',
                'author'  => (int) $p->post_author,
            ];
            foreach (array_keys($meta) as $key) {
                $posts[$id]['meta'][$key] = (string) get_post_meta($id, (string) $key, true);
            }
        }

        $id = substr(md5($loop['rel'] . '|' . $loop['qid']), 0, 12);
        $ds = [
            'queryId'      => (string) $loop['qid'],
            'perPage'      => (int) $vars['posts_per_page'],
            'order'        => $order,
            'posts'        => $posts,
            'filters'      => $filters,
            'rows'         => $rows,
            'items'        => [],
            'filterHtml'   => [],
            'paginationId' => self::pagination_id($loop),
            'updatedQuery' => null,
            'baseUrl'      => (string) $loop['url'],
        ];
        if (!is_dir(StaticFilters::cache_dir()) && !wp_mkdir_p(StaticFilters::cache_dir())) {
            self::refuse($job, $loop, 'The cache folder could not be created');
            return null;
        }
        $cur = ['loop' => $loop, 'id' => $id, 'page' => 1, 'pages' => (int) ceil(count($order) / (int) $vars['posts_per_page']), 'total' => count($order)];
        self::save_part($id, $ds);

        return $cur;
    }

    /**
     * Fetch pages of results until done or the tick's time is up.
     *
     * @param Job                 $job Job.
     * @param array<string,mixed> $cur In-progress state.
     * @return array<string,mixed>|null State to continue with, or null when this loop is finished.
     */
    private static function fetch_pages(Job $job, array $cur): ?array {
        $loop = (array) $cur['loop'];
        $ds   = self::load_part((string) $cur['id']);
        if ($ds === null) {
            self::refuse($job, $loop, 'The working file disappeared');
            return null;
        }

        $started   = microtime(true);
        $filter_id = array_merge(array_keys((array) $ds['filters']), $ds['paginationId'] ? [(string) $ds['paginationId']] : []);

        while ((int) $cur['page'] <= (int) $cur['pages']) {
            if (microtime(true) - $started > self::TICK_SECONDS) {
                self::save_part((string) $cur['id'], $ds);
                return $cur;
            }
            $page  = (int) $cur['page'];
            $reply = self::query($loop, $filter_id, $page);
            if (!empty($reply['error']) || !isset($reply['html'])) {
                self::refuse($job, $loop, 'Bricks did not answer for page ' . $page . (!empty($reply['error']) ? ': ' . (is_string($reply['error']) ? $reply['error'] : 'error') : ''));
                self::drop((string) $cur['id']);
                return null;
            }
            // Bricks wraps even an empty stylesheet in a <style> tag with a comment; only real CSS (or popups) refuses the loop.
            $css = trim((string) preg_replace('#<style[^>]*>|</style>|/\*.*?\*/#s', '', (string) ($reply['styles'] ?? '')));
            if ($css !== '' || !empty($reply['popups'])) {
                self::refuse($job, $loop, 'The items carry their own CSS or popups');
                self::drop((string) $cur['id']);
                return null;
            }

            $items = self::split_items((string) $reply['html']);
            $uq    = (array) ($reply['updated_query'] ?? []);
            $want  = isset($uq['start'], $uq['end']) ? (int) $uq['end'] - (int) $uq['start'] + 1 : count($items);
            if (count($items) !== $want) {
                self::refuse($job, $loop, 'Could not tell the loop’s items apart (' . count($items) . ' found, ' . $want . ' expected)');
                self::drop((string) $cur['id']);
                return null;
            }
            foreach ($items as $oid => $markup) {
                $ds['items'][$oid] = $markup;
            }
            if ($page === 1) {
                $ds['filterHtml']   = array_map('strval', (array) ($reply['updated_filters'] ?? []));
                $ds['updatedQuery'] = $uq;
            }
            $cur['page'] = $page + 1;
        }

        // Every post must have its markup, or a filtered view would show a hole.
        if (count($ds['items']) !== (int) $cur['total'] || array_diff_key(array_flip($ds['order']), $ds['items'])) {
            self::refuse($job, $loop, 'Some items could not be captured (' . count($ds['items']) . ' of ' . $cur['total'] . ')');
            self::drop((string) $cur['id']);
            return null;
        }
        unset($ds['updatedQuery']['query_vars']['_bricks_query_signature']);

        $file = StaticFilters::cache_dir() . '/' . $cur['id'] . '.json';
        $json = wp_json_encode($ds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || file_put_contents($file, $json) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the plugin's own cache folder.
            self::refuse($job, $loop, 'The data file could not be written');
            self::drop((string) $cur['id']);
            return null;
        }
        self::drop((string) $cur['id'], true);
        self::done($job, $loop, ['ok' => true, 'reason' => '', 'items' => (int) $cur['total'], 'hash' => md5($json), 'id' => (string) $cur['id']]);

        return null;
    }

    /**
     * What the engine needs to know about one filter element.
     *
     * @param string              $id Element id.
     * @param array<string,mixed> $el {type, action, settings}.
     * @return array<string,mixed>
     */
    private static function definition(string $id, array $el): array {
        $s      = (array) $el['settings'];
        $action = (string) ($el['action'] !== '' ? $el['action'] : ($s['filterAction'] ?? 'filter'));
        $def    = [
            'id'             => $id,
            'element'        => (string) $el['type'],
            'action'         => $action,
            'source'         => (string) ($s['filterSource'] ?? ''),
            'nice'           => (string) ($s['filterNiceName'] ?? ''),
            'logic'          => strtoupper((string) ($s['filterMultiLogic'] ?? 'OR')),
            'metaKey'        => (string) ($s['customFieldKey'] ?? ''),
            'range'          => !empty($s['isDateRange']),
            'operator'       => (string) ($s['fieldCompareOperator'] ?? 'is'),
            'perPageOptions' => isset($s['perPageOptions']) ? (string) $s['perPageOptions'] : null,
        ];
        if ($action === 'sort') {
            $def['sorts'] = [];
            foreach ((array) ($s['sortOptions'] ?? []) as $o) {
                $src = (string) ($o['optionSource'] ?? '');
                $key = in_array($src, ['meta_value', 'meta_value_num'], true) ? (string) ($o['optionMetaKey'] ?? '') : $src;
                $def['sorts'][$key . '_' . (string) ($o['optionOrder'] ?? 'ASC')] = [
                    'label' => (string) ($o['optionLabel'] ?? ''),
                    'src'   => $src,
                    'key'   => (string) ($o['optionMetaKey'] ?? ''),
                    'dir'   => (string) ($o['optionOrder'] ?? 'ASC'),
                ];
            }
        }

        return $def;
    }

    /**
     * The id of the loop's pagination element, if it has one.
     *
     * @param array<string,mixed> $loop Queued loop.
     */
    private static function pagination_id(array $loop): ?string {
        foreach ((array) $loop['filters'] as $id => $type) {
            if ($type === 'pagination') {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * Ask Bricks for one page of the loop's unfiltered results, like the
     * browser does (and as a visitor: no user, the render token proves it is us).
     *
     * @param array<string,mixed> $loop       Queued loop.
     * @param array<int,string>   $filter_ids Filter element ids to get markup for.
     * @param int                 $page       Page number.
     * @return array<string,mixed>
     */
    private static function query(array $loop, array $filter_ids, int $page): array {
        $url  = rest_url('bricks/v1/query_result');
        $args = PageRenderer::request_args($url);

        // A nonce for the visitor the loopback request is, not for whoever runs the job.
        $previous = get_current_user_id();
        wp_set_current_user(0);
        $nonce = wp_create_nonce('bricks-nonce');
        wp_set_current_user($previous);

        $body = [
            'postId'               => (string) $loop['post'],
            'queryContextType'     => 'post',
            'queryElementId'       => (string) $loop['qid'],
            'previewTemplateId'    => '0',
            'originalQueryVars'    => (string) $loop['vars'],
            'pageFilters'          => [],
            'filters'              => $filter_ids,
            'selectedFilters'      => (object) [],
            'afTags'               => [],
            'nonce'                => $nonce,
            'baseUrl'              => (string) $loop['url'],
            'lang'                 => false,
            'mainQueryId'          => false,
            'activeSearchTemplate' => '0',
            'urlParams'            => (object) [],
        ];
        if ($page > 1) {
            $body['infinitePage'] = $page;
        }

        $args['headers']                           = array_merge((array) ($args['headers'] ?? []), ['Content-Type' => 'application/json', StaticFilters::EXPORT_HEADER => (string) $loop['qid']]);
        $args['body']                              = (string) wp_json_encode($body);
        $response                                  = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }
        $reply = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($reply) ? $reply : ['error' => 'HTTP ' . (int) wp_remote_retrieve_response_code($response)];
    }

    /**
     * Cut Bricks' answer (the markup of a page of results) into items at the
     * marks {@see StaticFilters::mark_item()} left, keyed by object id.
     *
     * @param string $html Loop markup.
     * @return array<int,string>
     */
    private static function split_items(string $html): array {
        $items = [];
        $parts = preg_split('/(?=<[a-z][a-z0-9]*\b[^>]*\b' . preg_quote(StaticFilters::ITEM_ATTRIBUTE, '/') . '="\d+")/i', $html, -1, PREG_SPLIT_NO_EMPTY);
        foreach ((array) $parts as $part) {
            if (!preg_match('/^<[a-z][a-z0-9]*\b[^>]*\b' . preg_quote(StaticFilters::ITEM_ATTRIBUTE, '/') . '="(\d+)"/i', $part, $m)) {
                continue;
            }
            // The last item is followed by Bricks' own "trail" element, which is not part of it.
            $part                = (string) preg_replace('/<[a-z][a-z0-9]*\b[^>]*\bbrx-query-trail\b.*$/is', '', $part);
            $items[(int) $m[1]] = $part;
        }

        return $items;
    }

    /**
     * Where the unfinished dataset is kept between ticks.
     *
     * @param string $id Dataset id.
     */
    private static function part_path(string $id): string {
        return StaticFilters::cache_dir() . '/' . $id . '.part';
    }

    /**
     * @param string              $id Dataset id.
     * @param array<string,mixed> $ds Dataset so far.
     */
    private static function save_part(string $id, array $ds): void {
        file_put_contents(self::part_path($id), (string) wp_json_encode($ds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the plugin's own cache folder.
    }

    /**
     * @param string $id Dataset id.
     * @return array<string,mixed>|null
     */
    private static function load_part(string $id): ?array {
        $path = self::part_path($id);
        $ds   = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($ds) ? $ds : null;
    }

    /**
     * Remove the working file (and, on a failure, a half-written data file).
     *
     * @param string $id      Dataset id.
     * @param bool   $success Keep the finished data file.
     */
    private static function drop(string $id, bool $success = false): void {
        wp_delete_file(self::part_path($id));
        if (!$success) {
            wp_delete_file(StaticFilters::cache_dir() . '/' . $id . '.json');
        }
    }
}
