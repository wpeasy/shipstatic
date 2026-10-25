<?php
/**
 * Puts the site-search script into exported pages.
 *
 * @package WPEasy\ShipStatic
 * @since   2.2.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Frontend;

use WPEasy\ShipStatic\Render\PageRenderer;
use WPEasy\ShipStatic\Search\IndexBuilder;
use WPEasy\ShipStatic\Search\LiveIndex;
use WPEasy\ShipStatic\Search\SearchForm;
use WPEasy\ShipStatic\Support\Assets;

defined('ABSPATH') || exit;

/**
 * Enqueues the site-search script.
 *
 * - Into the render (the HTML captured for the static export): the script
 *   upgrades every search form (any form with an `s` field) to search the
 *   destination's search-index.json.
 * - On the live WordPress site, only when "Use ShipStatic search on the
 *   WordPress site too" is on, and only on pages with the ShipStatic Search
 *   element or shortcode (enqueue_live()): it upgrades just those forms
 *   (`.bs-ss`), searching GET /shipstatic/v1/search-index. Other search forms
 *   keep normal WordPress search.
 */
final class Search {

    private const SCRIPT_HANDLE = 'shipstatic-search';

    private const ENTRY = 'src-svelte/search/main.ts';

    /**
     * Register hooks.
     */
    public static function init(): void {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
        add_filter('script_loader_tag', [self::class, 'script_as_module'], 10, 2);
        add_shortcode('shipstatic_search', [SearchForm::class, 'shortcode']);
        // ShipStatic Search element for Bricks (only when Bricks is the theme).
        add_action('init', [self::class, 'register_bricks_element'], 11);
    }

    /**
     * Register the ShipStatic Search Bricks element.
     */
    public static function register_bricks_element(): void {
        if (class_exists('\Bricks\Elements')) {
            // Name the element panel's category.
            add_filter('bricks/builder/i18n', static function (array $i18n): array {
                $i18n['shipstatic'] = esc_html__('ShipStatic', 'shipstatic');
                return $i18n;
            });
            \Bricks\Elements::register_element(SHIPSTATIC_PLUGIN_DIR . 'src/Bricks/SearchElement.php', 'shipstatic-search', '\WPEasy\ShipStatic\Bricks\SearchElement');
        }
    }

    /**
     * Enqueue the script + its settings during a render request.
     */
    public static function enqueue(): void {
        if (!IndexBuilder::enabled() || !PageRenderer::is_render_request()) {
            return;
        }

        // The index URL is an ordinary site URL here, so the export's URL
        // rewriting gives every destination its own path to it.
        self::enqueue_script(['index' => home_url('/' . IndexBuilder::FILE)]);
    }

    /**
     * Enqueue the script for the live site — called by the ShipStatic Search
     * element and shortcode, so it loads only where one of them is.
     */
    public static function enqueue_live(): void {
        if (!LiveIndex::enabled() || PageRenderer::is_render_request() || self::in_builder()) {
            return; // A render gets enqueue()'s export settings instead.
        }

        self::enqueue_script([
            'index'    => rest_url('shipstatic/v1/search-index'),
            // Only our own element: other search forms keep WordPress search.
            'selector' => 'form.bs-ss',
            // WordPress can answer /?s=, so Enter with no result to open searches there.
            'live'     => true,
        ]);
    }

    /**
     * Whether this is the Bricks builder (either window), where the script
     * must not run.
     */
    private static function in_builder(): bool {
        return (function_exists('bricks_is_builder') && bricks_is_builder())
            || (function_exists('bricks_is_builder_iframe') && bricks_is_builder_iframe())
            || (function_exists('bricks_is_builder_call') && bricks_is_builder_call());
    }

    /**
     * Enqueue the script, its stylesheet and its settings (once per page).
     *
     * @param array<string,mixed> $config index (+ selector, live).
     */
    private static function enqueue_script(array $config): void {
        if (wp_script_is(self::SCRIPT_HANDLE, 'enqueued')) {
            return;
        }
        $entry = Assets::entry(self::ENTRY);
        if ($entry === null) {
            return; // Assets not built.
        }

        // Results dropdown look (also styles plain WordPress/Bricks search forms).
        SearchForm::enqueue_style();
        wp_enqueue_script(self::SCRIPT_HANDLE, SHIPSTATIC_PLUGIN_URL . 'assets/dist/' . $entry['file'], [], SHIPSTATIC_VERSION, true);

        $config['i18n'] = [
            /* translators: %s: what the visitor searched for. */
            'noResults' => __('No results for “%s”', 'shipstatic'),
            /* translators: %d: number of search results (2 or more). */
            'results'   => __('%d results', 'shipstatic'),
            'oneResult' => __('1 result', 'shipstatic'),
            'label'     => __('Search results', 'shipstatic'),
        ];
        // Slashes stay unescaped so the index URL reads like any other link in
        // the page (the export rewrites it per destination).
        wp_add_inline_script(
            self::SCRIPT_HANDLE,
            'window.shipStaticSearch = ' . wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';',
            'before'
        );
    }

    /**
     * Load the script as an ES module (Vite output).
     *
     * @param string $tag    Script tag.
     * @param string $handle Script handle.
     */
    public static function script_as_module(string $tag, string $handle): string {
        if ($handle !== self::SCRIPT_HANDLE) {
            return $tag;
        }

        // Only the external tag, not the inline settings block before it.
        return (string) preg_replace('#<script (?=[^>]*\bsrc=)#', '<script type="module" ', $tag, 1);
    }
}
