<?php
/**
 * Admin menu + page registration.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Admin;

use WPEasy\ShipStatic\Support\Assets;
use WPEasy\ShipStatic\Support\I18n;

defined('ABSPATH') || exit;

/**
 * Registers the top-level "ShipStatic" admin page and its assets.
 */
final class Menu {

    /**
     * Admin menu slug.
     */
    private const MENU_SLUG = 'shipstatic';

    /**
     * Documentation submenu slug.
     */
    private const DOCS_SLUG = 'shipstatic-docs';

    /**
     * Capability required to view the page.
     */
    private const CAPABILITY = 'manage_options';

    /**
     * Script handle for the dashboard bundle (loaded as an ES module).
     */
    private const SCRIPT_HANDLE = 'shipstatic-dashboard';

    /**
     * Script handle for the documentation bundle.
     */
    private const DOCS_HANDLE = 'shipstatic-docs';

    /**
     * Page hook suffixes (dashboard, docs), used to scope asset enqueuing.
     *
     * @var string
     */
    private static string $page_hook = '';

    /**
     * @var string
     */
    private static string $docs_hook = '';

    /**
     * Register hooks.
     */
    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_filter('script_loader_tag', [self::class, 'script_as_module'], 10, 3);
        add_filter('site_status_tests', [self::class, 'site_health_tests']);
    }

    /**
     * Vite entries the admin apps ship, by label (used by the pre-check).
     */
    private const ENTRIES = [
        'dashboard' => 'src-svelte/dashboard/main.ts',
        'docs'      => 'src-svelte/docs/main.ts',
    ];

    /**
     * Pre-check an entry's built files: the Vite manifest, the entry script, every
     * script chunk it imports and every stylesheet must exist and be readable.
     * A partial upload, a bad zip extraction or wrong permissions otherwise only
     * show up as a page stuck on "Loading…".
     *
     * @param string $entry_key Vite manifest input key.
     * @return string|null A plain-text description of the problem, or null when fine.
     */
    public static function assets_problem(string $entry_key): ?string {
        $dist = SHIPSTATIC_PLUGIN_DIR . 'assets/dist/';

        if (!is_readable($dist . '.vite/manifest.json')) {
            return __('The admin assets are not built (assets/dist/.vite/manifest.json is missing). Reinstall the plugin from a fresh download, or run "npm run build" in the plugin directory.', 'shipstatic');
        }

        $manifest = self::manifest_entry($entry_key);
        if ($manifest === null) {
            return __('The built admin assets are out of date (the manifest has no entry for this page). Reinstall the plugin from a fresh download.', 'shipstatic');
        }

        $files = [];
        $seen  = [];
        $all   = json_decode((string) file_get_contents($dist . '.vite/manifest.json'), true);
        $walk  = static function (string $key) use (&$walk, &$files, &$seen, $all): void {
            if (isset($seen[$key]) || !isset($all[$key]) || !is_array($all[$key])) {
                return;
            }
            $seen[$key] = true;
            if (isset($all[$key]['file'])) {
                $files[] = (string) $all[$key]['file'];
            }
            foreach ((array) ($all[$key]['css'] ?? []) as $css) {
                $files[] = (string) $css;
            }
            foreach ((array) ($all[$key]['imports'] ?? []) as $import) {
                $walk((string) $import);
            }
        };
        $walk($entry_key);

        foreach ($files as $file) {
            if (!is_readable($dist . $file)) {
                /* translators: %s: file path inside the plugin, e.g. assets/dist/assets/dashboard-abc.js */
                return sprintf(__('A built file is missing or unreadable: %s. The plugin may have been uploaded incompletely or its file permissions are wrong. Reinstall it from a fresh download.', 'shipstatic'), 'assets/dist/' . $file);
            }
        }

        return null;
    }

    /**
     * Site Health: report missing/unreadable admin assets.
     *
     * @param array<string,mixed> $tests Registered tests.
     * @return array<string,mixed>
     */
    public static function site_health_tests(array $tests): array {
        $tests['direct']['shipstatic_assets'] = [
            'label' => __('ShipStatic admin assets', 'shipstatic'),
            'test'  => [self::class, 'site_health_assets'],
        ];

        return $tests;
    }

    /**
     * Site Health result for the ShipStatic admin assets.
     *
     * @return array<string,mixed>
     */
    public static function site_health_assets(): array {
        $problems = [];
        foreach (self::ENTRIES as $key) {
            $problem = self::assets_problem($key);
            if ($problem !== null) {
                $problems[$problem] = true;
            }
        }

        $result = [
            'label'       => __('ShipStatic admin assets are present', 'shipstatic'),
            'status'      => 'good',
            'badge'       => ['label' => 'ShipStatic', 'color' => 'blue'],
            'description' => '<p>' . esc_html__('The files the ShipStatic dashboard needs are installed and readable.', 'shipstatic') . '</p>',
            'actions'     => '',
            'test'        => 'shipstatic_assets',
        ];

        if ($problems !== []) {
            $result['label']       = __('ShipStatic admin assets are missing', 'shipstatic');
            $result['status']      = 'critical';
            $result['description'] = '<p>' . implode('</p><p>', array_map('esc_html', array_keys($problems))) . '</p>';
        }

        return $result;
    }

    /**
     * The admin menu icon: the ShipStatic sailing ship in line art. WordPress
     * recolours menu SVGs by rewriting `fill` attributes only (svg-painter.js),
     * so the strokes live in a mask and are painted through one filled rect —
     * the rect's fill is what WordPress swaps for the admin colour scheme.
     * The mask paths deliberately carry no `fill` attribute (`fill-opacity`
     * instead), or the rewrite would fill the shapes solid. Same geometry as
     * `src-svelte/lib/icons/IconShip.svelte`.
     */
    private const MENU_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="18 48 128 128"><defs><mask id="shipstatic-ship" maskUnits="userSpaceOnUse" x="18" y="48" width="128" height="128"><g stroke="#fff" stroke-width="11" stroke-linecap="round" stroke-linejoin="round" fill-opacity="0"><path d="M26 140 H128 L114 166 Q78 174 40 166 Z"/><path d="M62 140 V126 M62 72 V56 M104 140 V126 M104 86 V76"/><path d="M40 72 H84 Q96 99 84 126 H40 Q50 99 40 72 Z"/><path d="M90 86 H118 Q127 106 118 126 H90 Q97 106 90 86 Z"/></g></mask></defs><rect x="18" y="48" width="128" height="128" fill="black" mask="url(#shipstatic-ship)"/></svg>';

    /**
     * The menu icon as the base64 data URI add_menu_page() expects (WordPress
     * only recolours base64-encoded SVG icons).
     */
    private static function menu_icon(): string {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encodes our own static SVG into the data URI format add_menu_page() requires.
        return 'data:image/svg+xml;base64,' . base64_encode(self::MENU_ICON_SVG);
    }

    /**
     * Register the top-level admin menu page.
     */
    public static function register_menu(): void {
        self::$page_hook = (string) add_menu_page(
            __('ShipStatic', 'shipstatic'),
            __('ShipStatic', 'shipstatic'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [self::class, 'render_page'],
            self::menu_icon(),
            58
        );

        // Rename the auto-created first submenu (defaults to the menu title) to
        // "Dashboard", then add the Documentation page.
        add_submenu_page(
            self::MENU_SLUG,
            __('ShipStatic', 'shipstatic'),
            __('Dashboard', 'shipstatic'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [self::class, 'render_page']
        );

        self::$docs_hook = (string) add_submenu_page(
            self::MENU_SLUG,
            __('Documentation', 'shipstatic'),
            __('Documentation', 'shipstatic'),
            self::CAPABILITY,
            self::DOCS_SLUG,
            [self::class, 'render_docs']
        );
    }

    /**
     * Enqueue the base framework CSS and built dashboard bundle on our page only.
     *
     * @param string $hook The current admin page hook suffix.
     */
    public static function enqueue_assets(string $hook): void {
        if ($hook === self::$page_hook) {
            // The browser-driven sync needs PHP workers free for its loopback page
            // renders. The admin heartbeat would periodically consume one, stalling
            // the render on low-worker hosts (e.g. Local). It isn't needed here.
            wp_deregister_script('heartbeat');
            // The media replacer opens the WordPress media library (wp.media).
            wp_enqueue_media();
            self::enqueue_app(self::ENTRIES['dashboard'], self::SCRIPT_HANDLE, [
                // Whether the setup wizard has never been shown/dismissed yet —
                // dashboard-only, so it never leaks onto the docs page's shipStaticData.
                'isFirstRun' => get_option('shipstatic_wizard_seen', '0') === '0',
            ]);
        } elseif ($hook === self::$docs_hook) {
            self::enqueue_app(self::ENTRIES['docs'], self::DOCS_HANDLE);
        }
    }

    /**
     * Enqueue a built Svelte app (framework CSS + the entry's CSS chunks + JS),
     * reading the hashed filenames from the Vite manifest, plus the REST data.
     *
     * @param string               $entry_key Vite manifest input key.
     * @param string               $handle    Script handle (also the CSS handle prefix).
     * @param array<string,mixed>  $extra     Extra key/value pairs merged into `shipStaticData`.
     */
    private static function enqueue_app(string $entry_key, string $handle, array $extra = []): void {
        $problem = self::assets_problem($entry_key);
        $entry   = $problem === null ? self::manifest_entry($entry_key) : null;
        if ($entry === null) {
            add_action('admin_notices', static function () use ($problem): void {
                echo '<div class="notice notice-error"><p>'
                    . esc_html(sprintf(
                        /* translators: %s: what is wrong with the built admin files. */
                        __('ShipStatic: %s', 'shipstatic'),
                        $problem ?? __('the admin assets could not be read.', 'shipstatic')
                    ))
                    . '</p></div>';
            });
            return;
        }

        // Enqueue the entry's CSS AND every transitively-imported chunk's CSS.
        // A plain CSS import shared by 2+ entries (e.g. `ab-ui/styles`, now used
        // by the dashboard AND editor) is split by Vite into a shared chunk whose
        // CSS lives outside this entry's own `css[]` — walk imports so it loads.
        foreach (Assets::css_files($entry_key) as $i => $css_file) {
            wp_enqueue_style($handle . '-' . $i, SHIPSTATIC_PLUGIN_URL . 'assets/dist/' . $css_file, [], SHIPSTATIC_VERSION);
        }

        wp_enqueue_script($handle, SHIPSTATIC_PLUGIN_URL . 'assets/dist/' . $entry['file'], [], SHIPSTATIC_VERSION, true);

        wp_localize_script($handle, 'shipStaticData', array_merge([
            'restUrl' => esc_url_raw(rest_url('shipstatic/v1')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'version' => SHIPSTATIC_VERSION,
            'docsUrl' => admin_url('admin.php?page=' . self::DOCS_SLUG),
            // Translated UI strings for the Svelte bundle (shared/i18n.ts).
            'i18n'    => I18n::all(),
        ], $extra));
    }

    /**
     * Output the dashboard script tag as an ES module.
     *
     * @param string $tag    The script tag HTML.
     * @param string $handle The script handle.
     * @param string $src    The script source URL.
     */
    public static function script_as_module(string $tag, string $handle, string $src): string {
        if ($handle !== self::SCRIPT_HANDLE && $handle !== self::DOCS_HANDLE) {
            return $tag;
        }

        // wp_get_script_tag() escapes the attributes itself.
        return wp_get_script_tag([
            'type' => 'module',
            'src'  => $src,
            'id'   => $handle . '-js',
        ]);
    }

    /**
     * Read an entry from the Vite manifest.
     *
     * @param string $entry_key Manifest input key.
     * @return array<string,mixed>|null Entry data, or null if the build is missing.
     */
    private static function manifest_entry(string $entry_key): ?array {
        $path = SHIPSTATIC_PLUGIN_DIR . 'assets/dist/.vite/manifest.json';

        if (!is_readable($path)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        $entry    = is_array($manifest) ? ($manifest[$entry_key] ?? null) : null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * Render the dashboard page.
     */
    public static function render_page(): void {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        require SHIPSTATIC_PLUGIN_DIR . 'templates/admin/page.php';
    }

    /**
     * Render the documentation page.
     */
    public static function render_docs(): void {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        require SHIPSTATIC_PLUGIN_DIR . 'templates/admin/docs.php';
    }
}
