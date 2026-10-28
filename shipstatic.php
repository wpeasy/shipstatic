<?php
/**
 * Plugin Name:       ShipStatic
 * Description:       Publish your WordPress site as static HTML over SFTP/FTP, HTTPS, Cloudflare or GitHub Pages. Bricks-aware; works with any WordPress content.
 * Version:           2.4.10
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            BRXProd
 * Author URI:        https://brxprod.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shipstatic
 * Domain Path:       /languages
 *
 * @package WPEasy\ShipStatic
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic;

defined('ABSPATH') || exit;

/**
 * Plugin constants.
 */
define('SHIPSTATIC_VERSION', '2.4.10');
define('SHIPSTATIC_PLUGIN_FILE', __FILE__);
define('SHIPSTATIC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SHIPSTATIC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SHIPSTATIC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Composer autoloader for bundled dependencies (phpseclib).
 *
 * Shipped in the plugin zip so end users don't need Composer. Loaded if present;
 * the self-contained autoloader below still handles the plugin's own classes.
 */
if (is_readable(SHIPSTATIC_PLUGIN_DIR . 'vendor/autoload.php')) {
    require SHIPSTATIC_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * PSR-4 autoloader for the plugin namespace.
 *
 * Kept self-contained (independent of Composer) so the plugin's own classes load
 * even if `vendor/` is absent.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    $len    = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return; // Not ours — let other autoloaders handle it.
    }

    $relative = substr($class, $len);
    $file     = SHIPSTATIC_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_readable($file)) {
        require $file;
    }
});

/**
 * Load translations from /languages (bundled .mo files). On `init` per WP 6.7+,
 * which warns when a textdomain is loaded too early.
 */
add_action('init', static function (): void {
    load_plugin_textdomain('shipstatic', false, dirname(SHIPSTATIC_PLUGIN_BASENAME) . '/languages');
});

/**
 * Boot the plugin once all plugins are loaded.
 */
add_action('plugins_loaded', static function (): void {
    Plugin::init();
});

/**
 * Reset the first-run wizard flag on (re)activation, so deactivating and
 * reactivating the plugin — e.g. to re-test onboarding, or after cloning a
 * site — always shows the setup wizard again.
 */
register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);
