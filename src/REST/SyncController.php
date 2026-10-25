<?php
/**
 * Sync REST controller (start / tick / status / cancel).
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\REST;

use WP_REST_Request;
use WP_REST_Response;
use WPEasy\ShipStatic\CLI\Background;
use WPEasy\ShipStatic\Render\PageRenderer;
use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Sync\HtaccessBuilder;
use WPEasy\ShipStatic\Sync\ServerProbe;
use WPEasy\ShipStatic\Sync\Job;
use WPEasy\ShipStatic\Sync\ChangeTracker;
use WPEasy\ShipStatic\Sync\Manifest;
use WPEasy\ShipStatic\Sync\Runner;
use WPEasy\ShipStatic\Support\Url;

defined('ABSPATH') || exit;

/**
 * Drives the browser-driven batched run: start a job, then poll tick() until it
 * is no longer running. M2 supports the "check" (dry-run) type; "sync" (push)
 * lands in M3.
 */
final class SyncController {

    /**
     * REST namespace.
     */
    private const NS = 'shipstatic/v1';

    /**
     * Register routes.
     */
    public static function register(): void {
        register_rest_route(self::NS, '/sync/start', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'start'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/sync/tick', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'tick'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/sync/page', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'start_page'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/sync/page-status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'page_status'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/sync', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'status'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/sync/check', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'check'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/sync/cancel', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'cancel'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/sync/retry', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'retry'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/sync/claim', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'claim'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/sync/deploy-dispatch', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'deploy_dispatch'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/sync/server-config', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'server_config'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/sync/server-check', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'server_check'],
            'permission_callback' => [self::class, 'can_manage'],
            'args'                => [
                'dest' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route(self::NS, '/sync/reset', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'reset'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/sync/preflight', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'preflight'],
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
     * POST /sync/start — begin a run.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function start(WP_REST_Request $request): WP_REST_Response {
        $params = (array) $request->get_json_params();
        $type   = (string) ($params['type'] ?? 'check');

        if (!in_array($type, ['check', 'sync'], true)) {
            return new WP_REST_Response(['error' => 'Unknown run type.'], 400);
        }

        $options = ['prune' => !empty($params['prune'])];
        // Sync: re-render first even when the current render is up to date.
        if ($type === 'sync' && !empty($params['rerender'])) {
            $options['rerender'] = true;
        }
        // "Process changes": a Process (check) of only the recorded changes.
        if ($type === 'check' && !empty($params['changes'])) {
            $options['changes'] = true;
        }
        $dest    = (string) ($params['dest'] ?? '');
        if ($type === 'sync') {
            if ($dest === 'all') {
                $options['targets'] = Destinations::enabled_ids();
            } elseif ($dest !== '') {
                $options['targets'] = [$dest];
            }
        } elseif ($dest !== '') {
            // A "check" previews what would sync to this destination (diff only,
            // no upload), so it needs to know which destination to diff against.
            $options['checkDest'] = $dest;
        }

        try {
            $snapshot = Runner::start($type, $options);

            // Prefer WP-CLI where the host can spawn it (no web-worker contention);
            // otherwise the browser drives the run via curl/loopback ticks.
            $snapshot['driver'] = Background::spawn_run() ? 'cli' : 'browser';

            return new WP_REST_Response($snapshot);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['phase' => 'error', 'message' => $e->getMessage()], 200);
        }
    }

    /**
     * POST /sync/page — single-page sync: render one changed page (plus any NEW
     * internal pages it links to) and push only the changed files to the
     * destinations opted into single-page sync. Driven like a normal run.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function start_page(WP_REST_Request $request): WP_REST_Response {
        $params = (array) $request->get_json_params();
        $url    = esc_url_raw((string) ($params['url'] ?? ''));

        if ($url === '' || !Url::is_internal($url)) {
            return new WP_REST_Response(['phase' => 'error', 'message' => 'A valid internal page URL is required.'], 200);
        }
        if (empty(Destinations::single_page_ids())) {
            return new WP_REST_Response([
                'phase'   => 'error',
                'message' => 'No destinations are enabled for single-page sync. Turn on “Include in single-page sync” on a destination first.',
            ], 200);
        }

        try {
            $snapshot           = Runner::start('sync', ['only' => $url]);
            $snapshot['driver'] = Background::spawn_run() ? 'cli' : 'browser';

            return new WP_REST_Response($snapshot);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['phase' => 'error', 'message' => $e->getMessage()], 200);
        }
    }

    /**
     * GET /sync/page-status — per-destination push state for a single page, plus
     * whether single-page sync has any eligible destinations.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function page_status(WP_REST_Request $request): WP_REST_Response {
        $url = esc_url_raw((string) $request->get_param('url'));
        $rel = $url !== '' && Url::is_internal($url) ? Url::to_relative_path($url) : null;

        // Resolve the page so single-page sync can be gated to published content.
        // Prefer the caller's post id hint (the FAB/list cell knows it); fall back
        // to a reverse URL lookup. URLs that don't map to a post (home, archives)
        // are real live pages, so they count as publishable.
        $post_id = (int) $request->get_param('postId');
        if ($post_id <= 0 && $rel !== null) {
            $post_id = (int) url_to_postid($url);
        }
        $published = $post_id <= 0 || get_post_status($post_id) === 'publish';

        // Whether the page is even in the current render (else never "up to date").
        $render   = Manifest::load(Manifest::RENDER_OPTION);
        $rendered = $rel !== null && isset($render[$rel]);

        $targets     = [];
        $all_in_sync = $rendered;
        // Only destinations visible under the edition cap — a destination hidden
        // by a downgrade is preserved in storage but never offered for sync.
        foreach (Destinations::visible_objects() as $dest) {
            if (!(bool) $dest->get('enabled')) {
                continue;
            }
            $pushed  = Manifest::load(Destinations::pushed_option($dest->id()));
            // The page's path at THIS destination (inside its sub-path folder
            // where it uploads to a web root).
            $key     = $rel !== null ? Runner::deploy_key($rel, $dest) : null;
            $is_push = $key !== null && isset($pushed[$key]);
            // In sync = pushed AND the pushed copy's hash matches what THIS
            // destination would deploy — i.e. the render with its OWN replacers
            // applied (a plain render-hash comparison is wrong for a destination
            // that transforms the page: it would look out of sync forever).
            $deploy_hash = $rendered && $rel !== null ? Runner::page_deploy_hash($rel, $dest->id()) : '';
            $in_sync     = $is_push && $deploy_hash !== '' && (string) ($pushed[$key]['hash'] ?? '') === $deploy_hash;
            if (!$in_sync) {
                $all_in_sync = false;
            }
            $targets[] = [
                'id'     => $dest->id(),
                'name'   => (string) $dest->get('name'),
                'pushed' => $is_push,
                'inSync' => $in_sync,
            ];
        }

        return new WP_REST_Response([
            'rel'       => $rel,
            'targets'   => $targets,
            'canSync'   => !empty($targets),
            'published' => $published,
            // Nothing to sync: rendered, matches every target, and content hasn't
            // changed since that render. A stale render (dirty) is never up to date.
            'upToDate'  => !empty($targets) && $all_in_sync && !ChangeTracker::is_dirty(),
        ]);
    }

    /**
     * POST /sync/retry — re-upload the files that failed to push, without
     * re-rendering. Driven the same way as a fresh run.
     */
    public static function retry(): WP_REST_Response {
        try {
            $snapshot           = Runner::start_retry();
            $snapshot['driver'] = Background::spawn_run() ? 'cli' : 'browser';

            return new WP_REST_Response($snapshot);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['phase' => 'error', 'message' => $e->getMessage()], 200);
        }
    }

    /**
     * POST /sync/claim — attempt to claim the browser as the job's driver.
     * Returns the winning owner ('cli' | 'browser'); the browser only ticks if it
     * gets 'browser', so it never double-drives a live CLI process.
     */
    public static function claim(): WP_REST_Response {
        return new WP_REST_Response(['owner' => Runner::claim_driver('browser')]);
    }

    /**
     * POST /sync/tick — process one batch.
     */
    public static function tick(): WP_REST_Response {
        try {
            return new WP_REST_Response(Runner::tick());
        } catch (\Throwable $e) {
            return new WP_REST_Response(['phase' => 'error', 'message' => $e->getMessage()], 200);
        }
    }

    /**
     * GET /sync — current job snapshot.
     */
    public static function status(): WP_REST_Response {
        return new WP_REST_Response(Runner::status());
    }

    /**
     * POST /sync/deploy-dispatch — launch the parallel deploy worker pool from
     * this (web-request) context. The detached CLI coordinator can't spawn the
     * workers itself on Windows, so the dashboard calls this when a run reaches
     * the deploy phase with `needsDispatch` set. Idempotent.
     */
    public static function deploy_dispatch(): WP_REST_Response {
        return new WP_REST_Response(Runner::dispatch_workers());
    }

    /**
     * GET /sync/check — synchronous sync preview for a destination, with NO
     * render. Diffs the current render against what was last pushed and reports
     * what a Sync would change, or `needsProcess` when the render is stale/missing
     * (rendering is owned by Process + content tracking, not by Check).
     *
     * @param WP_REST_Request $request Request.
     */
    public static function check(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response(Runner::preview((string) $request->get_param('dest')));
    }

    /**
     * POST /sync/cancel — request cancellation.
     */
    public static function cancel(): WP_REST_Response {
        Runner::cancel();

        return new WP_REST_Response(Runner::status());
    }

    /**
     * POST /sync/reset — clear local sync state so the next sync re-uploads
     * everything (e.g. after switching destinations or a remote wipe).
     */
    public static function reset(): WP_REST_Response {
        Job::clear();

        // Clear EVERY destination's push record, not just the primary's — a reset
        // means "forget what's on the remotes" so the next sync uploads in full.
        // Also re-probe package-deploy capability.
        foreach (Destinations::all() as $dest) {
            $id = (string) ($dest['id'] ?? '');
            delete_option(Destinations::pushed_option($id));
            delete_option('shipstatic_pkg_off_' . $id);
            delete_option(Runner::SYNC_SIG . $id);
        }

        delete_option(Manifest::RENDER_OPTION);
        \WPEasy\ShipStatic\Sync\ChangeTracker::forget();

        // Escape hatch for a legitimate host-key change (server rebuild/migration):
        // clearing trusted SFTP host keys lets the next connection re-establish trust.
        \WPEasy\ShipStatic\Transport\SftpTransport::forget_host_key();

        return new WP_REST_Response(['ok' => true]);
    }

    /**
     * POST /sync/preflight — a one-shot loopback render of the homepage from
     * within this request. If it can't get a worker (serialized hosts like
     * Local) it times out, telling us browser-driven sync won't work here.
     */
    public static function preflight(): WP_REST_Response {
        $url            = home_url('/');
        $args           = PageRenderer::request_args($url);
        $args['timeout'] = 8;

        $start    = microtime(true);
        $response = wp_remote_get($url, $args);
        $ms       = (int) round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['ok' => false, 'ms' => $ms, 'message' => $response->get_error_message()]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        return new WP_REST_Response([
            'ok'      => $code === 200,
            'ms'      => $ms,
            'message' => $code === 200 ? "Rendered the homepage in {$ms}ms." : "Unexpected response (HTTP {$code}).",
        ]);
    }

    /**
     * GET /sync/server-config — the .htaccess (uploaded automatically), the
     * nginx snippet (for manual paste), and the last server check per destination.
     */
    public static function server_config(): WP_REST_Response {
        return new WP_REST_Response([
            'htaccess' => HtaccessBuilder::htaccess(),
            'nginx'    => HtaccessBuilder::nginx(),
            // Published automatically with every Cloudflare deploy (shown for reference).
            'cloudflareHeaders' => \WPEasy\ShipStatic\Deploy\CloudflareHeaders::headers(),
            'checks'   => (object) ServerProbe::all_last(),
        ]);
    }

    /**
     * GET /sync/server-check?dest=… — probe the destination's public URL
     * (`UrlSafety`-guarded) and report the server type + whether our cache
     * rules are in effect. Synchronous; two short requests.
     */
    public static function server_check(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response(ServerProbe::run((string) $request->get_param('dest')));
    }
}
