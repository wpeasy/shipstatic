<?php
/**
 * Plugin bootstrap.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic;

use WPEasy\ShipStatic\Abilities\AbilityRegistry;
use WPEasy\ShipStatic\Admin\Editor;
use WPEasy\ShipStatic\Admin\Menu;
use WPEasy\ShipStatic\Export\ExportDownload;
use WPEasy\ShipStatic\Bricks\RelayReplacer;
use WPEasy\ShipStatic\Bricks\Relay;
use WPEasy\ShipStatic\Frontend\Fab;
use WPEasy\ShipStatic\Frontend\Search;
use WPEasy\ShipStatic\Lockdown\Guard;
use WPEasy\ShipStatic\Lockdown\Settings as LockdownSettings;
use WPEasy\ShipStatic\Lockdown\Writer as LockdownWriter;
use WPEasy\ShipStatic\REST\BricksRelayController;
use WPEasy\ShipStatic\REST\ScheduleController;
use WPEasy\ShipStatic\REST\SearchIndexController;
use WPEasy\ShipStatic\Sync\Scheduler;
use WPEasy\ShipStatic\Render\LinkDeployReplacer;
use WPEasy\ShipStatic\Render\MediaDeployReplacer;
use WPEasy\ShipStatic\Render\PageRenderer;
use WPEasy\ShipStatic\Render\TextDeployReplacer;
use WPEasy\ShipStatic\Render\VideoDeployReplacer;
use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Sitemap\SitemapGenerator;
use WPEasy\ShipStatic\Sync\Pipeline;
use WPEasy\ShipStatic\REST\ConnectionController;
use WPEasy\ShipStatic\REST\DestinationsController;
use WPEasy\ShipStatic\REST\EditorController;
use WPEasy\ShipStatic\REST\ExportController;
use WPEasy\ShipStatic\REST\LinksController;
use WPEasy\ShipStatic\REST\LockdownController;
use WPEasy\ShipStatic\REST\ServerFolderController;
use WPEasy\ShipStatic\REST\MediaController;
use WPEasy\ShipStatic\REST\SitemapController;
use WPEasy\ShipStatic\REST\StatusController;
use WPEasy\ShipStatic\REST\SyncController;
use WPEasy\ShipStatic\REST\VideosController;

defined('ABSPATH') || exit;

/**
 * Wires up the plugin's subsystems.
 */
final class Plugin {

    /**
     * Runs on plugin activation (`register_activation_hook`, `shipstatic.php`).
     * Resets the first-run setup-wizard flag so deactivating and reactivating
     * the plugin always shows it again — e.g. re-testing onboarding, or a fresh
     * clone of a site that already has the option set.
     */
    public static function activate(): void {
        delete_option('shipstatic_wizard_seen');
        // Restore the scheduled sync's cron event (cleared on deactivation).
        Scheduler::reschedule();
    }

    /**
     * Runs on plugin deactivation: remove the scheduled-sync cron events (the
     * settings are kept, and activate() puts the event back) and every
     * Lockdown file. Lockdown is left off, so reactivating never re-locks the
     * site without anyone noticing.
     */
    public static function deactivate(): void {
        Scheduler::unschedule();
        LockdownSettings::update(['enabled' => false]);
        LockdownWriter::remove_all();
    }

    /**
     * Initialise the plugin.
     */
    public static function init(): void {
        // Migrate the legacy single destination into the destinations list once.
        Destinations::ensure_migrated();

        // Lockdown first: it may answer the request (check, unlock link) itself.
        Guard::init();
        add_action(LockdownWriter::CLEANUP_HOOK, [LockdownWriter::class, 'cleanup']);

        self::register_pipeline();

        if (is_admin()) {
            Menu::init();
            // Per-post "Include" metabox — self-gates to `manual` discovery mode.
            Editor::init();
        }

        // Frontend "Sync this page" FAB (self-gates to admins on public pages).
        Fab::init();
        Search::init();
        // Bricks forms, filters and AJAX loops on the static site use WordPress.
        Relay::init();

        // Watch content edits so the dashboard can flag the render stale.
        \WPEasy\ShipStatic\Sync\ChangeTracker::init();
        Scheduler::init();

        // Expose abilities to the WordPress Abilities API for MCP/AI discovery
        // (no-op on WP < 6.9; read-only always, actions behind opt-in toggles).
        AbilityRegistry::init();

        add_action('rest_api_init', [self::class, 'register_rest_routes']);

        // On our own loopback render requests only, tidy the captured output so
        // the static copy doesn't ship broken references. (Head cruft — feed/
        // oEmbed links, generator meta — is cleaned deterministically on the
        // captured HTML string in the render pipeline; see Render\HeadCleaner.)
        if (PageRenderer::is_render_request()) {
            add_action('init', [self::class, 'prepare_render_output']);
        }

        // Tell our own render requests what they rendered (see PageRenderer::OBJECT_HEADER).
        if (PageRenderer::is_trusted_render_request()) {
            add_action('template_redirect', [self::class, 'announce_render_object'], 0);
        }

        // Let our own render requests through Bricks' maintenance / coming-soon
        // mode. Registered before `wp` (where Bricks applies it at priority 9).
        add_filter('bricks/maintenance/should_apply', [self::class, 'allow_render_through_maintenance']);

        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::add_command('shipstatic', new \WPEasy\ShipStatic\CLI\SyncCommand());
        }
    }

    /**
     * Register the deploy-pipeline replacers (text, media, links, videos, the
     * Bricks relay addresses) and the
     * sitemap/robots.txt extra-file emitter onto {@see Pipeline}.
     */
    private static function register_pipeline(): void {
        Pipeline::register_replacer(new TextDeployReplacer());
        Pipeline::register_replacer(new MediaDeployReplacer());
        Pipeline::register_replacer(new LinkDeployReplacer());
        Pipeline::register_replacer(new VideoDeployReplacer());
        Pipeline::register_replacer(new RelayReplacer());

        Pipeline::register_extra_files(static function (array $page_urls): array {
            if (!apply_filters('shipstatic_generate_sitemaps', true)) {
                return [];
            }

            return SitemapGenerator::from_urls($page_urls);
        });
    }

    /**
     * Filters `bricks/maintenance/should_apply` (Bricks 2.0+) so a static render
     * captures the real page, not the maintenance screen.
     *
     * With maintenance mode on, Bricks serves its maintenance template to every
     * visitor — including our loopback render, which would otherwise mirror that
     * screen over the whole site ("coming soon" returns HTTP 200, so it wouldn't
     * even fail loudly). The bypass requires the shared-secret header, so only a
     * render this site started gets through; a visitor spoofing the user agent
     * still sees the maintenance page.
     *
     * @param bool $apply Whether Bricks intends to apply maintenance mode.
     * @return bool
     */
    public static function allow_render_through_maintenance($apply): bool {
        if (!PageRenderer::is_trusted_render_request()) {
            return (bool) $apply;
        }

        /**
         * Filters whether static renders bypass maintenance / coming-soon mode.
         * Return false to have renders capture the maintenance screen instead.
         *
         * @param bool $bypass Whether to bypass.
         */
        return apply_filters('shipstatic_bypass_maintenance', true) ? false : (bool) $apply;
    }

    /**
     * Send PageRenderer::OBJECT_HEADER for a trusted render request: what the
     * page is, from the main query. Values are built from ints and constants.
     */
    public static function announce_render_object(): void {
        if (headers_sent()) {
            return;
        }
        if (is_singular()) {
            $object = 'post:' . (int) get_queried_object_id();
        } elseif (is_category() || is_tag() || is_tax()) {
            $object = 'term:' . (int) get_queried_object_id();
        } elseif (is_front_page() || is_home()) {
            $object = 'home';
        } elseif (is_404()) {
            $object = 'none';
        } else {
            $object = 'archive'; // Post-type, author, date archives and similar.
        }
        header(PageRenderer::OBJECT_HEADER . ': ' . $object);
    }

    /**
     * Strip output that only works with PHP/WordPress behind it from the pages we
     * render for the static copy. Currently the WordPress emoji loader: its
     * `wp-emoji-release.min.js` is referenced only from inline JS (so it can't be
     * mirrored and 404s on the static host), and modern browsers render emoji
     * natively without it. Applies to our render requests only — never the live
     * front end. Disable with the `shipstatic_strip_emoji` filter.
     */
    public static function prepare_render_output(): void {
        /** Filters whether the WordPress emoji loader is stripped from the static output. */
        if (!apply_filters('shipstatic_strip_emoji', true)) {
            return;
        }

        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    }

    /**
     * Register REST API controllers.
     */
    public static function register_rest_routes(): void {
        ConnectionController::register();
        DestinationsController::register();
        ServerFolderController::register();
        StatusController::register();
        SyncController::register();
        EditorController::register();
        MediaController::register();
        ExportController::register();
        ExportDownload::register();
        LinksController::register();
        VideosController::register();
        SitemapController::register();
        ScheduleController::register();
        LockdownController::register();
        SearchIndexController::register();
        BricksRelayController::register();
    }
}
