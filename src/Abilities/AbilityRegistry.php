<?php
/**
 * WordPress Abilities API exposure — lets MCP/AI clients discover and (opt-in)
 * drive the static-sync engine.
 *
 * This plugin does NOT ship an MCP server. It registers a set of *abilities*
 * into WordPress core's Abilities API (`wp_register_ability()`, WP 6.9+); MCP
 * feature plugins (the official WordPress MCP plugin, Novamira, …) discover and
 * expose those abilities to AI agents. We are purely the producer.
 *
 * Security model (mirrors the rest of the plugin's REST surface):
 *  - Presence-gated: nothing happens unless `wp_register_ability()` exists.
 *  - Every ability's `permission_callback` requires `manage_options`.
 *  - Read-only abilities are always registered.
 *  - State-changing abilities are registered ONLY when their opt-in toggle is on
 *    (default off), AND re-check the toggle in the permission callback
 *    (defence in depth — registration and execution are both gated):
 *      • `shipstatic_ai_allow_changes` → local config/meta edits (discovery mode, the
 *        per-post Include switch, the include cascade).
 *      • `shipstatic_ai_allow_sync`    → anything that runs the engine or touches a
 *        remote (scan, full/single-page sync, reset, cancel).
 *  - No ability returns secrets/credentials/PII (destinations are reported by
 *    name + state only).
 *
 * @package WPEasy\ShipStatic
 * @since   1.0.2
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Abilities;

use WP_Error;
use WPEasy\ShipStatic\CLI\Background;
use WPEasy\ShipStatic\Discovery\UrlCollector;
use WPEasy\ShipStatic\Render\AssetExtractor;
use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Support\Environment;
use WPEasy\ShipStatic\Support\Text;
use WPEasy\ShipStatic\Support\Url;
use WPEasy\ShipStatic\Sync\LinkGraph;
use WPEasy\ShipStatic\Sync\Manifest;
use WPEasy\ShipStatic\Sync\MethodResolver;
use WPEasy\ShipStatic\Sync\Runner;

defined('ABSPATH') || exit;

/**
 * Registers the plugin's abilities (and their category) into the core Abilities
 * API. All execute callbacks delegate to existing services so the AI surface and
 * the REST/dashboard surface can never drift.
 */
final class AbilityRegistry {

    /**
     * Ability namespace prefix (also the category slug).
     */
    private const NS = 'shipstatic';

    /**
     * Option enabling the local-change abilities (discovery mode, include).
     */
    public const OPT_CHANGES = 'shipstatic_ai_allow_changes';

    /**
     * Option enabling the engine/remote abilities (scan, sync, reset, cancel).
     */
    public const OPT_SYNC = 'shipstatic_ai_allow_sync';

    /**
     * Hook registration. No-op when the Abilities API isn't present, so the
     * plugin runs unchanged on WordPress < 6.9.
     */
    public static function init(): void {
        if (!function_exists('wp_register_ability')) {
            return;
        }
        add_action('wp_abilities_api_categories_init', [self::class, 'register_category']);
        add_action('wp_abilities_api_init', [self::class, 'register_abilities']);
    }

    /**
     * Whether the local-change abilities are opted in.
     */
    public static function changes_enabled(): bool {
        return (bool) get_option(self::OPT_CHANGES, false);
    }

    /**
     * Whether the engine/remote abilities are opted in.
     */
    public static function sync_enabled(): bool {
        return (bool) get_option(self::OPT_SYNC, false);
    }

    /**
     * Register the single category these abilities live under.
     */
    public static function register_category(): void {
        if (!function_exists('wp_register_ability_category')) {
            return; // Abilities API (WordPress 6.9+) not present.
        }
        wp_register_ability_category(self::NS, [
            'label'       => __('ShipStatic', 'shipstatic'),
            'description' => __('Inspect and drive the ShipStatic export/sync engine.', 'shipstatic'),
        ]);
    }

    /**
     * Register the abilities. Read-only ones always; the two action groups only
     * when their opt-in toggle is on.
     */
    public static function register_abilities(): void {
        // ---- Read-only (always on) -----------------------------------------
        self::register('get-status', [
            'label'           => __('Get sync status', 'shipstatic'),
            'description'     => __('Returns whether a destination is connected, whether anything has been pushed, and whether the static copy is in sync, plus the resolved sync method and discovery mode.', 'shipstatic'),
            'execute_callback' => [self::class, 'get_status'],
            'readonly'        => true,
        ]);
        self::register('get-method', [
            'label'           => __('Get sync method', 'shipstatic'),
            'description'     => __('Returns how a sync will run: discovery mode, transport, compression, server target and link style.', 'shipstatic'),
            'execute_callback' => [self::class, 'get_method'],
            'readonly'        => true,
        ]);
        self::register('list-pages', [
            'label'           => __('List exportable pages', 'shipstatic'),
            'description'     => __('Lists published, public posts/pages with their static-export state: whether each is included, whether it will actually export under the current plan, and whether it is currently synced.', 'shipstatic'),
            'execute_callback' => [self::class, 'list_pages'],
            'readonly'        => true,
        ]);
        self::register('get-page-status', [
            'label'           => __('Get page sync status', 'shipstatic'),
            'description'     => __('For one post, returns its include/effective/synced state and per-destination push state.', 'shipstatic'),
            'execute_callback' => [self::class, 'get_page_status'],
            'input_schema'    => self::post_id_schema(),
            'readonly'        => true,
        ]);
        self::register('check-page-links', [
            'label'           => __('Check page link integrity', 'shipstatic'),
            'description'     => __('For one post, reports internal links that point to pages NOT in the export (outbound) and currently-included pages that link TO it (inbound) — the data behind the manual-mode link-integrity warnings.', 'shipstatic'),
            'execute_callback' => [self::class, 'check_page_links'],
            'input_schema'    => self::post_id_schema(),
            'readonly'        => true,
        ]);
        self::register('list-destinations', [
            'label'           => __('List destinations', 'shipstatic'),
            'description'     => __('Lists the configured deploy destinations by name, transport type and enabled state. Never returns credentials.', 'shipstatic'),
            'execute_callback' => [self::class, 'list_destinations'],
            'readonly'        => true,
        ]);
        self::register('get-sync-progress', [
            'label'           => __('Get sync progress', 'shipstatic'),
            'description'     => __('Returns the current sync job snapshot (phase, counts, totals, recent errors) — useful for polling a run started elsewhere.', 'shipstatic'),
            'execute_callback' => [self::class, 'get_sync_progress'],
            'readonly'        => true,
        ]);

        // ---- Local changes (opt-in: shipstatic_ai_allow_changes) -------------------
        if (self::changes_enabled()) {
            self::register('set-discovery-mode', [
                'label'           => __('Set discovery mode', 'shipstatic'),
                'description'     => __('Sets how pages are discovered for export: "linked", "all" or "manual".', 'shipstatic'),
                'execute_callback' => [self::class, 'set_discovery_mode'],
                'permission'      => 'changes',
                'input_schema'    => [
                    'type'       => 'object',
                    'properties' => [
                        'mode' => [
                            'type'        => 'string',
                            'enum'        => ['linked', 'all', 'manual'],
                            'description' => __('The discovery mode to set.', 'shipstatic'),
                        ],
                    ],
                    'required'   => ['mode'],
                ],
                'idempotent'      => true,
            ]);
            self::register('set-page-include', [
                'label'           => __('Include or exclude a page', 'shipstatic'),
                'description'     => __('In manual discovery mode, sets whether a single post is included in the static export.', 'shipstatic'),
                'execute_callback' => [self::class, 'set_page_include'],
                'permission'      => 'changes',
                'input_schema'    => [
                    'type'       => 'object',
                    'properties' => [
                        'postId'  => ['type' => 'integer', 'description' => __('The post ID.', 'shipstatic')],
                        'include' => ['type' => 'boolean', 'description' => __('True to include, false to exclude.', 'shipstatic')],
                    ],
                    'required'   => ['postId', 'include'],
                ],
                'idempotent'      => true,
            ]);
            self::register('include-pages', [
                'label'           => __('Include multiple pages', 'shipstatic'),
                'description'     => __('In manual discovery mode, includes a batch of posts (e.g. the cascade when enabling a page that links to others).', 'shipstatic'),
                'execute_callback' => [self::class, 'include_pages'],
                'permission'      => 'changes',
                'input_schema'    => [
                    'type'       => 'object',
                    'properties' => [
                        'postIds' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'integer'],
                            'description' => __('The post IDs to include.', 'shipstatic'),
                        ],
                    ],
                    'required'   => ['postIds'],
                ],
                'idempotent'      => true,
            ]);
        }

        // ---- Engine / remote (opt-in: shipstatic_ai_allow_sync) --------------------
        if (self::sync_enabled()) {
            self::register('scan', [
                'label'           => __('Scan (dry run)', 'shipstatic'),
                'description'     => __('Starts a check run: renders the pages and reports what would change, without pushing anything to a destination.', 'shipstatic'),
                'execute_callback' => [self::class, 'scan'],
                'permission'      => 'sync',
                'idempotent'      => true,
            ]);
            self::register('sync', [
                'label'           => __('Sync', 'shipstatic'),
                'description'     => __('Starts a full sync: renders the pages and pushes the static copy to the destination(s).', 'shipstatic'),
                'execute_callback' => [self::class, 'sync'],
                'permission'      => 'sync',
            ]);
            self::register('sync-page', [
                'label'           => __('Sync a single page', 'shipstatic'),
                'description'     => __('Renders one page (and any new internal pages it links to) and pushes only the changed files to destinations opted into single-page sync.', 'shipstatic'),
                'execute_callback' => [self::class, 'sync_page'],
                'permission'      => 'sync',
                'input_schema'    => [
                    'type'       => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => __('The internal page URL to sync.', 'shipstatic')],
                    ],
                    'required'   => ['url'],
                ],
            ]);
            self::register('cancel-sync', [
                'label'           => __('Cancel sync', 'shipstatic'),
                'description'     => __('Requests cancellation of the running sync job.', 'shipstatic'),
                'execute_callback' => [self::class, 'cancel_sync'],
                'permission'      => 'sync',
                'idempotent'      => true,
            ]);
            self::register('reset-sync-state', [
                'label'           => __('Reset sync state', 'shipstatic'),
                'description'     => __('Clears the local record of what has been pushed so the next sync re-uploads everything. Destructive: does not delete remote files but forces a full re-upload.', 'shipstatic'),
                'execute_callback' => [self::class, 'reset_sync_state'],
                'permission'      => 'sync',
                'destructive'     => true,
            ]);
        }
    }

    // ====================================================================
    // Registration helper
    // ====================================================================

    /**
     * Register one ability with the shared permission model and annotation
     * defaults.
     *
     * @param string              $name Bare ability name (namespaced internally).
     * @param array<string,mixed> $spec {
     *     @type string   $label
     *     @type string   $description
     *     @type callable $execute_callback
     *     @type array    $input_schema  Optional JSON Schema.
     *     @type string   $permission    Optional 'changes'|'sync' opt-in re-check (default none).
     *     @type bool     $readonly      Optional annotation (default false).
     *     @type bool     $destructive   Optional annotation (default false).
     *     @type bool     $idempotent    Optional annotation (default false).
     * }
     */
    private static function register(string $name, array $spec): void {
        $permission = $spec['permission'] ?? null;

        $args = [
            'label'               => (string) $spec['label'],
            'description'         => (string) $spec['description'],
            'category'            => self::NS,
            'execute_callback'    => $spec['execute_callback'],
            'permission_callback' => static function () use ($permission) {
                if (!current_user_can('manage_options')) {
                    return false;
                }
                // Defence in depth: action abilities re-check their opt-in toggle
                // even though they aren't registered while it's off.
                if ($permission === 'changes' && !self::changes_enabled()) {
                    return false;
                }
                if ($permission === 'sync' && !self::sync_enabled()) {
                    return false;
                }
                return true;
            },
            'meta'                => [
                'annotations'  => [
                    'readonly'    => !empty($spec['readonly']),
                    'destructive' => !empty($spec['destructive']),
                    'idempotent'  => !empty($spec['idempotent']),
                ],
                'show_in_rest' => true,
            ],
        ];

        if (isset($spec['input_schema'])) {
            $args['input_schema'] = $spec['input_schema'];
        }

        wp_register_ability(self::NS . '/' . $name, $args);
    }

    /**
     * Shared input schema: a single required integer `postId`.
     *
     * @return array<string,mixed>
     */
    private static function post_id_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'postId' => ['type' => 'integer', 'description' => __('The post ID.', 'shipstatic')],
            ],
            'required'   => ['postId'],
        ];
    }

    // ====================================================================
    // Read-only execute callbacks
    // ====================================================================

    /**
     * @return array<string,mixed>
     */
    public static function get_status(): array {
        $state   = get_option('shipstatic_connection_state', []);
        $primary = Destinations::primary();
        $pushed  = Manifest::load(Destinations::pushed_option($primary->id()));

        $has_pushed = !empty($pushed);

        return [
            'connected'     => is_array($state) && !empty($state['ok']),
            'hasPushed'     => $has_pushed,
            'inSync'        => $has_pushed && Runner::in_sync($primary->id(), $primary),
            'discoveryMode' => UrlCollector::mode(),
            'method'        => MethodResolver::resolve(),
            'isLocal'       => Environment::is_local(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_method(): array {
        return MethodResolver::resolve();
    }

    /**
     * Every published public post with its export state.
     *
     * @return array<string,mixed>
     */
    public static function list_pages(): array {
        $types = UrlCollector::public_post_types();
        $ids   = $types
            ? get_posts([
                'post_type'        => $types,
                'post_status'      => 'publish',
                'posts_per_page'   => -1,
                'fields'           => 'ids',
                'no_found_rows'    => true,
                'suppress_filters' => false,
            ])
            : [];

        $mode      = UrlCollector::mode();
        $primary   = Destinations::primary();
        $pushed    = Manifest::load(Destinations::pushed_option($primary->id()));
        $render    = Manifest::load(Manifest::RENDER_OPTION);

        $pages = [];
        foreach ($ids as $pid) {
            $pid = (int) $pid;
            $rel = Url::to_relative_path((string) get_permalink($pid));

            if ($mode === 'manual') {
                $included = UrlCollector::is_included($pid);
            } elseif ($mode === 'all') {
                $included = true;
            } else { // linked — included if the last crawl reached it
                $included = $rel !== null && isset($render[$rel]);
            }

            $pages[] = [
                'postId'    => $pid,
                'title'     => Text::title($pid),
                'url'       => (string) get_permalink($pid),
                'type'      => (string) get_post_type($pid),
                'included'  => $included,
                'effective' => UrlCollector::is_effective($pid),
                'synced'    => $rel !== null && isset($pushed[$rel]),
            ];
        }

        return [
            'mode'          => $mode,
            'includedCount' => UrlCollector::effective_count(),
            'savedCount'    => UrlCollector::included_count(),
            'pages'         => $pages,
        ];
    }

    /**
     * @param array<string,mixed>|mixed $input { postId }.
     * @return array<string,mixed>|WP_Error
     */
    public static function get_page_status($input) {
        $post_id = (int) (is_array($input) ? ($input['postId'] ?? 0) : 0);
        $post    = $post_id > 0 ? get_post($post_id) : null;
        if ($post === null) {
            return new WP_Error('shipstatic_invalid_post', __('A valid post ID is required.', 'shipstatic'));
        }

        $rel     = Url::to_relative_path((string) get_permalink($post_id));
        $targets = [];
        foreach (Destinations::visible_objects() as $dest) {
            if (!(bool) $dest->get('enabled')) {
                continue;
            }
            $pushed    = Manifest::load(Destinations::pushed_option($dest->id()));
            $targets[] = [
                'id'     => $dest->id(),
                'name'   => (string) $dest->get('name'),
                'pushed' => $rel !== null && isset($pushed[$rel]),
            ];
        }

        return [
            'postId'    => $post_id,
            'title'     => Text::title($post_id),
            'url'       => (string) get_permalink($post_id),
            'published' => $post->post_status === 'publish',
            'included'  => UrlCollector::is_included($post_id),
            'effective' => UrlCollector::is_effective($post_id),
            'targets'   => $targets,
        ];
    }

    /**
     * @param array<string,mixed>|mixed $input { postId }.
     * @return array<string,mixed>|WP_Error
     */
    public static function check_page_links($input) {
        $post_id = (int) (is_array($input) ? ($input['postId'] ?? 0) : 0);
        if ($post_id <= 0 || get_post($post_id) === null) {
            return new WP_Error('shipstatic_invalid_post', __('A valid post ID is required.', 'shipstatic'));
        }

        // Outbound: links from this page to pages not in the export.
        $outbound = [];
        $busy     = false;
        $url      = (string) get_permalink($post_id);
        $html     = $url !== '' ? self::page_html($url) : null;
        if ($html === null) {
            $busy = true; // no cached copy and rendering wasn't possible right now
        } else {
            $public = UrlCollector::public_post_types();
            $seen   = [];
            foreach (AssetExtractor::extract_links($html, $url) as $link) {
                $pid = Url::to_post_id($link);
                if ($pid <= 0 || $pid === $post_id || isset($seen[$pid])) {
                    continue;
                }
                $p = get_post($pid);
                if ($p === null || $p->post_status !== 'publish' || !in_array($p->post_type, $public, true)) {
                    continue;
                }
                if (UrlCollector::is_effective($pid)) {
                    continue;
                }
                $seen[$pid] = true;
                $outbound[] = ['postId' => $pid, 'title' => Text::title($pid)];
            }
        }

        // Inbound: currently-included pages that link to this one (last scan).
        $inbound = [];
        $rel     = Url::to_relative_path($url);
        if ($rel !== null) {
            foreach (LinkGraph::inbound_post_ids($rel) as $pid) {
                if ($pid === $post_id || !UrlCollector::is_effective($pid)) {
                    continue;
                }
                $p = get_post($pid);
                if ($p === null || $p->post_status !== 'publish') {
                    continue;
                }
                $inbound[] = ['postId' => $pid, 'title' => Text::title($pid)];
            }
        }

        return [
            'postId'           => $post_id,
            'outboundExcluded' => $outbound,
            'inboundIncluded'  => $inbound,
            'outboundBusy'     => $busy,
            'inboundNote'      => __('Inbound links reflect the most recent scan/sync.', 'shipstatic'),
        ];
    }

    /**
     * Destinations by name + state. Never returns credentials.
     *
     * @return array<string,mixed>
     */
    public static function list_destinations(): array {
        $list = [];
        foreach (Destinations::visible_objects() as $dest) {
            $list[] = [
                'id'        => $dest->id(),
                'name'      => (string) $dest->get('name'),
                'transport' => (string) $dest->get('transport'),
                'enabled'   => (bool) $dest->get('enabled'),
            ];
        }

        return [
            'destinations' => $list,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_sync_progress(): array {
        return Runner::status();
    }

    // ====================================================================
    // Local-change execute callbacks (shipstatic_ai_allow_changes)
    // ====================================================================

    /**
     * @param array<string,mixed>|mixed $input { mode }.
     * @return array<string,mixed>
     */
    public static function set_discovery_mode($input): array {
        $mode = is_array($input) ? (string) ($input['mode'] ?? '') : '';
        UrlCollector::set_mode($mode);

        return ['discoveryMode' => UrlCollector::mode()];
    }

    /**
     * @param array<string,mixed>|mixed $input { postId, include }.
     * @return array<string,mixed>|WP_Error
     */
    public static function set_page_include($input) {
        $post_id = (int) (is_array($input) ? ($input['postId'] ?? 0) : 0);
        $include = is_array($input) ? !empty($input['include']) : false;

        if ($post_id <= 0 || get_post($post_id) === null) {
            return new WP_Error('shipstatic_invalid_post', __('A valid post ID is required.', 'shipstatic'));
        }
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('shipstatic_forbidden', __('You cannot edit this post.', 'shipstatic'));
        }

        update_post_meta($post_id, UrlCollector::INCLUDE_META, $include ? '1' : '0');

        return self::include_payload($include);
    }

    /**
     * @param array<string,mixed>|mixed $input { postIds }.
     * @return array<string,mixed>
     */
    public static function include_pages($input): array {
        $ids = is_array($input) ? array_map('intval', (array) ($input['postIds'] ?? [])) : [];

        $included = [];
        $skipped  = [];
        foreach ($ids as $pid) {
            if ($pid <= 0 || get_post($pid) === null || !current_user_can('edit_post', $pid)) {
                continue;
            }
            if (UrlCollector::is_included($pid)) {
                continue;
            }
            update_post_meta($pid, UrlCollector::INCLUDE_META, '1');
            $included[] = $pid;
        }

        return self::include_payload(true) + ['included' => $included, 'skipped' => $skipped];
    }

    // ====================================================================
    // Engine / remote execute callbacks (shipstatic_ai_allow_sync)
    // ====================================================================

    /**
     * @return array<string,mixed>
     */
    public static function scan(): array {
        return self::start_run('check', []);
    }

    /**
     * @return array<string,mixed>
     */
    public static function sync(): array {
        $options = [];
        $enabled = Destinations::enabled_ids();
        if (!empty($enabled)) {
            $options['targets'] = $enabled;
        }

        return self::start_run('sync', $options);
    }

    /**
     * @param array<string,mixed>|mixed $input { url }.
     * @return array<string,mixed>|WP_Error
     */
    public static function sync_page($input) {
        $url = is_array($input) ? esc_url_raw((string) ($input['url'] ?? '')) : '';
        if ($url === '' || !Url::is_internal($url)) {
            return new WP_Error('shipstatic_invalid_url', __('A valid internal page URL is required.', 'shipstatic'));
        }
        if (empty(Destinations::single_page_ids())) {
            return new WP_Error('shipstatic_no_targets', __('No destinations are enabled for single-page sync.', 'shipstatic'));
        }

        return self::start_run('sync', ['only' => $url]);
    }

    /**
     * @return array<string,mixed>
     */
    public static function cancel_sync(): array {
        Runner::cancel();

        return Runner::status();
    }

    /**
     * @return array<string,mixed>
     */
    public static function reset_sync_state(): array {
        \WPEasy\ShipStatic\Sync\Job::clear();
        foreach (Destinations::all() as $dest) {
            $id = (string) ($dest['id'] ?? '');
            delete_option(Destinations::pushed_option($id));
            delete_option('shipstatic_pkg_off_' . $id);
            delete_option(Runner::SYNC_SIG . $id);
        }
        delete_option(Manifest::RENDER_OPTION);
        \WPEasy\ShipStatic\Transport\SftpTransport::forget_host_key();

        return ['ok' => true];
    }

    // ====================================================================
    // Helpers
    // ====================================================================

    /**
     * Start a run and attach the chosen driver, surfacing failures as the engine
     * does (an `error` phase rather than a thrown exception).
     *
     * @param string              $type    'check' | 'sync'.
     * @param array<string,mixed> $options Run options.
     * @return array<string,mixed>
     */
    private static function start_run(string $type, array $options): array {
        try {
            $snapshot           = Runner::start($type, $options);
            $snapshot['driver'] = Background::spawn_run() ? 'cli' : 'browser';

            return $snapshot;
        } catch (\Throwable $e) {
            return ['phase' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * The include count/cap payload shared by the include abilities.
     *
     * @param bool $included Whether the target is now included.
     * @return array<string,mixed>
     */
    private static function include_payload(bool $included): array {
        return [
            'included'      => $included,
            'includedCount' => UrlCollector::effective_count(),
            'savedCount'    => UrlCollector::included_count(),
        ];
    }

    /**
     * A page's HTML: cached render copy if present, else a one-off on-demand
     * render (skipped while a sync runs). Returns null if unobtainable. Mirrors
     * EditorController::page_html().
     *
     * @param string $url Page permalink.
     */
    private static function page_html(string $url): ?string {
        $rel      = Url::to_relative_path($url);
        $manifest = Manifest::load(Manifest::RENDER_OPTION);
        if ($rel !== null && isset($manifest[$rel]['src']) && is_file((string) $manifest[$rel]['src'])) {
            $html = file_get_contents((string) $manifest[$rel]['src']);
            if (is_string($html)) {
                return $html;
            }
        }

        $phase = Runner::status()['phase'] ?? 'idle';
        if (!in_array($phase, ['idle', 'done', 'error', 'cancelled'], true)) {
            return null;
        }

        try {
            $result = \WPEasy\ShipStatic\Render\PageRenderer::render($url);
            if ($result['code'] === 200 && stripos((string) $result['contentType'], 'html') !== false) {
                return (string) $result['body'];
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }
}
