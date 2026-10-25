<?php
/**
 * Status REST controller.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\REST;

use WP_REST_Request;
use WP_REST_Response;
use WPEasy\ShipStatic\Abilities\AbilityRegistry;
use WPEasy\ShipStatic\Discovery\UrlCollector;
use WPEasy\ShipStatic\Render\CompatibilityScanner;
use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Sync\ChangeTracker;
use WPEasy\ShipStatic\Sync\ChangedContent;
use WPEasy\ShipStatic\Sync\DeployPool;
use WPEasy\ShipStatic\Support\Environment;
use WPEasy\ShipStatic\Support\Paths;
use WPEasy\ShipStatic\Support\Url;
use WPEasy\ShipStatic\Sync\Manifest;
use WPEasy\ShipStatic\Sync\MethodResolver;
use WPEasy\ShipStatic\Sync\Runner;
use WPEasy\ShipStatic\Search\IndexBuilder;
use WPEasy\ShipStatic\Search\LiveIndex;

defined('ABSPATH') || exit;

/**
 * Reports the three dashboard indicators (connected, pushed, in sync) plus the
 * resolved sync method.
 */
final class StatusController {

    /**
     * REST namespace.
     */
    private const NS = 'shipstatic/v1';

    /**
     * Register routes.
     */
    public static function register(): void {
        register_rest_route(self::NS, '/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/settings', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'save_settings'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/pages-overview', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'pages_overview'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);
    }

    /**
     * Capability gate.
     */
    public static function can_manage(): bool {
        return current_user_can('manage_options');
    }

    /**
     * GET /status — dashboard status snapshot.
     */
    public static function get(): WP_REST_Response {
        $state  = get_option('shipstatic_connection_state', []);
        $pushed = Manifest::load(Destinations::pushed_option(Destinations::primary()->id()));

        $render       = Manifest::load(Manifest::RENDER_OPTION);
        $connected    = is_array($state) && !empty($state['ok']);
        $has_pushed   = !empty($pushed);
        $has_rendered = !empty($render);

        // In sync = something has been pushed and the destination's last-synced
        // signature (render + its replacements) still matches the current state.
        $primary = Destinations::primary();
        $in_sync = $has_pushed && Runner::in_sync($primary->id(), $primary);

        return new WP_REST_Response([
            'connected' => $connected,
            'hasPushed' => $has_pushed,
            'inSync'    => $in_sync,
            'lastTest'  => is_array($state) ? [
                'ok'      => !empty($state['ok']),
                'time'    => isset($state['time']) ? (int) $state['time'] : 0,
                'message' => isset($state['message']) ? (string) $state['message'] : '',
            ] : null,
            'method'    => MethodResolver::resolve(),
            'isLocal'   => Environment::is_local(),
            // Plain permalinks put every page on the site root as a query string,
            // so nothing can be mapped to a file. The dashboard blocks-warns on it.
            'prettyPermalinks' => Environment::pretty_permalinks(),
            'cli'       => Environment::cli_command(),
            'wpCli'     => Environment::wp_cli(),
            'discoveryMode' => UrlCollector::mode(),
            // Whether a render exists yet.
            'hasRendered'   => $has_rendered,
            // Whether that render still reflects the current mode AND current
            // content — i.e. the pages list is accurate. When false the dashboard
            // offers "Process" (re-render) instead of "View list".
            'renderCurrent' => $has_rendered
                && !ChangeTracker::is_dirty()
                && ChangeTracker::rendered_mode() === UrlCollector::mode(),
            // Published pages NOT in the export — surfaced beside "View list" so an
            // in-sync export never silently hides content the user just published.
            // What "Process changes" would cover, and why Process all may be needed.
            'changes'       => self::changes(),
            'excludedPublished' => $has_rendered
                ? UrlCollector::published_excluded_count(array_keys($render))
                : 0,
            'fabEnabled'    => get_option('shipstatic_fab_enabled', '1') !== '0',
            'searchEnabled' => IndexBuilder::enabled(),
            'searchLive'    => LiveIndex::chosen(),
            // Max destinations deployed at once during a multi-destination sync.
            'concurrentSyncs' => DeployPool::concurrency(),
            // AI/MCP opt-ins. `aiAvailable` reflects whether the host WordPress
            // exposes the Abilities API at all (WP 6.9+).
            'aiAvailable'   => function_exists('wp_register_ability'),
            'aiAllowChanges' => AbilityRegistry::changes_enabled(),
            'aiAllowSync'    => AbilityRegistry::sync_enabled(),
        ]);
    }

    /**
     * GET /pages-overview — split published pages/posts into those that are in
     * the last render (Included) and those that are not (Excluded). Buckets by
     * the actual render manifest, so it reflects the export under any discovery
     * mode.
     */
    public static function pages_overview(): WP_REST_Response {
        $render   = Manifest::load(Manifest::RENDER_OPTION);
        $rendered = array_flip(array_keys($render)); // relative path => index, for O(1) lookup.
        $mode     = UrlCollector::mode();

        $included = [];
        $excluded = [];

        $types = UrlCollector::public_post_types();
        if (!empty($types)) {
            // Include unpublished statuses so the Excluded tab can explain "Not
            // published". auto-draft/trash/inherit (revisions, attachments) stay out.
            $ids = get_posts([
                'post_type'        => $types,
                'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
                'posts_per_page'   => -1,
                'fields'           => 'ids',
                'no_found_rows'    => true,
                'suppress_filters' => false,
            ]);

            foreach ($ids as $pid) {
                $pid    = (int) $pid;
                $url    = (string) get_permalink($pid);
                $rel    = $url !== '' ? Url::to_relative_path($url) : null;
                $title  = (string) get_the_title($pid);
                $title  = $title !== '' ? $title : __('(no title)', 'shipstatic');
                $status = (string) get_post_status($pid);

                if ($status === 'publish' && $rel !== null && isset($rendered[$rel])) {
                    $included[] = [
                        'title' => $title,
                        'url'   => $url,
                        'tags'  => self::page_notices((string) $rel),
                    ];
                } else {
                    $excluded[] = [
                        'title' => $title,
                        'url'   => $url,
                        'tags'  => self::exclusion_reasons($pid, $status, $mode),
                    ];
                }
            }

            $by_title = static fn(array $a, array $b): int => strcasecmp($a['title'], $b['title']);
            usort($included, $by_title);
            usort($excluded, $by_title);
        }

        return new WP_REST_Response([
            'hasRendered' => !empty($render),
            'included'    => $included,
            'excluded'    => $excluded,
        ]);
    }

    /**
     * Human-readable "won't work statically" notices for a rendered page, from a
     * fresh compatibility scan of its cached HTML (deduped by label).
     *
     * @param string $rel Render-manifest relative path (e.g. "about/index.html").
     * @return array<int,string>
     */
    private static function page_notices(string $rel): array {
        $file = Paths::output_dir() . '/' . $rel;
        if (!is_file($file)) {
            return [];
        }

        $html = (string) file_get_contents($file);
        if ($html === '') {
            return [];
        }

        $labels = [
            'form'        => __('Form submit action points to this site (PHP/REST callback won’t work)', 'shipstatic'),
            'search-form' => __('Search form posts back to this site — turn on Site search in Settings to make it work statically', 'shipstatic'),
            'php-link'    => __('Links to a PHP script on this site', 'shipstatic'),
        ];

        $tags = [];
        foreach (CompatibilityScanner::scan($html) as $issue) {
            $type  = (string) ($issue['type'] ?? '');
            $label = $labels[$type] ?? '';
            if ($label !== '' && !in_array($label, $tags, true)) {
                $tags[] = $label;
            }
        }

        return $tags;
    }

    /**
     * Why a page isn't in the static export, as display tags.
     *
     * @param int    $post_id Post id.
     * @param string $status  Post status.
     * @param string $mode    Discovery mode ('linked'|'all'|'manual').
     * @return array<int,string>
     */
    private static function exclusion_reasons(int $post_id, string $status, string $mode): array {
        if ($status !== 'publish') {
            return [__('Not published', 'shipstatic')];
        }

        // Plain permalinks: the page's URL is a query string on the site root
        // (/?page_id=9), which has no static file path — the real reason it is
        // out, whatever the discovery mode says.
        if (!Environment::pretty_permalinks()) {
            return [__('Plain permalinks', 'shipstatic')];
        }

        if ($mode === 'manual') {
            if (!UrlCollector::is_included($post_id)) {
                return [__('Not included', 'shipstatic')];
            }
            return [__('Not rendered', 'shipstatic')];
        }

        if ($mode === 'all') {
            // Every published page is seeded in "all" mode, so absence means a
            // render error.
            return [__('Not rendered', 'shipstatic')];
        }

        // 'linked' — published but not reachable by the home-page crawl.
        return [__('Not linked', 'shipstatic')];
    }

    /**
     * POST /settings — save global plugin settings.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function save_settings(WP_REST_Request $request): WP_REST_Response {
        $params = (array) $request->get_json_params();

        if (isset($params['discoveryMode'])) {
            UrlCollector::set_mode((string) $params['discoveryMode']);
        }

        if (array_key_exists('fabEnabled', $params)) {
            // Store as '1'/'0' (not a PHP bool): get_option() can't tell a stored
            // `false` from "unset", so a boolean false would always read back as
            // the `true` default and the toggle could never be turned off.
            update_option('shipstatic_fab_enabled', empty($params['fabEnabled']) ? '0' : '1', false);
        }

        if (array_key_exists('searchEnabled', $params)) {
            $on = !empty($params['searchEnabled']);
            if ($on !== IndexBuilder::enabled()) {
                IndexBuilder::set_enabled($on);
                // The render gains/loses the search script — re-Process needed.
                ChangeTracker::mark_dirty();
            }
        }

        if (array_key_exists('searchLive', $params)) {
            // Live site only — the export is unchanged, so no re-Process.
            LiveIndex::set_enabled(!empty($params['searchLive']));
        }

        if (array_key_exists('aiAllowChanges', $params)) {
            update_option(AbilityRegistry::OPT_CHANGES, !empty($params['aiAllowChanges']), false);
        }

        if (array_key_exists('aiAllowSync', $params)) {
            update_option(AbilityRegistry::OPT_SYNC, !empty($params['aiAllowSync']), false);
        }

        if (array_key_exists('concurrentSyncs', $params)) {
            DeployPool::set_concurrency((int) $params['concurrentSyncs']);
        }

        if (array_key_exists('wizardSeen', $params)) {
            // Same '1'/'0' string convention as fabEnabled above — flipped once
            // the setup wizard has been shown (or manually re-run), so it never
            // auto-opens again.
            update_option('shipstatic_wizard_seen', empty($params['wizardSeen']) ? '0' : '1', false);
        }

        return new WP_REST_Response([
            'discoveryMode'   => UrlCollector::mode(),
            'fabEnabled'      => get_option('shipstatic_fab_enabled', '1') !== '0',
            'searchEnabled'   => IndexBuilder::enabled(),
            'searchLive'      => LiveIndex::chosen(),
            'aiAllowChanges'  => AbilityRegistry::changes_enabled(),
            'aiAllowSync'     => AbilityRegistry::sync_enabled(),
            'concurrentSyncs' => DeployPool::concurrency(),
            'wizardSeen'      => get_option('shipstatic_wizard_seen', '0') !== '0',
        ]);
    }

    /**
     * What "Process changes" would do now: how many recorded changes it acts on,
     * new pages it can't add without a link, and site-wide changes it ignores.
     *
     * @return array{count:int,unlinked:array<int,string>,sitewide:array<int,string>}
     */
    private static function changes(): array {
        $plan = ChangeTracker::changed_ids() === [] ? null : ChangedContent::plan();

        return [
            'count'    => $plan === null ? 0 : ChangedContent::actionable($plan),
            'unlinked' => $plan === null ? [] : $plan['unlinked'],
            'sitewide' => ChangeTracker::sitewide_reasons(),
        ];
    }
}
