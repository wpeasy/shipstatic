<?php
/**
 * "ShipStatic" post-editor metabox + list-table column.
 *
 * Only active when the discovery mode is `manual`. Adds an "Include in static
 * export" switch (per-post meta) plus a sync button + status to every public
 * post type's edit screen (block + classic), and a "Static" toggle column to the
 * Pages/Posts list table. Mirrors Frontend\Fab for the manifest read + ES-module
 * enqueue.
 *
 * @package WPEasy\ShipStatic
 * @since   1.0.2
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Admin;

use WPEasy\ShipStatic\Discovery\UrlCollector;
use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Support\Assets;
use WPEasy\ShipStatic\Support\I18n;
use WPEasy\ShipStatic\Support\Url;
use WPEasy\ShipStatic\Sync\Manifest;

defined('ABSPATH') || exit;

/**
 * Registers the manual-mode editor metabox, list column + their assets.
 */
final class Editor {

    /**
     * Script handle for the editor bundle (ES module).
     */
    private const SCRIPT_HANDLE = 'shipstatic-editor';

    /**
     * Manifest entry key (Vite input name).
     */
    private const ENTRY = 'src-svelte/editor/main.ts';

    /**
     * Cached last-render relative paths (rel => entry). Null until loaded.
     *
     * @var array<string,mixed>|null
     */
    private static ?array $render_rels = null;

    /**
     * Cached pushed manifests per enabled destination (dest id => rel => entry).
     *
     * @var array<string,array<string,mixed>>|null
     */
    private static ?array $pushed = null;

    /**
     * Cached enabled destination ids.
     *
     * @var array<int,string>|null
     */
    private static ?array $enabled_ids = null;

    /**
     * Register hooks — only while in `manual` discovery mode.
     */
    public static function init(): void {
        // The "Static" status column + its sync modal show in EVERY discovery
        // mode. Post types register on `init`, so wire columns from `admin_init`.
        add_action('admin_init', [self::class, 'register_columns']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_filter('script_loader_tag', [self::class, 'script_as_module'], 10, 2);

        // The per-post "Include" switch (metabox, Quick Edit, the column's
        // check icon) is only meaningful in manual mode.
        if (UrlCollector::mode() === 'manual') {
            add_action('add_meta_boxes', [self::class, 'register_metabox']);
            add_action('quick_edit_custom_box', [self::class, 'render_quick_edit'], 10, 2);
            add_action('save_post', [self::class, 'save_quick_edit'], 10, 2);
        }
    }

    /**
     * Add the metabox to every public (exportable) post type.
     */
    public static function register_metabox(): void {
        if (!current_user_can('manage_options')) {
            return; // Admin-only, consistent with the rest of the plugin.
        }
        foreach (UrlCollector::public_post_types() as $type) {
            add_meta_box(
                'shipstatic',
                __('ShipStatic', 'shipstatic'),
                [self::class, 'render'],
                $type,
                'side'
            );
        }
    }

    /**
     * Metabox body — just the Svelte mount target (the app reads its context from
     * window.shipStaticEditorData).
     */
    public static function render(): void {
        echo '<div class="bs"><div id="bs-editor-root"></div></div>';
    }

    /**
     * Quick Edit: an "Include in static export" checkbox (manual mode). Core
     * prints one Quick Edit form per table, so the editor bundle ticks it from
     * the row's cell when Quick Edit opens.
     *
     * @param string $column    Column key.
     * @param string $post_type Post type.
     */
    public static function render_quick_edit(string $column, string $post_type): void {
        if ($column !== 'shipstatic_static' || !current_user_can('manage_options')
            || !in_array($post_type, UrlCollector::public_post_types(), true)) {
            return;
        }
        echo '<fieldset class="inline-edit-col-right bs-quick-edit"><div class="inline-edit-col"><div class="inline-edit-group wp-clearfix">'
            . '<input type="hidden" name="shipstatic_quick_edit" value="1">'
            . '<label class="alignleft"><input type="checkbox" name="shipstatic_include" value="1"> '
            . '<span class="checkbox-title">' . esc_html__('Include in static export', 'shipstatic') . '</span></label>'
            . '</div></div></fieldset>';
    }

    /**
     * Save the Quick Edit checkbox. Only for core's inline-save request (whose
     * nonce core has already checked; checked again here), and only when the
     * form carried our field, so other saves never touch the setting.
     *
     * @param int      $post_id Post id.
     * @param \WP_Post $post    Post.
     */
    public static function save_quick_edit(int $post_id, \WP_Post $post): void {
        if (!wp_doing_ajax() || !isset($_POST['shipstatic_quick_edit'], $_POST['_inline_edit'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_inline_edit'])), 'inlineeditnonce')) {
            return;
        }
        if (wp_is_post_revision($post_id) || !current_user_can('manage_options') || !current_user_can('edit_post', $post_id)
            || !in_array($post->post_type, UrlCollector::public_post_types(), true)) {
            return;
        }
        update_post_meta($post_id, UrlCollector::INCLUDE_META, isset($_POST['shipstatic_include']) ? '1' : '0');
    }

    /**
     * Add the "Static" toggle column to every exportable post type's list table.
     */
    public static function register_columns(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        foreach (UrlCollector::public_post_types() as $type) {
            add_filter("manage_edit-{$type}_columns", [self::class, 'add_column']);
            add_action("manage_{$type}_posts_custom_column", [self::class, 'render_column'], 10, 2);
        }
    }

    /**
     * Append the "Static" column header.
     *
     * @param array<string,string> $columns Existing columns.
     * @return array<string,string>
     */
    public static function add_column(array $columns): array {
        $columns['shipstatic_static'] = __('Static', 'shipstatic');

        return $columns;
    }

    /**
     * Render the per-row "Static" status cell — the whole cell is a button that
     * opens the sync modal (wired client-side by the editor bundle).
     *
     * @param string $column  Column key.
     * @param int    $post_id Post id.
     */
    public static function render_column(string $column, int $post_id): void {
        if ($column !== 'shipstatic_static') {
            return;
        }

        $st = self::page_state($post_id);
        if ($st['state'] === 'na') {
            echo '<span class="bs-static-na" aria-hidden="true">—</span>';
            return;
        }

        // Manual mode: a check icon includes/excludes the page in one click
        // (grey = not in the export, green = in it); the status button beside
        // it shows only while the page is included.
        $toggle = '';
        if (UrlCollector::mode() === 'manual') {
            $in     = $st['state'] === 'included';
            $toggle = sprintf(
                '<button type="button" class="bs-static-inc" data-post="%1$d" aria-pressed="%2$s" aria-label="%3$s"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></button>',
                (int) $post_id,
                $in ? 'true' : 'false',
                esc_attr($in ? __('In the static export — click to remove', 'shipstatic') : __('Not in the static export — click to include', 'shipstatic'))
            );
        }

        $labels = [
            'included' => $st['synced']
                ? __('In sync — open to re-sync', 'shipstatic')
                : __('Out of date — open to sync', 'shipstatic'),
            'excluded' => __('Not in the static export — open to manage', 'shipstatic'),
            'unknown'  => __('Run a check or sync to see status', 'shipstatic'),
        ];

        $inner = match ($st['state']) {
            'excluded'  => '<span class="bs-static-x" aria-hidden="true">✕</span>',
            'unknown'   => '<span class="bs-static-q" aria-hidden="true">?</span>',
            default     => '<span class="bs-static-dot bs-static-dot--' . ($st['synced'] ? 'ok' : 'warn') . '" aria-hidden="true"></span>'
                          . '<span class="bs-static-ico" aria-hidden="true">⟳</span>',
        };

        if ($toggle !== '') {
            echo '<span class="bs-static-wrap">' . $toggle; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with esc_attr()/int casts.
        }
        printf(
            '<button type="button" class="bs-static-cell" data-post="%1$d" data-url="%2$s" data-included="%3$s" data-state="%4$s" data-synced="%5$s" aria-label="%6$s">%7$s</button>',
            (int) $post_id,
            esc_url((string) get_permalink($post_id)),
            self::is_logically_included($post_id) ? '1' : '0',
            esc_attr($st['state']),
            $st['synced'] ? '1' : '0',
            esc_attr($labels[$st['state']] ?? ''),
            $inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup from the match() above; no variable data.
        );
        if ($toggle !== '') {
            echo '</span>';
        }
    }

    /**
     * The per-post export state for the list column.
     *
     * @param int $post_id Post id.
     * @return array{state:string,synced:bool} state ∈ included|excluded|unknown|na.
     */
    public static function page_state(int $post_id): array {
        if (get_post_status($post_id) !== 'publish') {
            return ['state' => 'na', 'synced' => false];
        }

        self::load_manifests();
        $mode = UrlCollector::mode();
        $rel  = Url::to_relative_path((string) get_permalink($post_id));

        if ($mode === 'manual') {
            if (!UrlCollector::is_included($post_id)) {
                return ['state' => 'excluded', 'synced' => false];
            }
            return ['state' => 'included', 'synced' => self::is_synced($rel)];
        }

        // all / linked
        if ($mode === 'linked' && empty(self::$render_rels)) {
            return ['state' => 'unknown', 'synced' => false]; // no scan run yet
        }
        if (!self::is_logically_included($post_id, $rel)) {
            return ['state' => 'excluded', 'synced' => false];
        }

        return ['state' => 'included', 'synced' => self::is_synced($rel)];
    }

    /**
     * Whether a relative path is present in every enabled destination's pushed
     * manifest (i.e. fully deployed).
     *
     * @param string|null $rel Relative path.
     */
    private static function is_synced(?string $rel): bool {
        if ($rel === null || empty(self::$enabled_ids)) {
            return false;
        }
        foreach (self::$enabled_ids as $id) {
            if (!isset(self::$pushed[$id][$rel])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a published post matches the active discovery rules (manual switch
     * / all-published / linked crawl result).
     *
     * @param int         $post_id Post id.
     * @param string|null $rel     Pre-computed relative path (optional).
     */
    private static function is_logically_included(int $post_id, ?string $rel = null): bool {
        $mode = UrlCollector::mode();
        if ($mode === 'manual') {
            return UrlCollector::is_included($post_id);
        }
        if ($mode === 'all') {
            return true;
        }
        // linked — included if the last crawl reached it.
        self::load_manifests();
        $rel = $rel ?? Url::to_relative_path((string) get_permalink($post_id));

        return $rel !== null && isset(self::$render_rels[$rel]);
    }

    /**
     * Cached render manifest + per-enabled-destination pushed manifests, loaded
     * once per request.
     */
    private static function load_manifests(): void {
        if (self::$render_rels !== null) {
            return;
        }
        self::$render_rels = Manifest::load(Manifest::RENDER_OPTION);
        self::$pushed      = [];
        self::$enabled_ids = [];
        foreach (Destinations::visible_objects() as $dest) {
            if (!(bool) $dest->get('enabled')) {
                continue;
            }
            $id                     = $dest->id();
            self::$enabled_ids[]    = $id;
            self::$pushed[$id]      = Manifest::load(Destinations::pushed_option($id));
        }
    }

    /**
     * Enqueue the editor bundle — the metabox app on post-edit screens, the
     * column-toggle wiring on list screens. `admin_enqueue_scripts` covers both
     * the block and classic editors.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public static function enqueue(string $hook): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        if ($hook === 'edit.php') {
            self::enqueue_list();
            return;
        }
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            self::enqueue_metabox();
        }
    }

    /**
     * Assets + data for the single-post metabox.
     */
    private static function enqueue_metabox(): void {
        if (self::current_public_type() === null) {
            return;
        }

        $entry = Assets::entry(self::ENTRY);
        if ($entry === null) {
            return;
        }

        Assets::enqueue_css(self::ENTRY);
        wp_enqueue_script(self::SCRIPT_HANDLE, SHIPSTATIC_PLUGIN_URL . 'assets/dist/' . $entry['file'], [], SHIPSTATIC_VERSION, true);

        $post_id   = (int) get_the_ID();
        $published = get_post_status($post_id) === 'publish';

        wp_localize_script(self::SCRIPT_HANDLE, 'shipStaticEditorData', [
            'restUrl'       => esc_url_raw(rest_url('shipstatic/v1')),
            'nonce'         => wp_create_nonce('wp_rest'),
            'postId'        => $post_id,
            'pageUrl'       => $published ? (string) get_permalink($post_id) : '',
            'published'     => $published,
            'included'      => UrlCollector::is_included($post_id),
            'effective'     => UrlCollector::is_effective($post_id),
            'includedCount' => UrlCollector::effective_count(),
            'savedCount'    => UrlCollector::included_count(),
            'i18n'          => I18n::all(),
        ]);
    }

    /**
     * Assets + data for the list-table "Static" toggle column. No CSS needed (the
     * cell is a bare checkbox), so only the JS bundle is enqueued.
     */
    private static function enqueue_list(): void {
        if (self::current_public_type() === null) {
            return;
        }

        // The column cells are server-rendered, so the bundle's CSS (which carries
        // the `:global(.bs-static-*)` cell styles + the SyncPanel modal chunk) IS
        // needed here.
        $entry = Assets::entry(self::ENTRY);
        if ($entry === null) {
            return;
        }
        Assets::enqueue_css(self::ENTRY);
        wp_enqueue_script(self::SCRIPT_HANDLE, SHIPSTATIC_PLUGIN_URL . 'assets/dist/' . $entry['file'], [], SHIPSTATIC_VERSION, true);

        $manual = UrlCollector::mode() === 'manual';

        wp_localize_script(self::SCRIPT_HANDLE, 'shipStaticListData', [
            'restUrl'       => esc_url_raw(rest_url('shipstatic/v1')),
            'nonce'         => wp_create_nonce('wp_rest'),
            'manualMode'    => $manual,
            // The sync action in the modal mirrors the FAB — gated by the same
            // "Enable sync single button" toggle.
            'fabEnabled'    => get_option('shipstatic_fab_enabled', '1') !== '0',
            'includedCount' => $manual ? UrlCollector::effective_count() : 0,
            'savedCount'    => $manual ? UrlCollector::included_count() : 0,
            'labels'        => [
                'included' => __('In the static export — click to remove', 'shipstatic'),
                'excluded' => __('Not in the static export — click to include', 'shipstatic'),
                'failed'   => __('Could not change this page. Please try again.', 'shipstatic'),
            ],
        ]);
    }

    /**
     * The current admin screen's post type if it's an exportable (public) one,
     * else null.
     */
    private static function current_public_type(): ?string {
        $screen = get_current_screen();
        $type   = $screen ? (string) $screen->post_type : '';

        return ($type !== '' && in_array($type, UrlCollector::public_post_types(), true)) ? $type : null;
    }

    /**
     * Output the editor script tag as an ES module.
     *
     * @param string $tag    Script tag HTML.
     * @param string $handle Script handle.
     */
    public static function script_as_module(string $tag, string $handle): string {
        if ($handle !== self::SCRIPT_HANDLE) {
            return $tag;
        }

        return str_replace('<script ', '<script type="module" ', $tag);
    }
}
