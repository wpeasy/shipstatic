<?php
/**
 * Deploy step for static Bricks filters: each destination's data files, the
 * script, and everything the results show.
 *
 * @package WPEasy\ShipStatic
 * @since   2.5.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Bricks;

use WPEasy\ShipStatic\Render\AssetExtractor;
use WPEasy\ShipStatic\Settings\Destination;
use WPEasy\ShipStatic\Support\Assets;
use WPEasy\ShipStatic\Support\Paths;
use WPEasy\ShipStatic\Support\Url;
use WPEasy\ShipStatic\Sync\DeployReplacer;
use WPEasy\ShipStatic\Sync\Runner;

defined('ABSPATH') || exit;

/**
 * For every loop {@see StaticFiltersBuilder} could build:
 *
 * - the data file is rewritten for this destination — every item's and filter's
 *   markup goes through {@see Runner::transform_for_destination()}, the path the
 *   relay's replies take (links to this site, the destination's text and link
 *   replacements, its sub-path), and written under `shipstatic-filters/`;
 * - **every image the results can show** is added to the upload list. A filter
 *   can bring up an item that is nowhere on the page, so it is not enough to
 *   upload what the pages use: the markup of every item is read with the
 *   crawler's own extractor (`src`, `data-src`, `srcset`, `poster`, CSS
 *   `url()`), and each same-site file found goes in;
 * - the page gets the list of its loops (a JSON block) and the runtime script
 *   ({@see src-svelte/static-filters/main.ts}).
 *
 * A page-only replacer ({@see Runner::PAGE_ONLY_REPLACERS}): the data files
 * must not be given the script.
 */
final class StaticFiltersReplacer implements DeployReplacer {

    /**
     * {@inheritDoc}
     */
    public function key(): string {
        return 'bricksStaticFilters';
    }

    /**
     * {@inheritDoc}
     */
    public function prepare(Destination $dest, string $dest_base): array {
        if (!StaticFilters::enabled()) {
            return ['ctx' => null, 'files' => []];
        }
        $runtime = self::runtime_file();
        if ($runtime === null) {
            return ['ctx' => null, 'files' => []];
        }

        $out_dir = Paths::cache_dir() . '/filters-out/' . $dest->id();
        self::clear($out_dir);

        $pages = [];
        $files = [];
        foreach (StaticFilters::registry() as $relative => $loops) {
            foreach ($loops as $qid => $loop) {
                $source = StaticFilters::cache_dir() . '/' . (string) ($loop['id'] ?? '') . '.json';
                if (empty($loop['ok']) || !preg_match('/^[a-f0-9]{12}$/', (string) ($loop['id'] ?? '')) || !is_file($source)) {
                    continue;
                }
                $ds = json_decode((string) file_get_contents($source), true);
                if (!is_array($ds) || !wp_mkdir_p($out_dir)) {
                    continue;
                }

                foreach (self::media((array) $ds) as $rel => $src) {
                    $files[$rel] = $src;
                }

                $ds['baseUrl'] = Runner::transform_for_destination((string) $ds['baseUrl'], $dest, 'html');
                foreach ((array) $ds['items'] as $oid => $markup) {
                    $ds['items'][$oid] = Runner::transform_for_destination((string) $markup, $dest, 'html');
                }
                foreach ((array) $ds['filterHtml'] as $fid => $markup) {
                    $ds['filterHtml'][$fid] = Runner::transform_for_destination((string) $markup, $dest, 'html');
                }

                $name = (string) $loop['id'] . '.json';
                $path = $out_dir . '/' . $name;
                $json = wp_json_encode($ds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($json) || file_put_contents($path, $json) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the plugin's own cache folder.
                    continue;
                }
                $files[StaticFilters::DIR . '/' . $name] = $path;
                // `?v=` = this file's content: a host that caches files for a long time (Cloudflare: a year) must not serve an old
                // data file next to a page that expects the new one.
                $pages[(string) $relative][(string) $qid] = Url::BASE_TOKEN . '/' . StaticFilters::DIR . '/' . $name . '?v=' . substr(md5($json), 0, 8);
            }
        }
        if (!$pages) {
            return ['ctx' => null, 'files' => []];
        }
        $files[StaticFilters::DIR . '/runtime.js'] = $runtime;
        ksort($files);

        return [
            'ctx'   => ['pages' => $pages, 'script' => Url::BASE_TOKEN . '/' . StaticFilters::DIR . '/runtime.js?v=' . substr((string) md5_file($runtime), 0, 8), 'fromUrl' => !Relay::enabled()],
            'files' => $files,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function apply(string $html, $ctx, string $relative): string {
        if (!isset($ctx['pages'][$relative]) || stripos($html, '</body>') === false) {
            return $html;
        }

        $tags = '';
        // Bricks applies `?brx_…` filters on load itself only on WordPress; here the same script does it
        // (the relay adds it too when it is on).
        if ($ctx['fromUrl']) {
            $tags .= RelayReplacer::filters_from_url_tag($html);
        }
        $tags .= wp_get_inline_script_tag(
            (string) wp_json_encode($ctx['pages'][$relative], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP),
            ['type' => 'application/json', 'id' => 'shipstatic-filters']
        );
        $tags .= wp_get_script_tag(['src' => $ctx['script'], 'type' => 'module']);

        $at = (int) strripos($html, '</body>');

        return substr($html, 0, $at) . $tags . substr($html, $at);
    }

    /**
     * {@inheritDoc}
     */
    public function signature(Destination $dest) {
        if (!StaticFilters::enabled()) {
            return null;
        }
        $runtime = self::runtime_file();

        return [StaticFilters::signature(), $runtime !== null ? md5_file($runtime) : '', Relay::enabled()];
    }

    /**
     * The built script.
     */
    private static function runtime_file(): ?string {
        $entry = Assets::entry('src-svelte/static-filters/main.ts');
        $path  = $entry !== null ? SHIPSTATIC_PLUGIN_DIR . 'assets/dist/' . (string) $entry['file'] : '';

        return $path !== '' && is_file($path) ? $path : null;
    }

    /**
     * Every same-site file the results of a loop show, as export path => local
     * file: all items, not only those on the first page.
     *
     * @param array<string,mixed> $ds Dataset (the markup as WordPress sent it).
     * @return array<string,string>
     */
    private static function media(array $ds): array {
        $files = [];
        $base  = (string) ($ds['baseUrl'] ?? '');
        foreach (array_merge(array_values((array) $ds['items']), array_values((array) $ds['filterHtml'])) as $markup) {
            foreach (AssetExtractor::extract_assets((string) $markup, $base) as $url) {
                $rel = Url::to_relative_path($url);
                $src = $rel !== null ? Paths::source_file($url) : null;
                if ($rel !== null && $src !== null && !isset($files[$rel])) {
                    $files[$rel] = $src;
                }
            }
        }

        return $files;
    }

    /**
     * Empty a destination's output folder.
     *
     * @param string $dir Folder.
     */
    private static function clear(string $dir): void {
        foreach (is_dir($dir) ? (array) glob($dir . '/*.json') : [] as $file) {
            wp_delete_file((string) $file);
        }
    }
}
