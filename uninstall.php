<?php
/**
 * Uninstall cleanup.
 *
 * Runs when the plugin is deleted from the WordPress admin. Removes everything
 * the plugin stores: its options (settings, destinations — including saved
 * passwords and API tokens — job state, manifests), its transients, the
 * per-post "include in export" meta, and the staging cache directory.
 *
 * Files already deployed to destinations are not touched.
 *
 * @package WPEasy\ShipStatic
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// The plugin isn't loaded during uninstall; register its autoloader so the
// cache path is resolved exactly as at runtime (constant → filter → default).
spl_autoload_register(static function (string $class): void {
    $prefix = 'WPEasy\\ShipStatic\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) {
        require $file;
    }
});

/**
 * Remove one site's data.
 */
function shipstatic_uninstall_site(): void {
    global $wpdb;

    // Lockdown's files (deactivation already removed them; be sure). Before the
    // options go: the saved server facts say where .user.ini is.
    \WPEasy\ShipStatic\Lockdown\Writer::remove_all();

    // Scheduled-sync cron events (deactivation already clears them; be sure).
    wp_clear_scheduled_hook('shipstatic_scheduled_sync');
    wp_clear_scheduled_hook('shipstatic_scheduled_continue');
    wp_clear_scheduled_hook('shipstatic_lockdown_cleanup');

    // Options + transients. Every key the plugin writes starts with
    // `shipstatic_` (transients: `_transient[_timeout]_shipstatic_…`).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off bulk delete on uninstall; no cache to keep.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('shipstatic_') . '%',
            $wpdb->esc_like('_transient_shipstatic_') . '%',
            $wpdb->esc_like('_transient_timeout_shipstatic_') . '%'
        )
    );
    wp_cache_flush();

    delete_post_meta_by_key('_shipstatic_manual_include');

    // Staging cache (rendered pages, packages, exports). Only removed when it is
    // the plugin's own folder — a custom SHIPSTATIC_CACHE_DIR / filter could
    // point somewhere shared, so anything not named `shipstatic` is left alone.
    $dir = \WPEasy\ShipStatic\Support\Paths::cache_dir();
    if ($dir !== '' && basename($dir) === 'shipstatic' && is_dir($dir)) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        (new WP_Filesystem_Direct(null))->rmdir($dir, true);
    }
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $shipstatic_site_id) {
        switch_to_blog((int) $shipstatic_site_id);
        shipstatic_uninstall_site();
        restore_current_blog();
    }
} else {
    shipstatic_uninstall_site();
}
