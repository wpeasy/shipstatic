<?php
/**
 * Tracks whether the site content has changed since the last render, so the
 * dashboard can flag destinations out of sync and prompt a re-render.
 *
 * @package WPEasy\ShipStatic
 * @since   1.0.2
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Sync;

use WPEasy\ShipStatic\Discovery\UrlCollector;

defined('ABSPATH') || exit;

/**
 * The render manifest is only rebuilt by a Check/Sync run — nothing renders on
 * post save. So after content changes the export is stale, yet (without this) the
 * "In sync" dot stays green and the pages list shows the old split. This watches
 * public-content edits and raises a "dirty" flag; the flag is cleared whenever a
 * full render finalises. {@see Runner::in_sync()} treats a dirty flag as out of
 * sync, and the dashboard offers "Process" (re-render) instead of "View list".
 */
final class ChangeTracker {

    /**
     * Option: set when public content has changed since the last full render.
     */
    public const DIRTY_OPTION = 'shipstatic_content_dirty';

    /**
     * Option: the discovery mode in force at the last full render. A mode change
     * also makes the render (and the included/excluded split) stale.
     */
    public const MODE_OPTION = 'shipstatic_render_mode';

    /**
     * Option: ids of public posts added/edited/unpublished/deleted since the
     * last full render — what "Process changes" re-renders (Runner::start()
     * with `changes`). Cleared by a full render; trimmed by a partial one.
     */
    public const CHANGED_OPTION = 'shipstatic_changed_posts';

    /**
     * Option: why the last changes may affect every page (menus, Bricks
     * templates/settings, widgets, site title, permalinks) — reason => true.
     * "Process changes" can't cover these; the dashboard says to Process all.
     */
    public const SITEWIDE_OPTION = 'shipstatic_sitewide_changes';

    /** Past this many changed posts, Process all is the sensible choice anyway. */
    private const MAX_CHANGED = 500;

    /** Options whose change shows on every page => reason. */
    private const SITEWIDE_OPTIONS = [
        'blogname'            => 'site',
        'blogdescription'     => 'site',
        'sidebars_widgets'    => 'widgets',
        'permalink_structure' => 'permalinks',
        'show_on_front'       => 'site',
        'page_on_front'       => 'site',
        'page_for_posts'      => 'site',
        // Bricks design data that styles or appears on every page. (Not every
        // bricks_* option: some are caches, licence checks or builder UI state.)
        'bricks_global_settings'     => 'bricks',
        'bricks_global_classes'      => 'bricks',
        'bricks_global_variables'    => 'bricks',
        'bricks_global_pseudo_classes' => 'bricks',
        'bricks_color_palette'       => 'bricks',
        'bricks_theme_styles'        => 'bricks',
        'bricks_components'          => 'bricks',
        'bricks_global_elements'     => 'bricks',
        'bricks_icon_sets'           => 'bricks',
    ];

    /**
     * Register hooks.
     */
    public static function init(): void {
        add_action('save_post', [self::class, 'on_save_post'], 10, 2);
        add_action('deleted_post', [self::class, 'on_post_id_change']);
        add_action('trashed_post', [self::class, 'on_post_id_change']);
        add_action('untrashed_post', [self::class, 'on_post_id_change']);
        add_action('transition_post_status', [self::class, 'on_transition'], 10, 3);
        // Bricks stores page content in `_bricks_*` post meta and may save it
        // without a full post update, so watch those meta writes too.
        add_action('updated_post_meta', [self::class, 'on_meta'], 10, 3);
        add_action('added_post_meta', [self::class, 'on_meta'], 10, 3);
        // A nav-menu edit changes the link graph (so the linked crawl reaches
        // different pages) AND the menu markup baked into every exported page —
        // but it saves `nav_menu_item` posts, not a public type, so the hooks
        // above miss it. Flag dirty whenever a menu is saved/reordered.
        add_action('wp_update_nav_menu', [self::class, 'on_menu']);
        // Changes that show on every page (see SITEWIDE_OPTION).
        add_action('updated_option', [self::class, 'on_option'], 10, 1);
        add_action('switch_theme', [self::class, 'on_theme']);
    }

    /**
     * A nav menu was saved: every page's chrome (and the link graph) changed.
     */
    public static function on_menu(): void {
        self::mark_sitewide('menus');
    }

    /**
     * Bricks global settings/classes/theme styles and core site options.
     *
     * @param string $option Option name.
     */
    public static function on_option($option): void {
        $option = (string) $option;
        if (isset(self::SITEWIDE_OPTIONS[$option])) {
            self::mark_sitewide(self::SITEWIDE_OPTIONS[$option]);
        }
    }

    /**
     * The active theme changed.
     */
    public static function on_theme(): void {
        self::mark_sitewide('theme');
    }

    /**
     * @param int      $post_id Post id.
     * @param \WP_Post $post    Post object.
     */
    public static function on_save_post($post_id, $post): void {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        if (!($post instanceof \WP_Post) || $post->post_status === 'auto-draft') {
            return;
        }
        if (self::is_public_type((string) $post->post_type)) {
            self::mark_changed((int) $post_id);
        } elseif ((string) $post->post_type === 'bricks_template') {
            // Header/footer/single templates show on many pages.
            self::mark_sitewide('templates');
        }
    }

    /**
     * @param string   $new  New status.
     * @param string   $old  Old status.
     * @param \WP_Post $post Post object.
     */
    public static function on_transition($new, $old, $post): void {
        if ($new === $old || !($post instanceof \WP_Post)) {
            return;
        }
        if (self::is_public_type((string) $post->post_type)) {
            self::mark_changed((int) $post->ID);
        }
    }

    /**
     * Delete/trash/untrash, which pass only the post id.
     *
     * @param int $post_id Post id.
     */
    public static function on_post_id_change($post_id): void {
        $type = (string) get_post_type((int) $post_id);
        if ($type !== '' && self::is_public_type($type)) {
            self::mark_changed((int) $post_id);
        }
    }

    /**
     * @param int    $meta_id  Meta row id (unused).
     * @param int    $post_id  Post id.
     * @param string $meta_key Meta key.
     */
    public static function on_meta($meta_id, $post_id, $meta_key): void {
        unset($meta_id);
        if (strpos((string) $meta_key, '_bricks') !== 0) {
            return; // Only Bricks builder content meta.
        }
        $type = (string) get_post_type((int) $post_id);
        if ($type !== '' && self::is_public_type($type)) {
            self::mark_changed((int) $post_id);
        } elseif ($type === 'bricks_template') {
            self::mark_sitewide('templates');
        }
    }

    /**
     * Whether a post type is one we'd export.
     *
     * @param string $type Post type.
     */
    private static function is_public_type(string $type): bool {
        return in_array($type, UrlCollector::public_post_types(), true);
    }

    /**
     * Flag the render as stale (content changed since it was built).
     */
    public static function mark_dirty(): void {
        update_option(self::DIRTY_OPTION, 1, false);
    }

    /**
     * Record a changed public post (and flag the render stale).
     *
     * @param int $post_id Post id.
     */
    public static function mark_changed(int $post_id): void {
        self::mark_dirty();
        $ids = self::changed_ids();
        if ($post_id > 0 && !in_array($post_id, $ids, true)) {
            $ids[] = $post_id;
            if (count($ids) > self::MAX_CHANGED) {
                self::mark_sitewide('many');
                $ids = array_slice($ids, -self::MAX_CHANGED);
            }
            update_option(self::CHANGED_OPTION, $ids, false);
        }
    }

    /**
     * Record a change that may show on every page (and flag the render stale).
     *
     * @param string $reason menus | templates | bricks | widgets | site | permalinks | theme | many.
     */
    public static function mark_sitewide(string $reason): void {
        self::mark_dirty();
        $reasons = self::sitewide_reasons();
        if (!in_array($reason, $reasons, true)) {
            $reasons[] = $reason;
            update_option(self::SITEWIDE_OPTION, $reasons, false);
        }
    }

    /**
     * Ids of public posts changed since the last full render.
     *
     * @return array<int,int>
     */
    public static function changed_ids(): array {
        $ids = get_option(self::CHANGED_OPTION, []);

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    /**
     * Site-wide change reasons since the last full render.
     *
     * @return array<int,string>
     */
    public static function sitewide_reasons(): array {
        $reasons = get_option(self::SITEWIDE_OPTION, []);

        return is_array($reasons) ? array_values(array_map('strval', $reasons)) : [];
    }

    /**
     * Record that a full render just finalised: clear the dirty flag and the
     * change lists, and store the discovery mode it was built under.
     *
     * @param string $mode Discovery mode used for the render.
     */
    public static function mark_rendered(string $mode): void {
        update_option(self::DIRTY_OPTION, 0, false);
        update_option(self::MODE_OPTION, $mode, false);
        delete_option(self::CHANGED_OPTION);
        delete_option(self::SITEWIDE_OPTION);
    }

    /**
     * Record that "Process changes" just processed these posts. The render
     * counts as current again only when no change is left and nothing
     * site-wide is pending (that still needs Process all).
     *
     * @param array<int,int> $ids Processed post ids.
     */
    public static function mark_partial(array $ids): void {
        $left = array_values(array_diff(self::changed_ids(), array_map('intval', $ids)));
        if ($left === []) {
            delete_option(self::CHANGED_OPTION);
        } else {
            update_option(self::CHANGED_OPTION, $left, false);
        }
        if ($left === [] && self::sitewide_reasons() === []) {
            update_option(self::DIRTY_OPTION, 0, false);
        }
    }

    /**
     * Clear all tracking (e.g. on Reset).
     */
    public static function forget(): void {
        delete_option(self::DIRTY_OPTION);
        delete_option(self::MODE_OPTION);
        delete_option(self::CHANGED_OPTION);
        delete_option(self::SITEWIDE_OPTION);
    }

    /**
     * Whether content has changed since the last full render.
     */
    public static function is_dirty(): bool {
        return (bool) get_option(self::DIRTY_OPTION, false);
    }

    /**
     * The discovery mode the current render was built under ('' if never).
     */
    public static function rendered_mode(): string {
        return (string) get_option(self::MODE_OPTION, '');
    }
}
