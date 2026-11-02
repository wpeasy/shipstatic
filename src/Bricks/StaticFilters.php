<?php
/**
 * Bricks filters, sorting, search and pagination on the static site WITHOUT
 * WordPress: the answers come from JSON files in the export.
 *
 * @package WPEasy\ShipStatic
 * @since   2.5.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Bricks;

use WPEasy\ShipStatic\Render\PageRenderer;
use WPEasy\ShipStatic\Support\Paths;
use WPEasy\ShipStatic\Sync\ChangeTracker;
use WPEasy\ShipStatic\Sync\Job;

defined('ABSPATH') || exit;

/**
 * The alternative to the relay ({@see Relay}) for Bricks query loops. Bricks
 * asks `…/bricks/v1/query_result` whenever a filter, sort, page size, search
 * or page changes (and for infinite scroll); the relay sends that to
 * WordPress, this sends it nowhere: a small script in the page answers it in
 * the browser from a JSON file built at Process time.
 *
 * - {@see scan_page()} (during the render) notes every loop that has Bricks
 *   filters, pagination or infinite scroll;
 * - {@see StaticFiltersBuilder} (a phase of the render job) builds one JSON
 *   file per loop from Bricks' own data, or says why it can't;
 * - {@see StaticFiltersReplacer} (at deploy) rewrites each file for the
 *   destination, adds the script, the files and every image the results show.
 *
 * It is a re-implementation of Bricks' filter logic (src-svelte/static-filters),
 * held to Bricks' own answers by scripts/static-filters. Anything it does not
 * reproduce exactly is refused per loop (see {@see unsupported()}) and that loop
 * keeps using the relay. docs/STATIC_FILTERS_PLAN.md has the evidence and the
 * caveats.
 */
final class StaticFilters {

    /** '1' / '0'; absent = off. */
    public const OPTION = 'shipstatic_static_filters';

    /** What the last render found: page path => query id => result (see {@see registry()}). */
    public const REGISTRY_OPTION = 'shipstatic_static_filters_loops';

    /** Most items one loop may have (filterable); more, and that loop stays on the relay. */
    public const MAX_ITEMS = 3000;

    /** Bricks versions (major.minor) the engine was verified against (filterable). */
    private const VERIFIED_BRICKS = ['2.4'];

    /** Request header our loopback requests send so the loop items carry their object id. */
    public const EXPORT_HEADER = 'X-ShipStatic-Export';

    /** Attribute the loop root gets in those requests. */
    public const ITEM_ATTRIBUTE = 'data-ss-id';

    /** Folder (in the cache, and in the export) holding the data files. */
    public const DIR = 'shipstatic-filters';

    /**
     * Hook in (always: the hooks themselves check the setting).
     */
    public static function init(): void {
        add_filter('bricks/element/render_attributes', [self::class, 'mark_item'], 10, 3);
    }

    /**
     * Whether the static pages answer Bricks' filter requests themselves.
     */
    public static function enabled(): bool {
        return get_option(self::OPTION, '0') === '1';
    }

    /**
     * Turn it on or off. The render gains/loses the data files, so a new
     * Process is needed.
     *
     * @param bool $on On.
     */
    public static function set_enabled(bool $on): void {
        if ($on === self::enabled()) {
            return;
        }
        update_option(self::OPTION, $on ? '1' : '0', false);
        ChangeTracker::mark_dirty();
    }

    /**
     * Whether the engine was checked against this Bricks version.
     */
    public static function bricks_verified(): bool {
        if (!defined('BRICKS_VERSION')) {
            return false;
        }
        $versions = (array) apply_filters('shipstatic_static_filters_bricks_versions', self::VERIFIED_BRICKS);
        $current  = implode('.', array_slice(explode('.', (string) BRICKS_VERSION), 0, 2));

        return in_array($current, array_map('strval', $versions), true);
    }

    /**
     * The folder the built data files are kept in (until the next render).
     */
    public static function cache_dir(): string {
        return Paths::cache_dir() . '/' . self::DIR;
    }

    /**
     * Give a loop item its object id, but only in our own export requests:
     * Bricks answers with one run of HTML for the whole page of results, and
     * the builder needs to cut it into items.
     *
     * @param array<string,array<string,mixed>> $attributes Element attributes.
     * @param string                            $key        Attribute group.
     * @param object                            $element    Bricks element.
     * @return array<string,array<string,mixed>>
     */
    public static function mark_item($attributes, $key, $element) {
        if ($key !== '_root' || !self::enabled() || !isset($_SERVER['HTTP_X_SHIPSTATIC_EXPORT']) || !PageRenderer::is_trusted_render_request()) {
            return $attributes;
        }
        // The header names the one loop being exported, so a loop inside one of its items is left alone.
        $target = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_SHIPSTATIC_EXPORT']));
        // (While Bricks renders an item it has cleared the element's own hasLoop flag, so the id and "inside the loop" decide.)
        if (($element->id ?? '') !== $target || !class_exists('\\Bricks\\Query') || !\Bricks\Query::is_looping()) {
            return $attributes;
        }
        $id = (int) \Bricks\Query::get_loop_object_id();
        if ($id > 0 && is_array($attributes)) {
            $attributes['_root'][self::ITEM_ATTRIBUTE] = (string) $id;
        }

        return $attributes;
    }

    /**
     * During a render: note the query loops on a page that need data files.
     * A loop qualifies when something talks to it — a Bricks filter,
     * pagination or infinite scroll. Nothing is fetched here.
     *
     * @param Job    $job      The render job.
     * @param string $relative Page path in the export.
     * @param string $url      Page address.
     * @param string $html     The page as WordPress sent it.
     */
    public static function scan_page(Job $job, string $relative, string $url, string $html): void {
        if (!self::enabled()) {
            return;
        }
        $job->data['sfPages'][$relative] = true; // Rendered now: its old entries are replaced even if it has no loops any more.
        if (strpos($html, 'brx-query-trail') === false) {
            return;
        }

        $data = self::bricks_data($html);
        $loops = [];

        // The loops: Bricks leaves a "trail" element after each, with the signed query it ran.
        if (preg_match_all('/<[a-z][a-z0-9]*\b[^>]*\bbrx-query-trail\b[^>]*>/i', $html, $trails)) {
            foreach ($trails[0] as $tag) {
                $qid  = self::attr($tag, 'data-query-element-id');
                $vars = self::attr($tag, 'data-query-vars');
                if ($qid === '' || $vars === '' || isset($loops[$qid])) {
                    continue;
                }
                $loops[$qid] = [
                    'qid'      => $qid,
                    'vars'     => $vars,
                    'infinite' => strpos($tag, 'brx-infinite-scroll') !== false,
                    'filters'  => [],
                ];
            }
        }
        if (!$loops) {
            return;
        }

        // The filter elements pointing at each loop (and what kind they are).
        if (preg_match_all('/\bdata-brx-filter="([^"]*)"/', $html, $found)) {
            foreach ($found[1] as $json) {
                $f = json_decode(html_entity_decode($json, ENT_QUOTES), true);
                if (is_array($f) && isset($f['filterId'], $f['targetQueryId'], $loops[(string) $f['targetQueryId']])) {
                    $loops[(string) $f['targetQueryId']]['filters'][(string) $f['filterId']] = (string) ($f['filterType'] ?? '');
                }
            }
        }

        foreach ($loops as $loop) {
            if (!$loop['filters'] && !$loop['infinite']) {
                continue;
            }
            $job->data['sfQueue'][] = [
                'rel'     => $relative,
                'url'     => $url,
                'qid'     => $loop['qid'],
                'vars'    => $loop['vars'],
                'filters' => $loop['filters'],
                'post'    => (int) ($data['queryContextId'] ?? $data['postId'] ?? 0),
                'main'    => (string) ($data['mainQueryId'] ?? ''),
                'pageF'   => !empty($data['pageFilters']),
            ];
        }
    }

    /**
     * The page's `bricksData` object.
     *
     * @param string $html Page HTML.
     * @return array<string,mixed>
     */
    private static function bricks_data(string $html): array {
        if (!preg_match('/var bricksData\s*=\s*(\{.*?\});\s*(?:<\/script>|\n)/s', $html, $m)) {
            return [];
        }
        $data = json_decode($m[1], true);

        return is_array($data) ? $data : [];
    }

    /**
     * One attribute of an HTML tag (decoded), or ''.
     *
     * @param string $tag  Opening tag.
     * @param string $name Attribute name.
     */
    private static function attr(string $tag, string $name): string {
        return preg_match('/\s' . preg_quote($name, '/') . '="([^"]*)"/', $tag, $m) ? html_entity_decode($m[1], ENT_QUOTES) : '';
    }

    /**
     * What the last render found, by page: query id => {id, ok, reason, items}.
     *
     * @return array<string,array<string,array<string,mixed>>>
     */
    public static function registry(): array {
        $reg = get_option(self::REGISTRY_OPTION, []);

        return is_array($reg) ? $reg : [];
    }

    /**
     * Store what a render found. A full render replaces the registry; a
     * single-page or partial one replaces just the pages it rendered.
     *
     * @param array<string,array<string,array<string,mixed>>> $found   Page path => loops.
     * @param bool                                            $partial Whether other pages keep their entries.
     * @param array<int,string>                               $pages   Pages this render covered (partial only).
     */
    public static function save_registry(array $found, bool $partial, array $pages = []): void {
        $reg = $partial ? self::registry() : [];
        foreach ($pages as $page) {
            unset($reg[$page]);
        }
        foreach ($found as $page => $loops) {
            $reg[$page] = $loops;
        }
        ksort($reg);
        update_option(self::REGISTRY_OPTION, $reg, false);
        delete_transient('shipstatic_static_filters_sig');
    }

    /**
     * At the end of a render: keep what it found and drop data files nothing
     * refers to any more.
     *
     * @param Job $job The render job.
     */
    public static function commit(Job $job): void {
        if (!self::enabled()) {
            self::save_registry([], false);
        } else {
            self::save_registry((array) ($job->data['sfDone'] ?? []), !empty($job->data['singlePage']), array_keys((array) ($job->data['sfPages'] ?? [])));
        }

        $keep = [];
        foreach (self::registry() as $loops) {
            foreach ($loops as $loop) {
                if (!empty($loop['id'])) {
                    $keep[(string) $loop['id'] . '.json'] = true;
                }
            }
        }
        foreach ((array) glob(self::cache_dir() . '/*') as $file) {
            if (is_file((string) $file) && !isset($keep[basename((string) $file)])) {
                wp_delete_file((string) $file);
            }
        }
    }

    /**
     * A summary for the dashboard and WP-CLI.
     *
     * @return array{enabled:bool,verified:bool,bricks:string,loops:int,static:int,relay:int,list:array<int,array<string,mixed>>}
     */
    public static function report(): array {
        $list = [];
        foreach (self::registry() as $page => $loops) {
            foreach ($loops as $qid => $loop) {
                $list[] = [
                    'page'   => (string) $page,
                    'query'  => (string) $qid,
                    'static' => !empty($loop['ok']),
                    'reason' => (string) ($loop['reason'] ?? ''),
                    'items'  => (int) ($loop['items'] ?? 0),
                ];
            }
        }
        $static = count(array_filter($list, static fn(array $l): bool => $l['static']));

        return [
            'enabled'  => self::enabled(),
            'verified' => self::bricks_verified(),
            'bricks'   => defined('BRICKS_VERSION') ? (string) BRICKS_VERSION : '',
            'loops'    => count($list),
            'static'   => $static,
            'relay'    => count($list) - $static,
            'list'     => $list,
        ];
    }

    /**
     * Why a loop can't be answered statically, or null when it can. These are
     * the cases the differential tests do not cover or Bricks decides in ways
     * the data files can't carry; each stays on the relay.
     *
     * @param array<string,mixed>                $loop     The queued loop (scan_page()).
     * @param array<string,mixed>                $vars     The loop's query variables.
     * @param array<string,array<string,mixed>>  $elements Filter element id => {type, action, settings}.
     * @return string|null Reason (plain English), or null.
     */
    public static function unsupported(array $loop, array $vars, array $elements): ?string {
        if (!class_exists('\\Bricks\\Query_Filters') || !defined('BRICKS_VERSION')) {
            return 'Bricks is not active';
        }
        if (!self::bricks_verified()) {
            return 'Bricks ' . BRICKS_VERSION . ' has not been checked with static filters';
        }
        if (!empty($loop['main'])) {
            return 'The loop is the page’s main query';
        }
        if (!empty($loop['pageF'])) {
            return 'The page uses page filters';
        }
        if (isset($vars['taxonomies']) || isset($vars['role']) || isset($vars['role__in']) || isset($vars['hide_empty']) || isset($vars['include']) || (isset($vars['number']) && !isset($vars['posts_per_page']))) {
            return 'Term and user loops are not supported';
        }
        if (!isset($vars['posts_per_page']) || (int) $vars['posts_per_page'] < 1) {
            return 'The loop has no page size';
        }
        if (!empty($vars['s'])) {
            return 'The loop is a search results loop';
        }
        if (!empty($vars['offset'])) {
            return 'The loop uses an offset';
        }
        $orderby = is_array($vars['orderby'] ?? null) ? implode(' ', array_keys($vars['orderby'])) : (string) ($vars['orderby'] ?? '');
        if (stripos($orderby, 'rand') !== false || stripos($orderby, 'relevance') !== false) {
            return 'Random or relevance order cannot be reproduced';
        }

        foreach ((array) $loop['filters'] as $id => $type) {
            $el = $elements[$id] ?? null;
            if ($type === 'pagination' || $type === 'active-filters') {
                continue;
            }
            if ($el === null) {
                return 'Filter ' . $id . ' is not in the Bricks index';
            }
            $why = self::unsupported_filter($el);
            if ($why !== null) {
                return $why;
            }
        }

        return null;
    }

    /**
     * Why one filter element isn't supported, or null.
     *
     * @param array<string,mixed> $el {type, action, settings}.
     */
    private static function unsupported_filter(array $el): ?string {
        $type = (string) $el['type'];
        $s    = (array) $el['settings'];

        if (in_array($type, ['filter-submit', 'filter-reset', 'filter-apply'], true)) {
            return null; // Buttons: they only change what the other filters send.
        }
        $action = (string) ($el['action'] ?: 'filter');
        if (!in_array($type, ['filter-checkbox', 'filter-radio', 'filter-select', 'filter-search', 'filter-range', 'filter-datepicker', 'filter-active-filters'], true)) {
            return 'The filter type ' . $type . ' is not supported';
        }
        if (!in_array($action, ['filter', 'sort', 'per_page'], true)) {
            return 'The filter action ' . $action . ' is not supported';
        }

        if ($action === 'sort') {
            foreach ((array) ($s['sortOptions'] ?? []) as $o) {
                if (!in_array((string) ($o['optionSource'] ?? ''), ['title', 'post_date', 'meta_value_num', 'meta_value'], true)) {
                    return 'Sorting by ' . (string) ($o['optionSource'] ?? '?') . ' is not supported';
                }
            }

            return null;
        }
        if ($action === 'per_page' || $type === 'filter-active-filters') {
            return null;
        }

        $source = (string) ($s['filterSource'] ?? '');
        if (($s['sourceFieldType'] ?? 'post') !== 'post') {
            return 'Filters on terms or users are not supported';
        }
        switch ($type) {
            case 'filter-search':
                return null;
            case 'filter-range':
                $compare = (string) ($s['fieldCompareType'] ?? '');

                return $source === 'customField' && ($compare === '' || $compare === 'number') ? null : 'This range filter is not supported';
            case 'filter-datepicker':
                $field = (string) ($s['wpPostField'] ?? 'post_date');

                return $source === 'wpField' && ($field === '' || $field === 'post_date') && empty($s['enableTime']) ? null : 'Only date pickers on the post date, without a time, are supported';
        }

        // Checkbox, radio, select.
        if (isset($s['fieldCompareOperator']) && !in_array((string) $s['fieldCompareOperator'], ['IN', '=', ''], true)) {
            return 'A custom compare operator is not supported';
        }
        if ($source === 'taxonomy') {
            return null;
        }
        if ($source === 'customField') {
            return empty($s['fieldCompareType']) ? null : 'A typed custom-field comparison is not supported';
        }
        if ($source === 'wpField') {
            return (string) ($s['wpPostField'] ?? '') === 'post_author' ? null : 'Only the post author is supported as a WordPress field';
        }

        return 'The filter source ' . ($source !== '' ? $source : '(none)') . ' is not supported';
    }

    /**
     * A key for what the data files contain, for the deploy signature: a
     * destination reads "out of sync" when the files or the script change.
     */
    public static function signature(): string {
        $cached = get_transient('shipstatic_static_filters_sig');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $parts = [];
        foreach (self::registry() as $page => $loops) {
            foreach ($loops as $qid => $loop) {
                $parts[] = $page . '|' . $qid . '|' . (string) ($loop['hash'] ?? '') . '|' . (empty($loop['ok']) ? '0' : '1');
            }
        }
        $sig = md5(implode("\n", $parts));
        set_transient('shipstatic_static_filters_sig', $sig, DAY_IN_SECONDS);

        return $sig;
    }
}
