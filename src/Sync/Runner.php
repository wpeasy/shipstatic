<?php
/**
 * Batched sync orchestrator.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Sync;

use WPEasy\ShipStatic\CLI\Background;
use WPEasy\ShipStatic\Deploy\PackageDeployer;
use WPEasy\ShipStatic\Discovery\UrlCollector;
use WPEasy\ShipStatic\Settings\Destination;
use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Render\AssetExtractor;
use WPEasy\ShipStatic\Render\CompatibilityScanner;
use WPEasy\ShipStatic\Render\FaviconGenerator;
use WPEasy\ShipStatic\Render\HeadCleaner;
use WPEasy\ShipStatic\Render\PageRenderer;
use WPEasy\ShipStatic\Render\StableHash;
use WPEasy\ShipStatic\Render\UrlRewriter;
use WPEasy\ShipStatic\Support\Paths;
use WPEasy\ShipStatic\Support\Url;
use WPEasy\ShipStatic\Transport\FolderTransport;
use WPEasy\ShipStatic\Transport\ResumableSnapshotTransport;
use WPEasy\ShipStatic\Transport\SnapshotCapableTransport;
use WPEasy\ShipStatic\Transport\TransportFactory;
use WPEasy\ShipStatic\Transport\TransportInterface;
use WPEasy\ShipStatic\Search\IndexBuilder;
use WPEasy\ShipStatic\Search\LiveIndex;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text for JSON responses (rendered as text by the dashboard) and WP-CLI, never echoed into HTML; esc_html() here would show entities to the user.

/**
 * Drives a run as resumable batches: collect → render → assets → finalize.
 * Each tick() does a bounded slice of work and returns a UI snapshot. M2 stops
 * at a local render ("check"); M3 adds the upload phase.
 */
final class Runner {

    /**
     * Pages rendered per tick. Kept small so progress updates frequently and
     * each tick holds a worker only briefly (helps low-worker hosts).
     */
    private const PAGE_BATCH = 2;

    /**
     * Assets copied per tick.
     */
    private const ASSET_BATCH = 10;

    /**
     * Files uploaded per tick (one connection per tick, so keep it generous).
     */
    private const UPLOAD_BATCH = 15;

    /**
     * The destination home page, deferred and swapped in last during a push.
     */
    private const HOME_FILE = 'index.html';

    /**
     * Version of the render's layout. 2 = destination-neutral: files at their
     * plain paths and links carrying Url::BASE_TOKEN, both given each
     * destination's sub-path at deploy. 3 = a bare origin ("https://host", the
     * Bricks logo link) keeps its "/" — in 2 it became an empty href, i.e. the
     * current page. A render in an older layout is not current, so the
     * dashboard asks for Process (render_is_current()).
     */
    private const RENDER_FORMAT = 3;

    /**
     * Option holding the RENDER_FORMAT the current render was built with.
     */
    private const RENDER_FORMAT_OPTION = 'shipstatic_render_format';

    /**
     * WordPress's address when the render was made. The render holds source
     * URLs (sitemap, robots.txt) and strips the home sub-path it had then, so
     * a render from before an address change (moving WordPress into /wp or
     * back, a migration) isn't current: the sitemap kept "/wp/" after Undo.
     */
    private const RENDER_HOME_OPTION = 'shipstatic_render_home';

    /**
     * Site URLs the render found redirecting to another site — a link-tracking
     * or short-link plugin's "/go/partner" → 301 → partner.com — as rendered
     * link path => target. They aren't pages, and the static site has nothing
     * to count clicks or redirect, so links to them would 404 there; the Links
     * step at deploy points them straight at the target (LinkDeployReplacer).
     */
    private const RENDER_REDIRECTS_OPTION = 'shipstatic_render_redirects';

    /** When the render last finished (full or partial), for "Using the render from …". */
    private const RENDER_AT_OPTION = 'shipstatic_render_at';

    /**
     * Where a destination's pre-existing .htaccess is preserved, once.
     */
    private const HTACCESS_BAK = '.htaccess.shipstatic.bak';

    /**
     * Start a fresh run: prepare the cache, seed the queue, enter render phase.
     *
     * @param string              $type    Run type ('check' or 'sync').
     * @param array<string,mixed> $options Run options (e.g. ['prune' => true]).
     * @return array<string,mixed> Snapshot.
     * @throws \RuntimeException If the cache directory is not writable.
     */
    public static function start(string $type = 'check', array $options = []): array {
        if (!Paths::ensure()) {
            throw new \RuntimeException('The staging cache directory is not writable: ' . Paths::cache_dir());
        }

        if (\WPEasy\ShipStatic\Export\ExportRunner::is_running()) {
            throw new \RuntimeException(__('An Export ZIP is currently running — wait for it to finish, then try again.', 'shipstatic'));
        }

        // Single-page sync: push ONE changed page (and only NEW pages it links to),
        // merging into the existing render — not a full-site rebuild. So we keep the
        // existing cache + manifest and never prune.
        $only   = trim((string) ($options['only'] ?? ''));
        $single = $only !== '';

        // "Process changes": render only the posts ChangeTracker recorded (and
        // what lists them / what they newly link to), merged into the existing
        // render like a single-page run. See ChangedContent and Docs.
        $partial = !$single && !empty($options['changes']);
        $plan    = $partial ? self::partial_plan() : null;

        // Sync reuses an up-to-date render (Process all / Process changes built
        // it, nothing tracked has changed since) instead of rendering again.
        // `rerender` forces a fresh full render — for changes the tracker can't
        // see (plugin/theme updates, other plugins' options, time-based content);
        // scheduled syncs always pass it.
        if ($type === 'sync' && !$single && !$partial && empty($options['rerender']) && self::render_is_current()) {
            return self::start_from_render($options);
        }

        if (!$single && !$partial) {
            self::reset_output();
        }
        Job::clear();
        delete_option(self::CANCEL_FLAG);
        delete_option(self::HEARTBEAT);
        self::release_driver();

        $job = Job::create($type);
        // Never prune on a single-page sync — it's incremental, not a full rebuild.
        $job->data['prune'] = !$single && !$partial && !empty($options['prune']);

        // A "check" run is a dry-run preview of what would sync to one destination.
        if ($type !== 'sync') {
            $job->data['checkDest'] = (string) ($options['checkDest'] ?? '');
        }

        if ($single || $partial) {
            $job->data['singlePage'] = true;
            // Pages already in the render — don't re-crawl them; only follow links
            // to pages NOT yet here, so a page is never pushed with links to
            // internal pages that haven't been rendered/deployed.
            $job->data['known'] = array_fill_keys(array_keys(Manifest::load(Manifest::RENDER_OPTION)), true);
        }

        // Which destination(s) this run pushes to. Single-page targets the
        // destinations opted into single-page sync; otherwise 'targets' (sync all)
        // / 'destId' / primary.
        if ($single) {
            $targets = Destinations::single_page_ids();
            if (empty($targets)) {
                throw new \RuntimeException('No destinations are enabled for single-page sync.');
            }
        } else {
            $targets = self::targets_from($options);
        }
        $job->data['targets'] = $targets;
        $job->data['destId']  = $targets[0];

        if ($single) {
            $job->enqueue_page($only); // The changed page — always (re)rendered.
        } elseif ($partial) {
            $job->data['partial']       = true;
            $job->data['partialIds']    = $plan['ids'];
            $job->data['partialRemove'] = $plan['remove'];
            foreach ($plan['urls'] as $url) {
                $job->enqueue_page($url);
            }
        } else {
            // Seeds come solely from UrlCollector, which honours the discovery mode
            // ('linked' = home page only, then follow links; 'all' = + every
            // published post/term; 'manual' = only the posts whose Include switch
            // is on). The generated sitemap is built FROM the export afterwards —
            // it must not seed it, or 'Only linked pages' would still pull in
            // unlinked content (Sample Page, Hello World, …).
            $seeds = UrlCollector::collect();
            foreach ($seeds as $url) {
                $job->enqueue_page($url);
            }

            // Manual mode is an authoritative set: the crawler still runs (so each
            // included page's assets are collected), but it must never render a
            // page OUTSIDE the chosen set — an excluded page linked from an
            // included one has to stay out. tick_render() enforces this allowlist.
            if (UrlCollector::mode() === 'manual') {
                $allowed = [];
                foreach ($seeds as $url) {
                    $rel = Url::to_relative_path($url);
                    if ($rel !== null) {
                        $allowed[$rel] = true;
                    }
                }
                $job->data['lockedCrawl'] = true;
                $job->data['allowed']     = $allowed;
            }
        }

        $job->data['pageLimit']    = PHP_INT_MAX;
        $job->data['pageLimitHit'] = false;

        $job->data['phase']            = 'render';
        $job->data['message']          = 'Rendering pages…';
        $job->data['totals']['pages']  = count($job->data['queue']['pages']);
        $job->save();

        return self::snapshot($job);
    }

    /**
     * The destinations a run pushes to: 'targets' (sync all / chosen), else
     * 'destId', else the primary destination.
     *
     * @param array<string,mixed> $options Run options.
     * @return array<int,string>
     */
    private static function targets_from(array $options): array {
        $targets = isset($options['targets']) && is_array($options['targets'])
            ? array_values(array_filter(array_map('strval', $options['targets'])))
            : [];

        return $targets !== [] ? $targets : [(string) ($options['destId'] ?? Destinations::primary()->id())];
    }

    /**
     * Sync from the current render: no rendering, straight to the deploy (as a
     * retry does), sequentially or across the worker pool.
     *
     * @param array<string,mixed> $options Run options (targets/destId, prune).
     * @return array<string,mixed> Snapshot.
     */
    private static function start_from_render(array $options): array {
        Job::clear();
        delete_option(self::CANCEL_FLAG);
        delete_option(self::HEARTBEAT);
        self::release_driver();

        $job                          = Job::create('sync');
        $job->data['prune']           = !empty($options['prune']);
        $job->data['targets']         = self::targets_from($options);
        $job->data['destId']          = $job->data['targets'][0];
        $job->data['reusedRender']    = (int) get_option(self::RENDER_AT_OPTION, 0);
        $render                       = Manifest::load(Manifest::RENDER_OPTION);
        $job->data['counts']['files'] = count($render);
        $job->data['counts']['bytes'] = (int) array_sum(array_column($render, 'size'));

        if (self::parallel_eligible($job)) {
            self::begin_parallel_deploy($job);
        } else {
            $job->data['targetIndex'] = 0;
            self::start_target($job);
        }
        $job->save();

        return self::snapshot($job);
    }

    /**
     * The "Process changes" plan, refusing when only Process all will do.
     *
     * @return array{urls:array<int,string>,remove:array<int,string>,ids:array<int,int>,unlinked:array<int,string>}
     * @throws \RuntimeException With a message for the dashboard.
     */
    private static function partial_plan(): array {
        if (empty(Manifest::load(Manifest::RENDER_OPTION)) || (int) get_option(self::RENDER_FORMAT_OPTION, 0) !== self::RENDER_FORMAT || !self::render_home_current()) {
            throw new \RuntimeException(__('There is no up-to-date full render to add changes to yet. Use Process all.', 'shipstatic'));
        }
        if (ChangeTracker::rendered_mode() !== UrlCollector::mode()) {
            throw new \RuntimeException(__('Pages to include has changed since the last Process all, which affects the whole export. Use Process all.', 'shipstatic'));
        }
        $plan = ChangedContent::plan();
        if ($plan['urls'] === [] && $plan['remove'] === []) {
            if ($plan['unlinked'] !== []) {
                throw new \RuntimeException(sprintf(
                    /* translators: %s: page title(s). */
                    __('Nothing to process yet: %s isn’t linked from any page in the export, and with Pages to include set to “Only linked pages” a page only joins the export through a link. Link to it from an exported page, then use Process changes (or Process all).', 'shipstatic'),
                    '“' . implode('”, “', $plan['unlinked']) . '”'
                ));
            }
            throw new \RuntimeException(__('No content changes are recorded since the last Process. Site-wide changes (menus, templates, settings) need Process all.', 'shipstatic'));
        }

        return $plan;
    }

    /**
     * Re-upload just the files that failed to push, without re-rendering.
     *
     * Reuses the last render: start_target() rebuilds the deploy manifest from it
     * and diffs against the pushed manifest, which still excludes the previously
     * failed files — so the upload queue becomes exactly those files. Targets the
     * same destination(s) as the run that left failures.
     *
     * @return array<string,mixed> Snapshot.
     * @throws \RuntimeException If nothing has been rendered yet.
     */
    public static function start_retry(): array {
        if (!Paths::ensure()) {
            throw new \RuntimeException('The staging cache directory is not writable: ' . Paths::cache_dir());
        }

        if (empty(Manifest::load(Manifest::RENDER_OPTION))) {
            throw new \RuntimeException('Nothing has been rendered yet — run Sync first.');
        }

        $previous = Job::load();
        $targets  = ($previous !== null && !empty($previous->data['targets']))
            ? array_values(array_map('strval', $previous->data['targets']))
            : [Destinations::primary()->id()];

        Job::clear();
        delete_option(self::CANCEL_FLAG);
        delete_option(self::HEARTBEAT);
        self::release_driver();

        $job = Job::create('sync');
        $job->data['prune']       = false; // A retry only re-pushes, never deletes.
        $job->data['targets']     = $targets;
        $job->data['targetIndex'] = 0;
        self::start_target($job); // → phase 'upload', queue = the still-missing files
        $job->save();

        return self::snapshot($job);
    }

    /**
     * Open transport for the current target, cached for the lifetime of the
     * process so consecutive upload/prune ticks reuse ONE connection instead of
     * re-handshaking every batch (a real saving for FTPS, where each connect is a
     * TLS handshake + login). The CLI runner drives the whole job in one process,
     * so it keeps the connection open across all batches; a browser tick is its
     * own process, so it simply reconnects per request as before.
     */
    private static ?TransportInterface $connection = null;

    /**
     * Destination id the cached connection belongs to.
     */
    private static string $connection_dest = '';

    /**
     * Whether the process-end close handler has been registered.
     */
    private static bool $shutdown_registered = false;

    /**
     * Get (or open) the persistent transport for the job's current target.
     * Reconnects when the target changes or no connection is open yet.
     *
     * @param Job $job Active job.
     * @throws \Throwable On connection failure.
     */
    private static function target_transport(Job $job): TransportInterface {
        $dest_id = (string) ($job->data['destId'] ?? '');

        if (self::$connection !== null && self::$connection_dest === $dest_id) {
            return self::$connection;
        }

        self::close_connection(); // Different target (or stale) — drop it.

        $dest      = Destinations::get($dest_id);
        $transport = TransportFactory::make($dest !== null ? $dest->connection_config() : null);
        $transport->connect();

        self::$connection      = $transport;
        self::$connection_dest = $dest_id;

        // Close the socket cleanly when the process ends (covers a browser tick's
        // single request as well as the CLI runner finishing the job).
        if (!self::$shutdown_registered) {
            register_shutdown_function([self::class, 'close_connection']);
            self::$shutdown_registered = true;
        }

        return $transport;
    }

    /**
     * Close and forget the persistent transport, if any.
     */
    public static function close_connection(): void {
        if (self::$connection !== null) {
            try {
                self::$connection->disconnect();
            } catch (\Throwable $e) {
                // Already gone — nothing to do.
            }
            self::$connection      = null;
            self::$connection_dest = '';
        }
    }

    /**
     * Process one batch of the active job.
     *
     * @return array<string,mixed> Snapshot.
     */
    public static function tick(): array {
        $job = Job::load();
        if ($job === null) {
            return ['phase' => 'idle'];
        }

        self::beat(); // Mark the job as actively driven (see is_driver_alive()).

        // Deploy-phase cancellation is handled inside the monitor: it must NOT
        // clear the cancel flag (the worker processes still need to read it) and
        // must wait for the workers to wind down before finalizing.
        if (($job->data['phase'] ?? '') !== 'deploy' && self::is_cancelled()) {
            self::close_connection();
            // If we'd already swapped in the holding page, put the real home back.
            if (!empty($job->data['holdingShown'])) {
                self::restore_home($job);
            }
            delete_option(self::CANCEL_FLAG);
            $job->data['phase']   = 'cancelled';
            $job->data['message'] = 'Cancelled.';
            $job->save();
            return self::snapshot($job);
        }

        switch ($job->data['phase']) {
            case 'render':
                self::tick_render($job);
                break;
            case 'assets':
                self::tick_assets($job);
                break;
            case 'finalize':
                self::tick_finalize($job);
                break;
            case 'package':
                self::tick_package($job);
                break;
            case 'snapshot':
                self::tick_snapshot($job);
                break;
            case 'upload':
                self::tick_upload($job);
                break;
            case 'prune':
                self::tick_prune($job);
                break;
            case 'deploy':
                self::tick_deploy_monitor($job);
                break;
        }

        return self::snapshot($job);
    }

    /**
     * Option holding the cancel flag, kept separate from the job blob so a
     * concurrent tick's save() cannot clobber it.
     */
    private const CANCEL_FLAG = 'shipstatic_cancel';

    /**
     * Option holding the last "a driver is actively working" timestamp.
     */
    private const HEARTBEAT = 'shipstatic_driver_heartbeat';

    /**
     * Seconds since the last heartbeat after which the driver is presumed dead.
     * Generous so a single slow page render doesn't look like a stalled process.
     */
    private const HEARTBEAT_TTL = 25;

    /**
     * Option holding the last "a deploy WORKER is actively uploading" timestamp.
     * Separate from the coordinator heartbeat so worker death is detectable even
     * while the coordinator keeps monitoring (which keeps HEARTBEAT/cliAlive warm).
     */
    private const WORKER_HEARTBEAT = 'shipstatic_worker_heartbeat';

    /**
     * Seconds to wait for the deploy pool to come alive before giving up. Guards
     * against a detached parallel deploy monitoring forever when the dashboard
     * closed before dispatching (or WP-CLI can't launch the workers at all).
     */
    private const DEPLOY_STALL_LIMIT = 120;

    /**
     * Timestamp option recording when the dashboard last launched the worker pool
     * (POST /sync/deploy-dispatch). Written by the web request; READ by the
     * coordinator monitor so it can tell "workers were just asked for, give them a
     * moment" from "no workers and none requested — re-flag needsDispatch". Keeping
     * this OUT of the job blob makes the monitor the sole writer of that blob, so
     * the two processes never clobber each other's writes.
     */
    private const DISPATCH_MARK = 'shipstatic_deploy_dispatch_at';

    /**
     * Stamp the worker heartbeat (called by a deploy worker as files upload).
     */
    private static function beat_worker(): void {
        update_option(self::WORKER_HEARTBEAT, time(), false);
    }

    /**
     * Whether a deploy worker has uploaded within the heartbeat window.
     */
    private static function is_worker_alive(): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cross-process worker heartbeat; must bypass the in-memory options cache.
        $ts = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::WORKER_HEARTBEAT)
        );
        return $ts > 0 && (time() - $ts) <= self::HEARTBEAT_TTL;
    }

    /**
     * Stamp the heartbeat — called as work progresses so the dashboard can tell
     * whether SOMETHING (a spawned WP-CLI process, or its own ticks) is driving
     * the job. Lets the browser take over only when a spawn genuinely failed to
     * launch, without racing a slow-but-alive CLI process.
     */
    private static function beat(): void {
        update_option(self::HEARTBEAT, time(), false);
    }

    /**
     * Option naming the single process allowed to drive the job ('cli' | 'browser').
     */
    private const DRIVER_OPTION = 'shipstatic_driver';

    /**
     * Atomically claim the right to drive the active job. Only ONE driver may
     * advance a job at a time: on Local's tiny php-fpm pool, a browser tick AND a
     * spawned WP-CLI process both running loopback renders starves the workers and
     * deadlocks. The first caller to insert the row wins (option_name is unique);
     * everyone else reads back the existing owner and must stand down.
     *
     * @param string $who 'cli' or 'browser'.
     * @return string The winning owner (=== $who if this caller claimed it).
     */
    public static function claim_driver(string $who): string {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic driver lock; the options API can't INSERT IGNORE atomically.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::DRIVER_OPTION,
            $who
        ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads back the lock owner just written; must bypass the options cache.
        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::DRIVER_OPTION
        ));
    }

    /**
     * Release the driver claim (direct delete so a long-lived process can't serve
     * a stale cached value to the next run).
     */
    private static function release_driver(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- direct delete so a long-lived process can't serve a stale cached value.
        $wpdb->delete($wpdb->options, ['option_name' => self::DRIVER_OPTION]);
    }

    /**
     * Whether the job has been touched by a driver within the heartbeat TTL.
     * Read straight from the DB (cross-process, cache-bypassing) like the cancel
     * flag, so a polling status request sees the CLI process's latest beat.
     */
    private static function is_driver_alive(): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cross-process heartbeat read; must bypass the in-memory options cache.
        $ts = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::HEARTBEAT)
        );
        return $ts > 0 && (time() - $ts) <= self::HEARTBEAT_TTL;
    }

    /**
     * Request cancellation of the active job.
     *
     * Normally this just raises the flag and the next tick winds the job down
     * gracefully. But if NO driver is alive (the driving tab was closed, or a
     * prompted WP-CLI run never started — e.g. the user refreshed mid-run), no
     * tick will ever read the flag, so the job would sit "running" until the 90s
     * stale guard fires. In that case finalize it here so the dashboard can clear
     * the stuck progress immediately.
     */
    public static function cancel(): void {
        update_option(self::CANCEL_FLAG, '1', false);

        if (self::is_driver_alive()) {
            return;
        }

        $job = Job::load();
        if ($job !== null && !in_array($job->data['phase'], ['done', 'error', 'cancelled'], true)) {
            $job->data['phase']   = 'cancelled';
            $job->data['message'] = 'Cancelled.';
            $job->save();
        }
        delete_option(self::CANCEL_FLAG);
        self::release_driver();
    }

    /**
     * Whether cancellation has been requested.
     *
     * Read straight from the DB, NOT via get_option(): the WP-CLI background
     * runner drives the whole job inside one long-lived process, which caches
     * options in memory. get_option() would keep returning the value cached when
     * the job started and never see the flag the (separate) cancel request set.
     */
    private static function is_cancelled(): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cross-process cancel flag; get_option() would serve the value cached at job start.
        $val = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::CANCEL_FLAG)
        );
        return $val === '1';
    }

    /**
     * Seconds without progress after which a "running" job is treated as
     * abandoned (the driving tab was closed, or the process died).
     */
    private const STALE_SECONDS = 90;

    /**
     * The no-progress window before a running job is auto-discarded. Filterable
     * so a site that raised {@see shipstatic_render_timeout}/{@see shipstatic_asset_timeout}
     * above the default can keep this window comfortably larger than a single
     * request, or the watchdog could reap a job that's still working.
     */
    private static function stale_seconds(): int {
        /**
         * Filters the no-progress window (seconds) before a running sync job is
         * treated as abandoned.
         *
         * @param int $seconds Window in seconds.
         */
        return max(30, (int) apply_filters('shipstatic_stale_seconds', self::STALE_SECONDS));
    }

    /**
     * Current snapshot of the active job without advancing it.
     *
     * A running job that hasn't progressed in a while is auto-discarded so it
     * can never silently resume and hammer the server with loopback renders.
     *
     * @return array<string,mixed>
     */
    public static function status(): array {
        $job = Job::load();
        if ($job === null) {
            return ['phase' => 'idle'];
        }

        $running = !in_array($job->data['phase'], ['done', 'error', 'cancelled'], true);
        // A scheduled run driven by WP-Cron slices can pause between page loads.
        $stale = !empty($job->data['scheduled']) ? max(self::stale_seconds(), 15 * MINUTE_IN_SECONDS) : self::stale_seconds();
        if ($running && (time() - (int) $job->data['updatedAt']) > $stale) {
            Job::clear();
            delete_option(self::CANCEL_FLAG);
            return ['phase' => 'idle'];
        }

        return self::snapshot($job);
    }

    /**
     * Skip reason for a non-200 render/fetch, with a hint for the statuses that
     * have a specific, fixable cause — otherwise "HTTP 503" alone sends people
     * hunting through server logs for a switch they flipped themselves.
     * A redirect says where it went: a link-tracking or short-link plugin's
     * URL (/go/partner → 301 → partner.com) is skipped on purpose, and "HTTP
     * 301" alone read like a fault.
     *
     * @param int    $code     HTTP status code.
     * @param string $location Where an unfollowed redirect points ('' if none).
     */
    private static function http_skip_reason(int $code, string $location = ''): string {
        if ($code >= 300 && $code <= 399 && $location !== '') {
            return Url::is_internal($location)
                /* translators: 1: HTTP status code, 2: URL. */
                ? sprintf(__('HTTP %1$d — too many redirects (last to %2$s); check for a redirect loop', 'shipstatic'), $code, $location)
                /* translators: 1: HTTP status code, 2: URL on another site. */
                : sprintf(__('HTTP %1$d — redirects to another site (%2$s), e.g. a link-tracking or short link: not a page, so not exported; links to it on the static site go straight there', 'shipstatic'), $code, $location);
        }

        $hints = [
            // Bricks serves its maintenance screen with 503; other maintenance
            // plugins and WAFs use 403/401 for the same "site is closed" state.
            503 => __('site is in maintenance mode, or temporarily unavailable', 'shipstatic'),
            403 => __('blocked — maintenance mode, a security plugin, or a firewall', 'shipstatic'),
            401 => __('password protected, or behind HTTP authentication', 'shipstatic'),
        ];

        $reason = 'HTTP ' . $code;

        return isset($hints[$code]) ? $reason . ' (' . $hints[$code] . ')' : $reason;
    }

    /**
     * Remember a site URL that redirects to another site, keyed the way links
     * to it appear in the render (Url::strip_base_token() form).
     *
     * @param Job                 $job    Active job.
     * @param string              $url    The URL that was requested.
     * @param array<string,mixed> $result PageRenderer result.
     */
    private static function note_redirect(Job $job, string $url, array $result): void {
        $code = (int) ($result['code'] ?? 0);
        $to   = (string) ($result['location'] ?? '');
        if ($code < 300 || $code > 399 || $to === '' || Url::is_internal($to) || !in_array(strtolower((string) wp_parse_url($to, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return;
        }
        $key = Url::strip_base_token(UrlRewriter::rewrite($url));
        if ($key === '' || $key[0] !== '/') {
            return;
        }
        $job->data['redirects'][$key] = $to;
        // Links are written with or without the trailing slash.
        if (strpbrk($key, '?#') === false && $key !== '/') {
            $job->data['redirects'][str_ends_with($key, '/') ? rtrim($key, '/') : $key . '/'] = $to;
        }
    }

    /**
     * Site URLs the last render found redirecting to another site.
     *
     * @return array<string,string> Rendered link path => target URL.
     */
    public static function external_redirects(): array {
        return array_map('strval', (array) get_option(self::RENDER_REDIRECTS_OPTION, []));
    }

    /**
     * Render a batch of queued pages.
     *
     * @param Job $job Active job.
     */
    private static function tick_render(Job $job): void {
        $batch = array_splice($job->data['queue']['pages'], 0, self::PAGE_BATCH);

        foreach ($batch as $url) {
            if (self::is_cancelled()) {
                break; // tick() finalizes the cancelled state on the next call.
            }
            if ($job->data['counts']['pagesDone'] >= (int) ($job->data['pageLimit'] ?? PHP_INT_MAX)) {
                // Free page cap reached — stop rendering further pages.
                $job->data['pageLimitHit'] = true;
                break;
            }
            self::beat(); // Keep the heartbeat fresh through a slow batch.

            try {
                $result = PageRenderer::render($url);

                if ($result['code'] !== 200) {
                    $job->skip($url, self::http_skip_reason((int) $result['code'], (string) ($result['location'] ?? '')));
                    self::note_redirect($job, $url, $result);
                    continue;
                }

                if (stripos($result['contentType'], 'html') === false) {
                    // Not a page — treat as an asset to copy verbatim.
                    $job->enqueue_asset($url);
                    continue;
                }

                $relative = Url::to_relative_path($url);
                if ($relative === null) {
                    $job->skip($url, 'unmappable');
                    continue;
                }

                self::cache_file($job, $relative, UrlRewriter::rewrite(HeadCleaner::clean($result['body'])));
                $job->data['objects'][$relative] = (string) ($result['object'] ?? '');
                $job->data['counts']['pagesDone']++;

                $compat = CompatibilityScanner::scan($result['body']);
                if (!empty($compat) && count($job->data['compat']) < 200) {
                    $job->data['compat'][] = ['url' => $url, 'issues' => $compat];
                }

                $src_pid = Url::to_post_id($url);
                foreach (AssetExtractor::extract_links($result['body'], $url) as $link) {
                    $rel = Url::to_relative_path($link);

                    // Record the link graph BEFORE the crawl filters, so a link to
                    // an excluded page is captured too (powers the manual-mode
                    // link-integrity checks). Keyed by source post id; deduped.
                    if ($src_pid > 0 && $rel !== null) {
                        $job->data['linkGraph'][$src_pid][$rel] = true;
                    }

                    // Single-page: follow a link only if it points at a page NOT
                    // already rendered — pulling in just the new pages this change
                    // introduced, so none of them ship as a dead internal link.
                    if (!empty($job->data['singlePage']) && $rel !== null && isset($job->data['known'][$rel])) {
                        continue;
                    }
                    // Manual mode: only render pages in the authoritative allowed
                    // set, so an excluded page can't return via an internal link.
                    if (!empty($job->data['lockedCrawl']) && ($rel === null || !isset($job->data['allowed'][$rel]))) {
                        continue;
                    }
                    $job->enqueue_page($link);
                }
                foreach (AssetExtractor::extract_assets($result['body'], $url) as $asset) {
                    $job->enqueue_asset($asset);
                }
            } catch (\Throwable $e) {
                $job->error($url, $e->getMessage());
            }
        }

        // Free page cap reached: drop any remaining queued pages so the run
        // renders no more than maxPages page documents.
        if (!empty($job->data['pageLimitHit'])) {
            $job->data['queue']['pages'] = [];
        }

        $job->data['totals']['pages'] = $job->data['counts']['pagesDone'] + count($job->data['queue']['pages']);

        if (empty($job->data['queue']['pages'])) {
            $job->data['phase']           = 'assets';
            $job->data['message']         = $job->data['type'] === 'sync' ? 'Copying assets…' : 'Cataloguing assets…';
            $job->data['totals']['assets'] = count($job->data['queue']['assets']);
        }

        $job->save();
    }

    /**
     * Copy a batch of queued assets.
     *
     * @param Job $job Active job.
     */
    private static function tick_assets(Job $job): void {
        $batch = array_splice($job->data['queue']['assets'], 0, self::ASSET_BATCH);

        foreach ($batch as $url) {
            if (self::is_cancelled()) {
                break;
            }
            self::beat();

            try {
                $relative = Url::to_relative_path($url);
                if ($relative === null) {
                    $job->skip($url, 'unmappable');
                    continue;
                }

                $source = Paths::source_file($url);

                // CSS is read and rewritten (origins → root-relative) and scanned
                // for nested url()/@import background assets, then cached.
                if (self::is_css_url($relative)) {
                    $css = self::read_or_fetch_css($job, $url, $source);
                    if ($css === null) {
                        continue;
                    }
                    foreach (AssetExtractor::extract_css_assets($css, $url) as $nested) {
                        $job->enqueue_asset($nested);
                    }
                    self::cache_file($job, $relative, UrlRewriter::rewrite($css));
                    $job->data['counts']['assetsDone']++;
                    continue;
                }

                // Static asset present on disk: list its source path, never copy.
                if ($source !== null) {
                    self::plan_source($job, $relative, $source);
                    $job->data['counts']['assetsDone']++;
                    continue;
                }

                // No source file (dynamic asset): fetch and cache it.
                $result = PageRenderer::fetch_asset($url);
                if ($result['code'] !== 200) {
                    $job->skip($url, self::http_skip_reason((int) $result['code'], (string) ($result['location'] ?? '')));
                    self::note_redirect($job, $url, $result);
                    continue;
                }
                // A page that slipped into the asset queue must not be written.
                if (stripos($result['contentType'], 'html') !== false) {
                    continue;
                }
                self::cache_file($job, $relative, $result['body']);
                $job->data['counts']['assetsDone']++;
            } catch (\Throwable $e) {
                $job->error($url, $e->getMessage());
            }
        }

        $job->data['totals']['assets'] = $job->data['counts']['assetsDone'] + count($job->data['queue']['assets']);

        if (empty($job->data['queue']['assets'])) {
            $job->data['phase']   = 'finalize';
            $job->data['message'] = 'Finalising…';
        }

        $job->save();
    }

    /**
     * Build the render manifest and complete the job.
     *
     * @param Job $job Active job.
     */
    private static function tick_finalize(Job $job): void {
        if (empty($job->data['singlePage'])) {
            self::emit_extra_files($job);
            $manifest = Manifest::from_plan($job->data['plan']);
        } else {
            // Merge the (re)rendered page + any newly-linked pages/assets into the
            // existing render, leaving the rest of the site untouched. (Sitemaps
            // aren't regenerated here — the next full sync refreshes them.)
            $manifest = array_merge(Manifest::load(Manifest::RENDER_OPTION), Manifest::from_plan($job->data['plan']));
            if (!empty($job->data['partial'])) {
                // Pages whose post left the export, then the sitemap/robots for the
                // merged page set (a partial run adds or removes pages).
                foreach ((array) ($job->data['partialRemove'] ?? []) as $relative) {
                    unset($manifest[(string) $relative]);
                }
                self::emit_extra_files($job, array_keys($manifest));
                $manifest = array_merge($manifest, Manifest::from_plan($job->data['plan']));
            }
            ksort($manifest);
        }

        Manifest::save(Manifest::RENDER_OPTION, $manifest);
        update_option(self::RENDER_AT_OPTION, time(), false);
        $redirects = (array) ($job->data['redirects'] ?? []);
        update_option(self::RENDER_REDIRECTS_OPTION, empty($job->data['singlePage']) ? $redirects : array_replace(self::external_redirects(), $redirects), false);
        self::write_manifest_file($job, $manifest);
        LiveIndex::save_objects((array) ($job->data['objects'] ?? []), !empty($job->data['singlePage']), (array) ($job->data['partialRemove'] ?? []));
        if (!empty($job->data['partial'])) {
            ChangeTracker::mark_partial((array) ($job->data['partialIds'] ?? []));
        }

        // A full render reflects current content under the current mode — clear
        // the "content changed" flag and record the mode it was built under.
        // (Single-page runs are partial, so they don't reset this.)
        if (empty($job->data['singlePage'])) {
            ChangeTracker::mark_rendered(UrlCollector::mode());
            update_option(self::RENDER_FORMAT_OPTION, self::RENDER_FORMAT, false);
            update_option(self::RENDER_HOME_OPTION, home_url('/'), false);
        }

        // Persist the link graph (source post id => linked rels). Full run
        // replaces it; a single-page run merges the re-rendered pages over it.
        $built = [];
        foreach ((array) ($job->data['linkGraph'] ?? []) as $pid => $rels) {
            $built[(int) $pid] = array_keys((array) $rels);
        }
        LinkGraph::save(empty($job->data['singlePage']) ? $built : array_replace(LinkGraph::load(), $built));

        $job->data['counts']['files'] = count($manifest);

        if ($job->data['type'] !== 'sync') {
            self::finalize_check_preview($job);
            $job->save();
            return;
        }

        // Sync: deploy the shared render to each target destination. When the pool
        // is eligible (>1 target, concurrency > 1, CLI spawn available) fan the
        // deploy out across worker processes; otherwise deploy them in turn (the
        // untouched sequential path).
        if (empty($job->data['targets'])) {
            $job->data['targets'] = [Destinations::primary()->id()];
        }

        if (self::parallel_eligible($job)) {
            self::begin_parallel_deploy($job);
        } else {
            $job->data['targetIndex'] = 0;
            self::start_target($job);
        }
        $job->save();
    }

    /**
     * Whether the deploy phase should fan out across concurrent worker processes.
     * Requires a multi-destination full sync, a concurrency setting above 1, and
     * a host that can spawn WP-CLI workers (real parallelism is impossible in the
     * single-threaded browser-tick fallback).
     *
     * @param Job $job Active job.
     */
    private static function parallel_eligible(Job $job): bool {
        return empty($job->data['singlePage'])
            && count((array) ($job->data['targets'] ?? [])) > 1
            && DeployPool::concurrency() > 1
            && Background::can_spawn();
    }

    /**
     * Whether THIS process is a detached, console-less coordinator (the dashboard-
     * spawned `wp shipstatic run`). Such a process CANNOT reliably spawn further
     * background processes on Windows (`start` needs a console — the symptom is
     * "The process tried to write to a nonexistent pipe"), so it must NOT launch
     * the worker pool itself: it flags `needsDispatch` and the dashboard's poll
     * launches the workers from a web request (valid handles). A console-attached
     * `wp shipstatic sync` leaves this false and spawns directly.
     */
    private static bool $detached = false;

    /**
     * Mark this process as the detached coordinator (called by the `run` command).
     */
    public static function mark_detached(): void {
        self::$detached = true;
    }

    /**
     * Enter the parallel deploy phase: reset per-target state, mark the job
     * 'deploy', then either spawn the worker pool (console context) or flag it for
     * web-request dispatch (detached coordinator — see {@see $detached}). Each
     * worker claims a destination and deploys it independently;
     * {@see tick_deploy_monitor} aggregates progress and finalizes when done.
     *
     * @param Job $job Active job.
     */
    private static function begin_parallel_deploy(Job $job): void {
        $targets = array_values(array_map('strval', (array) $job->data['targets']));

        DeployPool::reset($targets);
        delete_option(self::WORKER_HEARTBEAT); // fresh pool — no stale liveness
        delete_option(self::DISPATCH_MARK);    // fresh pool — not yet dispatched

        $pool = min(DeployPool::concurrency(), count($targets));

        if (!self::$detached) {
            // Console context (terminal `wp shipstatic sync`) — spawn directly.
            $spawned = DeployPool::spawn_workers($pool);
            if ($spawned < 1) {
                // Spawning failed outright — fall back to sequential so the sync
                // still completes (just one destination at a time).
                $job->data['targetIndex'] = 0;
                self::start_target($job);
                return;
            }
        }

        $job->data['phase']           = 'deploy';
        $job->data['parallel']        = true;
        $job->data['poolSize']        = $pool;
        $job->data['deployStartedAt'] = time();
        // Detached: workers not spawned yet — the dashboard dispatches them.
        $job->data['needsDispatch']   = self::$detached;
        $job->data['lastSpawnAt']     = time();
        $job->data['parallelTargets'] = [];
        $job->data['message']         = sprintf('Deploying to %d destinations (%d at a time)…', count($targets), $pool);
    }

    /**
     * Spawn (or re-spawn) the worker pool from THIS request's context. Called by
     * the dashboard (`POST /sync/deploy-dispatch`) because the detached coordinator
     * can't spawn workers itself. Idempotent + safe to call repeatedly: the atomic
     * per-target claim prevents any destination being deployed twice.
     *
     * @return array{ok:bool,spawned:int,phase:string}
     */
    public static function dispatch_workers(): array {
        $job = Job::load();
        if ($job === null) {
            return ['ok' => false, 'spawned' => 0, 'phase' => 'idle'];
        }

        $phase = (string) ($job->data['phase'] ?? '');
        if ($phase !== 'deploy' || empty($job->data['needsDispatch'])) {
            return ['ok' => false, 'spawned' => 0, 'phase' => $phase];
        }

        $spawned = DeployPool::spawn_workers((int) ($job->data['poolSize'] ?? 1));

        // Record the dispatch time in a SEPARATE option (not the job blob) so we
        // never clobber the coordinator monitor's concurrent progress writes. The
        // monitor reads this to flip needsDispatch off once workers are launched.
        update_option(self::DISPATCH_MARK, time(), false);

        return ['ok' => $spawned > 0, 'spawned' => $spawned, 'phase' => 'deploy'];
    }

    /**
     * Coordinator monitor for the parallel deploy phase. Does NO uploading — the
     * worker processes do that; this aggregates their per-target state into the
     * job snapshot, re-spawns the pool if the workers died before finishing, and
     * flips the job to done/error once every target is finished.
     *
     * @param Job $job Active job.
     */
    private static function tick_deploy_monitor(Job $job): void {
        $targets = array_values(array_map('strval', (array) ($job->data['targets'] ?? [])));
        $agg     = DeployPool::aggregate($targets);

        $job->data['counts']['uploaded'] = $agg['uploaded'];
        $job->data['counts']['pruned']   = $agg['pruned'];
        $job->data['totals']['uploads']  = $agg['totalUploads'];
        $job->data['failed']             = $agg['failed'];
        $job->data['targetsDone']        = $agg['done'];
        $job->data['parallelTargets']    = $agg['perTarget'];
        if (!empty($agg['zipUnavailable'])) {
            $job->data['zipUnavailable'] = true;
        }
        if (!empty($agg['packageFallback'])) {
            $job->data['packageFallback'] = (string) $agg['packageFallback'];
        }

        // Cancellation: the workers stop claiming/uploading as soon as they see
        // the flag (they check it every batch), so we just wait for them to go
        // quiet — once no worker has beat within the heartbeat window they've all
        // wound down — then finalize. The cancel flag is left for start() to clear
        // on the next run so no in-flight worker misses it.
        if (self::is_cancelled()) {
            if (!self::is_worker_alive()) {
                $job->data['phase']   = 'cancelled';
                $job->data['message'] = 'Cancelled.';
                DeployPool::cleanup($targets);
            } else {
                $job->data['message'] = 'Cancelling…';
            }
            $job->save();
            if (PHP_SAPI === 'cli') {
                usleep(300000);
            }
            return;
        }

        if ($agg['allFinished']) {
            $job->data['phase'] = $agg['anyError'] ? 'error' : 'done';
            if ($agg['anyError']) {
                // Name the destination(s) that actually failed — each one's own
                // message (the real reason, e.g. "FTPS authentication failed")
                // is still available per-target in parallelTargets for the UI's
                // error-details view; this top-level line is just the summary.
                $failed_names = array_values(array_map(
                    static fn(array $t): string => (string) $t['name'],
                    array_filter($agg['perTarget'], static fn(array $t): bool => ($t['status'] ?? '') === 'error')
                ));
                $job->data['message'] = $failed_names !== []
                    ? sprintf('Failed to deploy to %s — click the status badge for details.', implode(', ', $failed_names))
                    : 'Some destinations failed to deploy — re-run Sync to retry.';
            } else {
                $job->data['message'] = self::done_message($job);
            }
            DeployPool::cleanup($targets);
            $job->save();
            return;
        }

        // Give up rather than monitor forever if the pool never came alive (e.g.
        // the dashboard tab was closed before it could dispatch, or WP-CLI can't
        // actually launch the workers). is_worker_alive() stays true while workers
        // upload, so this only fires when NO worker beat for the whole window.
        if (!self::is_worker_alive() && (time() - (int) ($job->data['deployStartedAt'] ?? time())) > self::DEPLOY_STALL_LIMIT) {
            $job->data['phase']   = 'error';
            $job->data['message'] = 'Deploy workers did not start — check WP-CLI, or lower Concurrent syncs and re-run.';
            DeployPool::cleanup($targets);
            $job->save();
            return;
        }

        // Resilience + dispatch coordination. The monitor is the SOLE writer of
        // needsDispatch (the dashboard only records a dispatch marker), so these
        // two processes never clobber each other.
        if (self::is_worker_alive()) {
            $job->data['needsDispatch'] = false; // pool is up and uploading
        } elseif (self::$detached) {
            // Detached coordinator can't spawn — ask the dashboard, unless we just
            // asked (give the freshly-launched workers a moment to start beating).
            $dispatched = (int) get_option(self::DISPATCH_MARK, 0);
            $recent     = $dispatched > 0 && (time() - $dispatched) <= self::HEARTBEAT_TTL;
            $job->data['needsDispatch'] = !$recent;
        } elseif ((time() - (int) ($job->data['lastSpawnAt'] ?? 0)) > self::HEARTBEAT_TTL) {
            // Console context — re-spawn directly. The atomic claim keeps any
            // destination from being deployed twice.
            DeployPool::spawn_workers((int) ($job->data['poolSize'] ?? 1));
            $job->data['lastSpawnAt'] = time();
        }

        $job->save();

        // Throttle a CLI coordinator's tight monitor loop (the browser poll has
        // its own interval, so this only paces the detached CLI watcher).
        if (PHP_SAPI === 'cli') {
            usleep(300000); // 0.3s
        }
    }

    /**
     * Worker-process entry point: claim and deploy destinations until none are
     * left unclaimed (or the run is cancelled). Each claimed destination is
     * deployed to completion over its own connection, reusing the same
     * package/upload/prune machinery as the sequential path — just scoped to a
     * private per-target job blob so concurrent workers never collide.
     */
    public static function run_deploy_worker(): void {
        $token = DeployPool::worker_token();

        while (!self::is_cancelled()) {
            $dest_id = DeployPool::claim_next_target($token);
            if ($dest_id === null) {
                break; // Every target is owned by a worker — nothing left to do.
            }
            self::deploy_target_worker($dest_id);
        }

        self::close_connection();
    }

    /**
     * Deploy a single destination to completion inside a worker, driving a
     * private per-target job through the existing package/upload/prune ticks.
     *
     * @param string $dest_id Destination id.
     */
    private static function deploy_target_worker(string $dest_id): void {
        $main   = Job::load();
        $prune  = $main !== null && !empty($main->data['prune']);
        $option = DeployPool::target_option($dest_id);

        $job = Job::create('sync', $option);
        $job->data['parallel']    = true;
        $job->data['prune']       = $prune;
        $job->data['targets']     = [$dest_id];
        $job->data['targetIndex'] = 0;
        self::start_target($job); // → phase 'snapshot'|'package'|'upload' for THIS destination
        $job->save();

        while (!in_array($job->data['phase'], ['done', 'error', 'cancelled'], true)) {
            if (self::is_cancelled()) {
                self::close_connection();
                $job->data['phase']   = 'cancelled';
                $job->data['message'] = 'Cancelled.';
                $job->save();
                return;
            }

            self::beat_worker(); // Mark this worker alive (separate from cliAlive).

            switch ($job->data['phase']) {
                case 'package':
                    self::tick_package($job);
                    break;
                case 'snapshot':
                    self::tick_snapshot($job);
                    break;
                case 'upload':
                    self::tick_upload($job);
                    break;
                case 'prune':
                    self::tick_prune($job);
                    break;
                default:
                    // Unknown phase — bail rather than spin.
                    $job->data['phase'] = 'error';
                    $job->save();
                    break 2;
            }
        }

        self::close_connection();
    }

    /**
     * Finish a "check" run as a sync preview: diff the destination's deploy
     * manifest against what was last pushed to it, and report what a Sync would
     * change — WITHOUT connecting or uploading. Uses the locally-stored pushed
     * manifest, so it works offline and before the first sync (everything counts
     * as "to upload").
     *
     * @param Job $job Active job.
     */
    private static function finalize_check_preview(Job $job): void {
        $preview = self::compute_check_preview(
            (string) ($job->data['checkDest'] ?? ''),
            !empty($job->data['prune'])
        );

        $job->data['preview'] = $preview;
        $job->data['phase']   = 'done';
        $job->data['message'] = self::check_message($preview);
    }

    /**
     * Synchronous sync preview for a destination — WITHOUT rendering. Rendering
     * is owned by Process (a "check" run) and the content-change tracker, so a
     * Check just diffs the CURRENT render manifest against what was last pushed
     * to the destination and reports what a Sync would change.
     *
     * When the render is missing, or no longer reflects the current discovery
     * mode / content, it returns ['needsProcess' => true] rather than silently
     * re-rendering — the caller should run Process first.
     *
     * @param string $dest_id Destination id ('' or 'all' → primary).
     * @return array<string,mixed>
     */
    /**
     * Whether the render was made at WordPress's current address (a render
     * from before this was recorded counts as current).
     */
    private static function render_home_current(): bool {
        $home = (string) get_option(self::RENDER_HOME_OPTION, '');

        return $home === '' || $home === home_url('/');
    }

    /**
     * Whether the current render manifest still reflects the current
     * discovery mode + content — i.e. Process/Sync would have nothing new to
     * render. Shared by {@see preview()} (Check) and Export ZIP, which both
     * need the identical "needsProcess" gate.
     */
    public static function render_is_current(): bool {
        return !empty(Manifest::load(Manifest::RENDER_OPTION))
            && (int) get_option(self::RENDER_FORMAT_OPTION, 1) === self::RENDER_FORMAT
            && self::render_home_current()
            && !ChangeTracker::is_dirty()
            && ChangeTracker::rendered_mode() === UrlCollector::mode();
    }

    public static function preview(string $dest_id): array {
        if ($dest_id === '' || $dest_id === 'all') {
            $dest_id = Destinations::primary()->id();
        }
        $dest = Destinations::get($dest_id);
        $name = $dest !== null ? (string) $dest->get('name') : $dest_id;

        // Mirrors StatusController's renderCurrent: a render exists AND still
        // reflects the current mode + content (the page list is accurate).
        $has_rendered   = !empty(Manifest::load(Manifest::RENDER_OPTION));
        $render_current = self::render_is_current();

        if (!$render_current) {
            return [
                'needsProcess' => true,
                'destId'       => $dest_id,
                'destName'     => $name,
                'message'      => $has_rendered
                    ? __('The page list has changed since the last render. Click Process to refresh it, then Check again.', 'shipstatic')
                    : __('Nothing has been rendered yet. Click Process first, then Check.', 'shipstatic'),
            ];
        }

        $preview = self::compute_check_preview($dest_id, true);
        $preview['needsProcess'] = false;
        $preview['message']      = self::check_message($preview);

        return $preview;
    }

    /**
     * Diff the destination's deploy manifest (built from the current render)
     * against what was last pushed to it. Shared by the synchronous {@see
     * preview()} and the legacy "check" run's {@see finalize_check_preview()}.
     *
     * @param string $dest_id Destination id ('' or 'all' → primary).
     * @param bool   $prune   Whether removals should be counted.
     * @return array{destId:string,destName:string,upload:int,remove:int,total:int,inSync:bool,excludedPublished:int}
     */
    private static function compute_check_preview(string $dest_id, bool $prune): array {
        if ($dest_id === '' || $dest_id === 'all') {
            $dest_id = Destinations::primary()->id();
        }
        $dest = Destinations::get($dest_id);

        $render = Manifest::load(Manifest::RENDER_OPTION);
        $deploy = self::build_deploy_manifest($render, $dest_id);
        $diff   = Manifest::diff(Manifest::load(Destinations::pushed_option($dest_id)), $deploy);

        return [
            'destId'   => $dest_id,
            'destName' => $dest !== null ? (string) $dest->get('name') : $dest_id,
            'upload'   => count($diff['changed']),
            'remove'   => $prune ? count($diff['removed']) : 0,
            'total'    => count($deploy),
            'inSync'   => self::in_sync($dest_id, $dest),
            // Published pages that aren't in the export at all — so an "in sync"
            // result never silently hides content the user just published.
            'excludedPublished' => UrlCollector::published_excluded_count(array_keys($render)),
        ];
    }

    /**
     * Human-readable summary of a check preview.
     *
     * @param array<string,mixed> $p Preview data.
     */
    private static function check_message(array $p): string {
        $name   = (string) $p['destName'];
        $upload = (int) $p['upload'];
        $remove = (int) $p['remove'];

        if ($upload === 0 && $remove === 0) {
            $base = !empty($p['inSync'])
                ? sprintf('%s is up to date — nothing to sync.', $name)
                : sprintf('%s: no file changes, but settings differ — Sync to refresh.', $name);
        } else {
            $parts = [];
            if ($upload > 0) {
                $parts[] = sprintf('%d file(s) to upload', $upload);
            }
            if ($remove > 0) {
                $parts[] = sprintf('%d to remove', $remove);
            }
            $base = sprintf('%s: %s on next sync.', $name, implode(', ', $parts));
        }

        // Never let an "in sync" reading hide published pages that aren't in the
        // export (orphan pages in linked mode, etc.).
        $excluded = (int) ($p['excludedPublished'] ?? 0);
        if ($excluded > 0) {
            $base .= ' ' . sprintf(
                /* translators: %d: number of published pages not in the static export. */
                _n(
                    '%d published page isn’t in the export — see View list.',
                    '%d published pages aren’t in the export — see View list.',
                    $excluded,
                    'shipstatic'
                ),
                $excluded
            );
        }

        return $base;
    }

    /**
     * Add generated/auxiliary root files to the upload plan.
     *
     * The favicon is always emitted (a Free feature). Other root files —
     * sitemap.xml + robots.txt — come from emitters registered on the
     * {@see Pipeline} (the Pro addon registers the sitemap generator). Each
     * emitter receives the list of exported page URLs (absolute-to-SOURCE) and
     * returns relativePath => contents; build_deploy_manifest() rewrites any
     * origin-absolute file to each destination's origin.
     *
     * @param Job                    $job       Active job.
     * @param array<int,string>|null $relatives Pages to list (default: this run's plan).
     */
    private static function emit_extra_files(Job $job, ?array $relatives = null): void {
        $emitters = Pipeline::extra_file_emitters();
        if (!empty($emitters)) {
            $page_urls = [];
            foreach ($relatives ?? array_keys($job->data['plan']) as $rel) {
                if (substr(strtolower($rel), -5) === '.html') {
                    $page_urls[] = self::url_for_relative($rel);
                }
            }
            foreach ($emitters as $emit) {
                foreach ((array) $emit($page_urls) as $relative => $contents) {
                    self::cache_file($job, (string) $relative, (string) $contents);
                }
            }
        }

        self::emit_favicon($job);
    }

    /**
     * Map a cached relative HTML path back to its absolute source URL.
     *
     * @param string $relative e.g. "about/index.html" or "index.html".
     */
    private static function url_for_relative(string $relative): string {
        $path = (string) preg_replace('#/?index\.html$#i', '/', $relative);
        $path = ltrim($path, '/');

        return ($path === '' || $path === '/') ? home_url('/') : home_url('/' . $path);
    }

    /**
     * Plan the favicon for upload. Prefers a real /favicon.ico shipped at the web
     * root; otherwise creates /favicon.ico from the WordPress Site Icon if one is
     * set. (Linked <link rel="icon"> images already upload via the crawl — this
     * is the root fallback browsers request directly.)
     *
     * @param Job $job Active job.
     */
    private static function emit_favicon(Job $job): void {
        /** Filters whether a root /favicon.ico is included in the export. */
        if (!apply_filters('shipstatic_include_favicon', true) || isset($job->data['plan']['favicon.ico'])) {
            return;
        }

        // A real favicon.ico at the web root takes precedence.
        $root_ico = Paths::source_file(home_url('/favicon.ico'));
        if ($root_ico !== null && is_file($root_ico)) {
            self::plan_source($job, 'favicon.ico', $root_ico);
            return;
        }

        // Otherwise derive /favicon.ico from the Site Icon (Customizer), if any.
        $icon_url = function_exists('get_site_icon_url') ? (string) get_site_icon_url() : '';
        if ($icon_url !== '') {
            $icon_src = Paths::source_file($icon_url);
            if ($icon_src !== null && is_file($icon_src)) {
                self::plan_source($job, 'favicon.ico', $icon_src);
                return;
            }
        }

        // Last resort: generate a plain default so the export always has a favicon.
        $ico = FaviconGenerator::ico();
        if ($ico !== '') {
            self::cache_file($job, 'favicon.ico', $ico);
        }
    }

    /**
     * Whether a relative path holds absolute source URLs that must be rewritten to
     * the destination's origin (a sitemap or robots.txt) rather than made
     * root-relative like every other document.
     *
     * @param string $relative Relative path.
     */
    private static function is_origin_absolute_file(string $relative): bool {
        $name = strtolower(basename($relative));

        return $name === 'robots.txt'
            || (substr($name, -4) === '.xml' && strpos($name, 'sitemap') === 0);
    }

    /**
     * Write a transformed file into the per-destination deploy dir, with a gzip
     * sibling when compressible. Returns its manifest meta, or null on failure.
     *
     * @param string $deploy_dir Per-destination deploy directory.
     * @param string $relative   Relative path.
     * @param string $content    Transformed contents.
     * @return array{size:int,hash:string,src:string}|null
     */
    private static function write_deploy_file(string $deploy_dir, string $relative, string $content): ?array {
        // Defence in depth: a key that climbs would write outside the deploy
        // folder. Page keys already passed Url::to_relative_path(), and a
        // destination's sub-path prefix is cleaned (Url::clean_path()).
        if (Url::has_traversal($relative)) {
            return null;
        }

        $path = $deploy_dir . '/' . $relative;
        $dir  = dirname($path);

        if ((!is_dir($dir) && !wp_mkdir_p($dir)) || file_put_contents($path, $content) === false) {
            return null;
        }
        if (Compressor::is_compressible($relative)) {
            Compressor::write_sibling($path);
        }

        return ['size' => strlen($content), 'hash' => StableHash::of_html($content), 'src' => wp_normalize_path($path)];
    }

    /**
     * Begin deploying to the current target: build its deploy manifest, compute
     * the delta, and reset the per-target upload state.
     *
     * @param Job $job Active job.
     */
    private static function start_target(Job $job): void {
        $dest_id = (string) ($job->data['targets'][$job->data['targetIndex']] ?? '');
        $job->data['destId'] = $dest_id;

        $dest = Destinations::get($dest_id);
        $name = $dest !== null ? (string) $dest->get('name') : $dest_id;

        $deploy = self::build_deploy_manifest(Manifest::load(Manifest::RENDER_OPTION), $dest_id);
        Manifest::save(self::deploy_option($job), $deploy);

        $diff = Manifest::diff(Manifest::load(self::pushed_option($job)), $deploy);

        $job->data['queue']['uploads']   = $diff['changed'];
        $job->data['removedFiles']       = $diff['removed'];
        $job->data['removed']            = count($diff['removed']);
        $job->data['totals']['uploads']  = count($diff['changed']);
        $job->data['counts']['uploaded'] = 0;
        $job->data['counts']['pruned']   = 0;
        $job->data['failed']             = [];
        $job->data['htaccessDone']       = false;
        $job->data['holdingShown']       = false;

        // A snapshot destination (Cloudflare) publishes the WHOLE deploy
        // manifest every time and has no filesystem: no package, no per-file
        // upload, no prune (see tick_snapshot()). The diff above still drives
        // the progress counts and the Check preview.
        if ($dest !== null && $dest->is_snapshot()) {
            $job->data['phase']   = 'snapshot';
            $job->data['message'] = sprintf('Publishing to %s…', $name);
        } elseif (self::should_package($dest_id)) {
            // Prefer the package strategy (one zip + server-side extract) when
            // the host can run it; otherwise fall back to per-file uploads.
            $job->data['phase']   = 'package';
            $job->data['message'] = sprintf('Packaging deploy for %s…', $name);
        } else {
            $job->data['phase']   = 'upload';
            $job->data['message'] = sprintf('Uploading to %s…', $name);
            // This destination is set up for package deploy, but the PHP runtime
            // running the sync has no ZipArchive — so we fall back to (slower)
            // file-by-file. Flag it so the dashboard can explain WHY. Common on
            // Local's bundled CLI PHP; most production hosts have zip.
            if (PackageDeployer::would_package($dest_id, $dest) && !PackageDeployer::can_build()) {
                $job->data['zipUnavailable'] = true;
            }
        }
    }

    /**
     * Whether to deploy to a destination as a single package (see
     * PackageDeployer::available_for).
     */
    private static function should_package(string $dest_id): bool {
        return PackageDeployer::available_for($dest_id, Destinations::get($dest_id));
    }

    /**
     * Advance to the next target destination, or finish.
     *
     * @param Job $job Active job.
     */
    private static function advance_target(Job $job): void {
        $job->data['targetsDone']++;
        $job->data['targetIndex']++;

        if ($job->data['targetIndex'] < count($job->data['targets'])) {
            self::start_target($job);
            return;
        }

        $job->data['phase']   = 'done';
        $job->data['message'] = self::done_message($job);
    }

    /**
     * Public seam for Export ZIP: the destination-scoped deploy manifest (base
     * render + that destination's active replacers + sitemap/robots origin
     * rewrite) — the same content a real Sync would push. Pure passthrough to
     * {@see build_deploy_manifest()}; see its docblock for side effects (it
     * writes into cache/shipstatic/deploy/{destId}/ when replacers are
     * active — the same dir a real Sync already uses, not export-specific).
     *
     * @param array<string,array{size:int,hash:string,src:string}> $base    Base render manifest.
     * @param string                                                $dest_id Target destination id.
     * @return array<string,array{size:int,hash:string,src:string}>
     */
    public static function deploy_manifest_for(array $base, string $dest_id): array {
        return self::build_deploy_manifest($base, $dest_id);
    }

    /**
     * Build the deploy manifest for a destination: the base render with every
     * registered {@see DeployReplacer} applied to each HTML page and the
     * destination's sub-path filled into every page and stylesheet
     * (Url::rebase(); written to a per-destination deploy dir), plus any extra
     * files those replacers need uploaded (e.g. swapped-in media variants).
     * Keys are relocated into the destination's sub-path folder where it
     * uploads to a web root (file_prefix()). Files that come out unchanged
     * reference the base render.
     *
     * @param array<string,array{size:int,hash:string,src:string}> $base    Base render manifest.
     * @param string                                                $dest_id Target destination id.
     * @return array<string,array{size:int,hash:string,src:string}>
     */
    private static function build_deploy_manifest(array $base, string $dest_id): array {
        $dest = $dest_id !== '' ? Destinations::get($dest_id) : Destinations::primary();
        if ($dest === null) {
            return $base; // Unknown destination — deploy the base render verbatim.
        }

        $dest_base   = PackageDeployer::base_url($dest);
        $served      = Url::normalize_base($dest->served_base_path());
        $file_prefix = self::file_prefix($dest);

        // Prepare each registered replacer once for this destination. An inactive
        // replacer (null ctx) is skipped; active ones contribute a transform
        // context and any extra files to add to the upload manifest.
        $active = []; // list of ['replacer' => DeployReplacer, 'ctx' => mixed]
        $extra  = []; // manifest key => local source file
        foreach (Pipeline::replacers() as $replacer) {
            $prepared = $replacer->prepare($dest, $dest_base);
            if (($prepared['ctx'] ?? null) === null) {
                continue;
            }
            $active[] = ['replacer' => $replacer, 'ctx' => $prepared['ctx']];
            foreach ((array) ($prepared['files'] ?? []) as $key => $src) {
                $extra[(string) $key] = (string) $src;
            }
        }

        $deploy_dir = Paths::cache_dir() . '/deploy/' . $dest->id();
        self::reset_dir($deploy_dir);

        // Site search: one record per page, read from this destination's FINAL
        // page (after its replacements and sub-path), so its index has its own
        // wording and URLs.
        $search  = IndexBuilder::enabled();
        $records = [];

        // Every page and stylesheet carries the destination-base placeholder,
        // so each destination gets its own copy with its sub-path filled in
        // (and its replacements applied). Other files deploy from the render.
        $out = [];
        foreach ($base as $relative => $meta) {
            $key = self::relocate($relative, $file_prefix);

            // Sitemap/robots: source URLs → this destination's origin + sub-path.
            if (self::is_origin_absolute_file($relative)) {
                if ($dest_base !== '' && is_file($meta['src'])) {
                    $content   = UrlRewriter::to_absolute((string) file_get_contents($meta['src']), $dest_base, $served);
                    $out[$key] = self::write_deploy_file($deploy_dir, $key, $content) ?? $meta;
                } else {
                    $out[$key] = $meta;
                }
                continue;
            }

            $ext = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));
            if (!in_array($ext, ['html', 'css'], true) || !is_file($meta['src'])) {
                $out[$key] = $meta;
                continue;
            }

            $original    = (string) file_get_contents($meta['src']);
            $transformed = $original;
            if ($ext === 'html') {
                foreach ($active as $a) {
                    $transformed = $a['replacer']->apply($transformed, $a['ctx'], $relative);
                }
            }
            // Last, after the replacers (their keys match the render as-is).
            $transformed = Url::rebase($transformed, $served);

            if ($search && $ext === 'html') {
                $record = IndexBuilder::record($transformed, $relative, $served);
                if ($record !== null) {
                    $records[] = $record;
                }
            }

            // Only write a per-destination copy when the file actually changed;
            // otherwise reference the base render (no extra file, no churn).
            $out[$key] = $transformed === $original
                ? $meta
                : (self::write_deploy_file($deploy_dir, $key, $transformed) ?? $meta);
        }

        // Add the replacement files (if not already in the render).
        foreach ($extra as $rel => $src) {
            $key = self::relocate($rel, $file_prefix);
            if (!isset($out[$key]) && is_file($src)) {
                $out[$key] = ['size' => (int) filesize($src), 'hash' => StableHash::of_file($src), 'src' => wp_normalize_path($src)];
            }
        }

        if ($search) {
            $key   = self::relocate(IndexBuilder::FILE, $file_prefix);
            $index = self::write_deploy_file($deploy_dir, $key, IndexBuilder::encode($records));
            if ($index !== null) {
                $out[$key] = $index;
            }
        }

        return $out;
    }

    /**
     * Replacers that only make sense on a whole exported page: Media and
     * Videos are saved per page, and the Bricks relay edits `bricksData`.
     */
    private const PAGE_ONLY_REPLACERS = ['media', 'video', 'bricksRelay'];

    /**
     * Turn HTML or CSS that WordPress produced just now (a Bricks query
     * result sent through the relay) into what it would be on a destination's
     * pages: links to this site rewritten as the render does, then the
     * destination's whole-export replacements (text, links), then its
     * sub-path — the same order as build_deploy_manifest().
     *
     * @param string      $content HTML or CSS.
     * @param Destination $dest    Destination.
     * @param string      $ext     'html' or 'css'.
     */
    public static function transform_for_destination(string $content, Destination $dest, string $ext = 'html'): string {
        static $prepared = [];

        $content = UrlRewriter::rewrite($content);
        if ($ext === 'html') {
            if (!isset($prepared[$dest->id()])) {
                $prepared[$dest->id()] = [];
                $dest_base             = PackageDeployer::base_url($dest);
                foreach (Pipeline::replacers() as $replacer) {
                    if (in_array($replacer->key(), self::PAGE_ONLY_REPLACERS, true)) {
                        continue;
                    }
                    $ctx = $replacer->prepare($dest, $dest_base)['ctx'] ?? null;
                    if ($ctx !== null) {
                        $prepared[$dest->id()][] = [$replacer, $ctx];
                    }
                }
            }
            foreach ($prepared[$dest->id()] as [$replacer, $ctx]) {
                $content = $replacer->apply($content, $ctx, '');
            }
        }

        return Url::rebase($content, Url::normalize_base($dest->served_base_path()));
    }

    /**
     * Folder a destination's files go in, relative to where it uploads: its
     * served sub-path ("blog/" for a site at example.com/blog/), or "" when it
     * is served from the domain root — or publishes with the sub-path as its
     * root (a GitHub project site's repository root IS /repo/).
     *
     * @param Destination $dest Destination.
     */
    private static function file_prefix(Destination $dest): string {
        $served = Url::normalize_base($dest->served_base_path());
        if ($served === '' || TransportFactory::root_is_served_path((string) $dest->get('transport'))) {
            return '';
        }

        return ltrim($served, '/') . '/';
    }

    /**
     * A render path's path at the destination. robots.txt and favicon.ico
     * stay at the upload root: they are only ever requested from a domain's
     * root, so a site in a sub-folder still needs them there.
     *
     * @param string $relative Render-relative path.
     * @param string $prefix   From file_prefix().
     */
    private static function relocate(string $relative, string $prefix): string {
        if ($prefix === '' || in_array(strtolower($relative), ['robots.txt', 'favicon.ico'], true)) {
            return $relative;
        }

        return $prefix . $relative;
    }

    /**
     * Where a render-relative path lives at a destination (its key in that
     * destination's deploy and pushed manifests).
     *
     * @param string      $relative Render-relative path.
     * @param Destination $dest     Destination.
     */
    public static function deploy_key(string $relative, Destination $dest): string {
        return self::relocate($relative, self::file_prefix($dest));
    }

    /**
     * Recursively empty (and create) a directory.
     *
     * @param string $dir Directory.
     */
    private static function reset_dir(string $dir): void {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes an emptied directory inside the plugin's own cache dir.
            $item->isDir() ? @rmdir($item->getPathname()) : wp_delete_file($item->getPathname());
        }
    }

    /**
     * Drop a human-readable manifest.json in the cache root (a convenience copy
     * of the DB manifest; the database remains the source of truth). It lives
     * beside the staging guards, outside the uploaded site/ tree.
     *
     * @param Job                                                    $job      Active job.
     * @param array<string,array{size:int,hash:string,src:string}>   $manifest Manifest.
     */
    private static function write_manifest_file(Job $job, array $manifest): void {
        $cache_root = wp_normalize_path(Paths::output_dir());
        $entries    = [];

        foreach ($manifest as $relative => $meta) {
            $entries[$relative] = [
                'size'   => $meta['size'],
                'hash'   => $meta['hash'],
                'origin' => strpos($meta['src'], $cache_root) === 0 ? 'cache' : 'source',
                // No 'src': absolute server paths (they reveal the account name)
                // don't belong in a file that can end up public.
            ];
        }

        $document = [
            'generatedAt' => time(),
            'type'        => $job->data['type'],
            'files'       => count($manifest),
            'bytes'       => $job->data['counts']['bytes'],
            'entries'     => $entries,
        ];

        $json = wp_json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents(Paths::cache_dir() . '/manifest.json', $json);
        }
    }

    /**
     * The DEPLOY hash of one page for one destination — the base render with that
     * destination's active replacers applied (media/links/videos/text), hashed the
     * same way {@see write_deploy_file()} does. This is what a page's PUSHED-manifest
     * hash is compared against to tell if the page is in sync there. A plain
     * render-hash comparison is WRONG for a destination that transforms the page
     * (it would look permanently out of sync). Returns '' when the page isn't in
     * the current render. No side effects (unlike build_deploy_manifest).
     *
     * @param string $rel     Export-relative page path.
     * @param string $dest_id Destination id.
     */
    public static function page_deploy_hash(string $rel, string $dest_id): string {
        $render = Manifest::load(Manifest::RENDER_OPTION);
        $meta   = $render[$rel] ?? null;
        if (!is_array($meta) || !is_file((string) ($meta['src'] ?? ''))) {
            return '';
        }

        $base = (string) ($meta['hash'] ?? '');
        // Only HTML pages are transformed (replacers + the destination's
        // sub-path); anything else keeps its render hash.
        if (substr(strtolower($rel), -5) !== '.html') {
            return $base;
        }

        $dest = Destinations::get($dest_id);
        if ($dest === null) {
            return $base;
        }

        $dest_base = PackageDeployer::base_url($dest);
        $original    = (string) file_get_contents((string) $meta['src']);
        $transformed = $original;
        foreach (Pipeline::replacers() as $replacer) {
            $prepared = $replacer->prepare($dest, $dest_base);
            if (($prepared['ctx'] ?? null) !== null) {
                $transformed = $replacer->apply($transformed, $prepared['ctx'], $rel);
            }
        }
        $transformed = Url::rebase($transformed, Url::normalize_base($dest->served_base_path()));

        // Same hash build_deploy_manifest() records for this page.
        return $transformed === $original ? $base : StableHash::of_html($transformed);
    }

    /**
     * Deploy the whole target as a single package: upload one zip + a one-shot
     * helper, then extract server-side. Falls back to per-file uploads if the
     * host can't run the helper.
     *
     * @param Job $job Active job.
     */
    private static function tick_package(Job $job): void {
        try {
            $transport = self::target_transport($job);
        } catch (\Throwable $e) {
            self::close_connection();
            $job->data['phase']   = 'error';
            $job->data['message'] = 'Upload connection failed: ' . $e->getMessage();
            $job->save();
            return;
        }

        $manifest = Manifest::load(self::deploy_option($job));

        self::beat();

        // Server config first (merge-safe), same as the per-file path.
        self::upload_htaccess($transport);
        update_option('shipstatic_nginx_snippet', HtaccessBuilder::nginx(), false);
        self::beat();

        $files = [];
        foreach ($job->data['queue']['uploads'] as $relative) {
            $meta = $manifest[$relative] ?? null;
            if ($meta !== null && is_file($meta['src'])) {
                $files[$relative] = $meta['src'];
            }
        }

        $deletes  = !empty($job->data['prune']) ? array_values($job->data['removedFiles']) : [];
        $dest     = Destinations::get((string) ($job->data['destId'] ?? ''));
        $base_url = PackageDeployer::base_url($dest);

        // Persist each stage so the dashboard's status poll shows live progress
        // through the single package operation.
        $progress = function (string $stage) use ($job): void {
            $job->data['message'] = $stage;
            self::beat();
            $job->save();
        };

        $result = PackageDeployer::deploy($transport, $base_url, $files, $deletes, PackageDeployer::sslverify($base_url), PackageDeployer::url_is_guess($dest), $progress);

        if (empty($result['ok'])) {
            // Only permanently disable package deploy on a DEFINITIVE failure
            // (host can't run the helper). A transient failure (network, timeout,
            // FTP) just falls back for this run and retries package next time.
            if (empty($result['retryable'])) {
                update_option(PackageDeployer::OFF_PREFIX . (string) ($job->data['destId'] ?? ''), 1, false);
            }
            // Surface WHY package deploy fell back (the deployer's reason), instead
            // of a bare "unavailable here" — so the user can act on it. Recorded on
            // the job too, for a persistent post-run notice.
            $reason = trim((string) ($result['message'] ?? ''), " \t\n\r\0\x0B.");
            $job->data['packageFallback'] = $reason;
            $job->data['phase']        = 'upload';
            $job->data['htaccessDone'] = false;
            $job->data['holdingShown'] = false;
            $job->data['message']      = $reason !== ''
                ? sprintf('%s — uploading file by file instead…', $reason)
                : 'Package deploy didn’t complete — uploading file by file instead…';
            $job->save();
            return;
        }

        // The remote now holds the full deploy manifest.
        Manifest::save(self::pushed_option($job), Manifest::pushed_from($manifest));
        self::mark_synced((string) ($job->data['destId'] ?? ''));
        $job->data['counts']['uploaded'] += (int) $result['extracted'];
        $job->data['counts']['pruned']   += (int) $result['deleted'];
        $job->data['queue']['uploads']    = [];
        $job->data['removedFiles']        = [];

        self::advance_target($job);
        if (in_array($job->data['phase'], ['done', 'error', 'cancelled'], true)) {
            self::close_connection();
        }
        $job->save();
    }

    /**
     * Publish the whole target to a snapshot destination (Cloudflare, GitHub
     * Pages): hand the transport the FULL deploy manifest; it uploads only
     * what the far end doesn't already hold and swaps the new set in
     * atomically. Removed pages are gone simply because they're not in the
     * snapshot, so there is no prune phase — and none of the filesystem steps
     * (.htaccess merge, holding page, home-last, .gz siblings) apply.
     *
     * Files the transport can't include (over Cloudflare's 25 MiB limit) are
     * recorded as failed and left out of the pushed manifest, exactly like a
     * failed per-file upload. A failure is reported as an error — there is no
     * per-file fallback for a destination without a filesystem.
     *
     * Cloudflare publishes in ONE tick. A {@see ResumableSnapshotTransport}
     * (GitHub Pages, whose write limit can make a big first publish take
     * hours) does one step per tick, its progress kept in
     * `snapshotState`; while it waits out a rate limit, ticks only beat so the
     * stale reaper never fires.
     *
     * @param Job $job Active job.
     */
    private static function tick_snapshot(Job $job): void {
        $label = self::snapshot_label($job);

        $wait = (int) ($job->data['snapshotWait'] ?? 0);
        if ($wait > time()) {
            self::beat();
            $job->save();
            if (PHP_SAPI === 'cli') {
                sleep(min(5, $wait - time())); // Don't spin the WP-CLI runner.
            }
            return;
        }

        try {
            $transport = self::target_transport($job);
        } catch (\Throwable $e) {
            self::close_connection();
            $job->data['phase']   = 'error';
            $job->data['message'] = $label . ' connection failed: ' . $e->getMessage();
            $job->save();
            return;
        }

        if (!$transport instanceof SnapshotCapableTransport) {
            self::close_connection();
            $job->data['phase']   = 'error';
            $job->data['message'] = 'This destination’s transport cannot publish snapshots.';
            $job->save();
            return;
        }

        $manifest = Manifest::load(self::deploy_option($job));
        $files    = [];
        foreach ($manifest as $relative => $meta) {
            if (is_file($meta['src'])) {
                $files[$relative] = $meta['src'];
            }
        }

        $progress = function (string $stage) use ($job): void {
            $job->data['message'] = $stage;
            self::beat();
            $job->save();
        };

        if ($transport instanceof ResumableSnapshotTransport) {
            $state  = (array) ($job->data['snapshotState'] ?? []);
            $result = $transport->deploy_snapshot_step($files, $state, $progress);
            if (!empty($result['pending'])) {
                $job->data['snapshotState'] = $state;
                $job->data['snapshotWait']  = (int) ($result['waitUntil'] ?? 0);
                $job->data['message']       = (string) $result['message'];
                self::beat();
                $job->save();
                return;
            }
            unset($job->data['snapshotState'], $job->data['snapshotWait']);
        } else {
            $result = $transport->deploy_snapshot($files, $progress);
        }

        if (empty($result['ok'])) {
            self::close_connection();
            $job->data['phase']   = 'error';
            $job->data['message'] = 'Publishing to ' . $label . ' failed: ' . (string) $result['message'];
            $job->save();
            return;
        }
        $job->data['snapshotMessage'] = (string) $result['message'];

        // The far end now serves exactly the snapshot, minus anything skipped.
        $skipped = array_values((array) ($result['skipped'] ?? []));
        $pushed  = array_diff_key($manifest, array_flip($skipped));
        Manifest::save(self::pushed_option($job), Manifest::pushed_from($pushed));
        self::mark_synced((string) ($job->data['destId'] ?? ''));

        $job->data['counts']['uploaded'] += (int) $result['uploaded'];
        $job->data['counts']['pruned']   += count((array) $job->data['removedFiles']);
        $job->data['failed']              = array_merge((array) $job->data['failed'], $skipped);
        $job->data['queue']['uploads']    = [];
        $job->data['removedFiles']        = [];
        if ($skipped !== []) {
            $job->data['snapshotNotes'] = array_values((array) ($result['errors'] ?? []));
        }

        self::advance_target($job);
        if (in_array($job->data['phase'], ['done', 'error', 'cancelled'], true)) {
            self::close_connection();
        }
        $job->save();
    }

    /**
     * Display name of the current target's snapshot service.
     *
     * @param Job $job Active job.
     */
    private static function snapshot_label(Job $job): string {
        $dest = Destinations::get((string) ($job->data['destId'] ?? ''));

        return $dest !== null && $dest->is_github() ? 'GitHub Pages' : 'Cloudflare';
    }

    /**
     * Upload a batch of changed files (plus a one-time .htaccess) to the
     * destination, over a connection kept open across batches (see
     * target_transport()).
     *
     * @param Job $job Active job.
     */
    private static function tick_upload(Job $job): void {
        $manifest = Manifest::load(self::deploy_option($job));

        try {
            $transport = self::target_transport($job);
        } catch (\Throwable $e) {
            self::close_connection();
            $job->data['phase']   = 'error';
            $job->data['message'] = 'Upload connection failed: ' . $e->getMessage();
            $job->save();
            return;
        }

        try {
            // One-time setup: defer the home page, show a holding page while the
            // rest uploads (first Sync only), then write server config + store
            // the nginx snippet.
            if (empty($job->data['htaccessDone'])) {
                // The home page's key at this destination (in its sub-path
                // folder when it has one).
                $home_key               = self::home_key($job);
                $job->data['homeKey']   = $home_key;

                // Home is uploaded last (after any holding page). Note whether it
                // actually changed so we can skip re-uploading an unchanged one.
                $job->data['homeChanged'] = in_array($home_key, $job->data['queue']['uploads'], true);

                $job->data['queue']['uploads'] = array_values(array_filter(
                    $job->data['queue']['uploads'],
                    static fn(string $rel): bool => $rel !== $home_key
                ));

                // Only a destination with no home page yet gets the holding page
                // (the first Sync). A live site keeps its home page while an
                // update uploads: visitors never see "Currently synchronising".
                if (!empty($job->data['queue']['uploads']) && !self::remote_has($transport, $home_key)) {
                    self::upload_holding_page($transport, $home_key);
                    $job->data['holdingShown'] = true;
                }

                self::upload_htaccess($transport);
                update_option('shipstatic_nginx_snippet', HtaccessBuilder::nginx(), false);
                $job->data['htaccessDone'] = true;
                // +1 for the home page only when we'll actually (re)upload it:
                // because it changed, or to replace a holding page we put up.
                $will_upload_home = !empty($job->data['homeChanged']) || !empty($job->data['holdingShown']);
                $job->data['totals']['uploads'] = count($job->data['queue']['uploads']) + ($will_upload_home ? 1 : 0);
            }

            $batch = array_splice($job->data['queue']['uploads'], 0, self::UPLOAD_BATCH);
            foreach ($batch as $relative) {
                if (self::is_cancelled()) {
                    break;
                }
                self::beat();

                $meta = $manifest[$relative] ?? null;
                if ($meta === null || !is_file($meta['src'])) {
                    $job->skip($relative, 'missing source');
                    continue;
                }

                try {
                    self::put_retry($transport, $meta['src'], $relative);
                    self::upload_gz($transport, $relative, $meta['src']);
                    $job->data['counts']['uploaded']++;
                } catch (\Throwable $e) {
                    $job->error($relative, $e->getMessage());
                    $job->data['failed'][] = $relative;
                }

                // Persist after each file so the dashboard's status poll shows
                // upload progress smoothly, not one jump per batch.
                $job->save();
            }

            // Everything else is up: swap the holding page for the real home,
            // then record the push as complete.
            if (empty($job->data['queue']['uploads']) && !self::is_cancelled()) {
                // (Re)upload the real home only if it changed or we replaced it
                // with a holding page — otherwise it's already correct on the
                // remote and re-uploading it every sync is wasted work.
                if (!empty($job->data['homeChanged']) || !empty($job->data['holdingShown'])) {
                    self::upload_home($transport, $manifest, (string) ($job->data['homeKey'] ?? self::home_key($job)));
                    $job->data['counts']['uploaded']++;
                }

                // Mark only successfully-uploaded files as pushed, so any that
                // failed are re-uploaded on the next sync (delta detects them).
                $pushed = Manifest::pushed_from($manifest);
                foreach ($job->data['failed'] as $failed_rel) {
                    unset($pushed[$failed_rel]);
                }
                Manifest::save(self::pushed_option($job), $pushed);
                self::mark_synced((string) ($job->data['destId'] ?? ''));

                // Prune deleted files next, if requested and there are any;
                // otherwise move on to the next destination (or finish).
                if (!empty($job->data['prune']) && !empty($job->data['removedFiles'])) {
                    $job->data['phase']   = 'prune';
                    $job->data['message'] = 'Removing deleted files…';
                } else {
                    self::advance_target($job);
                }
            }
        } finally {
            // Keep the connection open across upload batches (and into the prune
            // phase, same destination); close only once the run has finished.
            // The process-end shutdown handler is the backstop for a browser
            // tick, whose single request ends after this one batch.
            if (in_array($job->data['phase'], ['done', 'error', 'cancelled'], true)) {
                self::close_connection();
            }
        }

        $job->save();
    }

    /**
     * Delete a batch of files that no longer exist locally from the destination.
     *
     * @param Job $job Active job.
     */
    private static function tick_prune(Job $job): void {
        try {
            $transport = self::target_transport($job);
        } catch (\Throwable $e) {
            // Uploads already succeeded; leave leftovers for the next run.
            self::close_connection();
            $job->data['phase']   = 'done';
            $job->data['message'] = 'Pushed. Could not connect to remove old files: ' . $e->getMessage();
            $job->save();
            return;
        }

        $batch = array_splice($job->data['removedFiles'], 0, self::UPLOAD_BATCH);
        foreach ($batch as $relative) {
            if (self::is_cancelled()) {
                break;
            }
            self::beat();

            $transport->delete($relative);
            $transport->delete($relative . '.gz');
            $job->data['counts']['pruned']++;
        }

        if (empty($job->data['removedFiles'])) {
            self::advance_target($job);
        }

        // Reuses the same connection the upload phase opened; close it once the
        // run is finished (shutdown handler is the backstop for a browser tick).
        if (in_array($job->data['phase'], ['done', 'error', 'cancelled'], true)) {
            self::close_connection();
        }

        $job->save();
    }

    /**
     * Name of the destination currently being deployed (for the snapshot).
     *
     * @param array<string,mixed> $data Job data.
     */
    private static function target_name(array $data): string {
        if (empty($data['targets'])) {
            return '';
        }
        $dest = Destinations::get((string) ($data['destId'] ?? ''));

        return $dest !== null ? (string) $dest->get('name') : (string) ($data['destId'] ?? '');
    }

    /**
     * The pushed-manifest option for the job's target destination.
     *
     * @param Job $job Active job.
     */
    private static function pushed_option(Job $job): string {
        $id = (string) ($job->data['destId'] ?? '');
        if ($id === '') {
            $id = Destinations::primary()->id();
        }

        return Destinations::pushed_option($id);
    }

    /**
     * The deploy-manifest option for the job's target destination. Per-destination
     * (not a single global) so concurrent deploy workers each read/write their own
     * target's manifest without clobbering one another.
     *
     * @param Job $job Active job.
     */
    private static function deploy_option(Job $job): string {
        $id = (string) ($job->data['destId'] ?? '');
        if ($id === '') {
            $id = Destinations::primary()->id();
        }

        return Manifest::DEPLOY_OPTION . '_' . $id;
    }

    /**
     * Option-name prefix for a destination's last-synced signature.
     */
    public const SYNC_SIG = 'shipstatic_sync_sig_';

    /**
     * A fingerprint of what a destination's deploy depends on: the current
     * render plus each registered replacer's settings for that destination.
     * Stored when a sync completes and compared for the "in sync" indicator — so
     * a destination with replacements (whose deployed bytes differ from the base
     * render) isn't wrongly flagged out of date, while a real source or
     * replacement change is.
     *
     * @param \WPEasy\ShipStatic\Settings\Destination|null $dest Destination.
     */
    public static function sync_signature($dest): string {
        $render = Manifest::load(Manifest::RENDER_OPTION);
        if (empty($render)) {
            return ''; // Nothing rendered — can't be "in sync".
        }

        $parts = [];
        foreach ($render as $rel => $meta) {
            $parts[] = $rel . ':' . ($meta['hash'] ?? '');
        }
        sort($parts);

        // Fold each registered replacer's signature contribution, keyed by its
        // stable key so the result is independent of registration order, plus
        // the destination base URL — baked into the deployed sitemap/robots
        // origin, so a change there means the destination needs re-deploying.
        $sig = [];
        foreach (Pipeline::replacers() as $replacer) {
            $sig[$replacer->key()] = $dest !== null ? $replacer->signature($dest) : null;
        }
        ksort($sig);
        $sig['__base'] = $dest !== null ? PackageDeployer::base_url($dest) : '';
        // Turning site search on/off (or a new index format) changes what deploys.
        $sig['__search'] = IndexBuilder::enabled() ? IndexBuilder::VERSION : 0;

        $replacements = (string) wp_json_encode($sig);

        return md5(md5(implode('|', $parts)) . '|' . md5($replacements));
    }

    /**
     * Rebuild a destination's push record from what its Connector reports is
     * actually on disk, so a wiped record (re-target, "Reset sync state", a
     * fresh pairing) doesn't force a full re-upload of files that are already
     * there and identical.
     *
     * Only files present in the CURRENT deploy manifest are recorded: a remote
     * file whose md5 equals the local deploy copy takes the manifest's stable
     * hash (so it reads as unchanged); one that differs is recorded with a
     * non-matching hash (so it re-uploads). Files on the remote that the
     * deploy manifest doesn't know are deliberately NOT recorded — the push
     * record only ever lists what we pushed, so prune never touches a user's
     * own files.
     *
     * @param string                                $dest_id Destination id.
     * @param array<string,array{size:int,md5:string}> $remote  Receiver inventory.
     * @return array{matched:int,changed:int,unmanaged:int,inSync:bool,total:int}
     */
    public static function rebuild_pushed_record(string $dest_id, array $remote): array {
        $render = Manifest::load(Manifest::RENDER_OPTION);
        $deploy = self::build_deploy_manifest($render, $dest_id);

        $pushed  = [];
        $matched = 0;
        $changed = 0;
        foreach ($deploy as $rel => $meta) {
            if (!isset($remote[$rel])) {
                continue; // Not on the remote → stays absent → uploads next sync.
            }
            $src = (string) ($meta['src'] ?? '');
            if ($src !== '' && is_file($src) && md5_file($src) === $remote[$rel]['md5']) {
                $pushed[$rel] = ['size' => (int) $meta['size'], 'hash' => (string) $meta['hash']];
                $matched++;
            } else {
                $pushed[$rel] = ['size' => (int) $remote[$rel]['size'], 'hash' => 'remote:' . $remote[$rel]['md5']];
                $changed++;
            }
        }
        ksort($pushed);
        Manifest::save(Destinations::pushed_option($dest_id), $pushed);

        $diff    = Manifest::diff($pushed, $deploy);
        $in_sync = $diff['changed'] === [] && $diff['removed'] === [] && $deploy !== [];
        if ($in_sync) {
            self::mark_synced($dest_id);
        } else {
            delete_option(self::SYNC_SIG . $dest_id);
        }

        return [
            'matched'   => $matched,
            'changed'   => $changed,
            'unmanaged' => count(array_diff_key($remote, $deploy)),
            'inSync'    => $in_sync,
            'total'     => count($deploy),
        ];
    }

    /**
     * Record that a destination is now in sync (its signature at deploy time).
     *
     * @param string $dest_id Destination id.
     */
    private static function mark_synced(string $dest_id): void {
        update_option(self::SYNC_SIG . $dest_id, self::sync_signature(Destinations::get($dest_id)), false);
    }

    /**
     * Whether a destination's last-synced signature still matches the current
     * render + replacements (used for the dashboard's "in sync" dot).
     *
     * @param string                                          $dest_id Destination id.
     * @param \WPEasy\ShipStatic\Settings\Destination|null  $dest    Destination.
     */
    public static function in_sync(string $dest_id, $dest): bool {
        // Content edited since the last render, or a render Sync wouldn't reuse
        // (older format, another address) → the export is stale everywhere.
        if (!self::render_is_current()) {
            return false;
        }

        $stored = (string) get_option(self::SYNC_SIG . $dest_id, '');

        return $stored !== '' && $stored === self::sync_signature($dest);
    }

    /**
     * Final status message reflecting failures and pruning.
     *
     * @param Job $job Active job.
     */
    private static function done_message(Job $job): string {
        if (!empty($job->data['snapshotNotes'])) {
            // The snapshot left files out (e.g. over the per-file limit) — a retry
            // won't help, so say which and why instead.
            $notes = (array) $job->data['snapshotNotes'];
            return sprintf('Published, but %d file(s) were left out: %s', count($notes), implode('; ', array_slice($notes, 0, 3)) . (count($notes) > 3 ? '; …' : '.'));
        }
        if (!empty($job->data['failed'])) {
            return 'Pushed with ' . count($job->data['failed']) . ' failed file(s); re-run Sync to retry them.';
        }

        $done = (int) $job->data['targetsDone'];
        if ($done > 1) {
            return sprintf('Pushed to %d destinations — all in sync.', $done);
        }

        // A snapshot transport's own summary (e.g. the GitHub commit made).
        if (!empty($job->data['snapshotMessage'])) {
            return (string) $job->data['snapshotMessage'];
        }

        $pruned = (int) $job->data['counts']['pruned'];

        return $pruned > 0
            ? 'Pushed — destination is in sync (' . $pruned . ' old file(s) removed).'
            : 'Pushed — destination is in sync.';
    }

    /**
     * Upload a file with a few retries, to ride out transient transport hiccups
     * (FTPS data-channel resets are common).
     *
     * @param TransportInterface $transport Connected transport.
     * @param string             $local     Local file path.
     * @param string             $remote    Remote relative path.
     * @throws \Throwable The last error if all attempts fail.
     */
    private static function put_retry(TransportInterface $transport, string $local, string $remote): void {
        $last = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $transport->put($local, $remote);
                return;
            } catch (\Throwable $e) {
                $last = $e;
            }
        }

        throw $last ?? new \RuntimeException('Upload failed: ' . $remote);
    }

    /**
     * The home page's key at the job's destination: index.html, inside the
     * sub-path folder when the upload root isn't the served path.
     *
     * @param Job $job Active job.
     */
    private static function home_key(Job $job): string {
        $dest = Destinations::get((string) ($job->data['destId'] ?? ''));

        return $dest !== null ? self::deploy_key(self::HOME_FILE, $dest) : self::HOME_FILE;
    }

    /**
     * Whether a file is already on the destination. When the answer is
     * unknown, assume it is: replacing a live home page is the worse mistake.
     *
     * @param TransportInterface $transport Connected transport.
     * @param string             $key       Remote key.
     */
    private static function remote_has(TransportInterface $transport, string $key): bool {
        try {
            return $transport->exists($key);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Upload the "Currently Synchronising" holding page to the destination home.
     *
     * @param TransportInterface $transport Connected transport.
     * @param string             $home_key  The home page's key at this destination.
     */
    private static function upload_holding_page(TransportInterface $transport, string $home_key): void {
        $tmp = self::worker_tmp('holding.out');
        file_put_contents($tmp, self::holding_html());
        self::put_retry($transport, $tmp, $home_key);
        wp_delete_file($tmp);
    }

    /**
     * A process-unique staging path in the cache dir. Parallel deploy runs one
     * worker process per destination, so the transient upload temp files (holding
     * page, .htaccess merge, on-the-fly .gz) MUST NOT share a fixed name or one
     * worker's unlink would pull the file out from under another mid-upload.
     *
     * @param string $suffix Short, filename-safe suffix.
     */
    private static function worker_tmp(string $suffix): string {
        return Paths::cache_dir() . '/.bs-tmp-' . getmypid() . '-' . $suffix;
    }

    /**
     * Upload the real home page (and its gzip sibling) from the render manifest.
     *
     * @param TransportInterface                                          $transport Connected transport.
     * @param array<string,array{size:int,hash:string,src:string}>        $manifest  Deploy manifest.
     * @param string                                                      $home_key  The home page's key at this destination.
     */
    private static function upload_home(TransportInterface $transport, array $manifest, string $home_key): void {
        $meta = $manifest[$home_key] ?? null;
        if ($meta === null || !is_file($meta['src'])) {
            return;
        }

        self::put_retry($transport, $meta['src'], $home_key);
        self::upload_gz($transport, $home_key, $meta['src']);
    }

    /**
     * Best-effort restore of the real home page (used when a push is cancelled
     * after the holding page has gone up).
     */
    private static function restore_home(Job $job): void {
        try {
            $dest      = Destinations::get((string) ($job->data['destId'] ?? '')) ?? Destinations::primary();
            $transport = TransportFactory::make($dest->connection_config());
            $transport->connect();
            try {
                // The destination's own copy (the raw render still carries the
                // sub-path placeholder).
                self::upload_home($transport, self::build_deploy_manifest(Manifest::load(Manifest::RENDER_OPTION), $dest->id()), (string) ($job->data['homeKey'] ?? self::home_key($job)));
            } finally {
                $transport->disconnect();
            }
        } catch (\Throwable $e) {
            // Best effort — the next successful sync will restore it.
        }
    }

    /**
     * Minimal, self-contained holding page shown at the destination home during
     * a push. Auto-refreshes so visitors land on the real site once it's live.
     */
    /**
     * The holding page, for Deploy\WordPressMove (a root left without a page
     * between moving WordPress and the first Sync).
     */
    public static function holding_page(): string {
        return self::holding_html();
    }

    private static function holding_html(): string {
        return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="10">
<meta name="robots" content="noindex">
<title>Updating…</title>
<style>
  :root { color-scheme: light dark; }
  body { margin:0; min-height:100vh; display:grid; place-items:center; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:#0f172a; color:#f1f5f9; }
  .box { text-align:center; padding:2rem; }
  .spin { width:42px; height:42px; margin:0 auto 1.25rem; border:4px solid rgba(255,255,255,.2); border-top-color:#3b82f6; border-radius:50%; animation:s 1s linear infinite; }
  h1 { font-size:1.4rem; font-weight:600; margin:0 0 .5rem; }
  p { margin:0; color:#94a3b8; }
  @keyframes s { to { transform:rotate(360deg); } }
</style>
</head>
<body>
  <div class="box">
    <div class="spin"></div>
    <h1>Currently synchronising</h1>
    <p>This site is being updated and will be back shortly.</p>
  </div>
</body>
</html>
HTML;
    }

    /**
     * Write our .htaccess to the destination root, backing up any existing one.
     *
     * @param TransportInterface $transport Connected transport.
     */
    private static function upload_htaccess(TransportInterface $transport): void {
        $existing = $transport->exists('.htaccess') ? $transport->get('.htaccess') : '';

        // One-time backup of the ORIGINAL before we ever merge into it. Skip when
        // the remote file is already our own output: on a destination that had no
        // .htaccess to begin with, sync 1 writes ours and sync 2 would otherwise
        // "back up" that generated file — and on a destination that DID have one,
        // that write would clobber the genuine original saved by sync 1.
        $is_ours = HtaccessBuilder::has_block($existing);

        if ($existing !== '' && !$is_ours && !$transport->exists(self::HTACCESS_BAK)) {
            $bak = self::worker_tmp('htaccess.bak.out');
            file_put_contents($bak, $existing);
            self::put_retry($transport, $bak, self::HTACCESS_BAK);
            wp_delete_file($bak);
        }

        $tmp = self::worker_tmp('htaccess.out');
        file_put_contents($tmp, HtaccessBuilder::merge($existing, $transport instanceof FolderTransport ? $transport->wordpress_dirs() : []));
        self::put_retry($transport, $tmp, '.htaccess');
        wp_delete_file($tmp);
    }

    /**
     * Upload a gzip sibling for a compressible file (cached .gz if present, else
     * generated on the fly from the source).
     *
     * @param TransportInterface $transport Connected transport.
     * @param string             $relative  Destination relative path.
     * @param string             $source    Local source file.
     */
    private static function upload_gz(TransportInterface $transport, string $relative, string $source): void {
        if (!Compressor::is_compressible($relative)) {
            return;
        }

        $sibling = $source . '.gz';
        if (is_file($sibling)) {
            self::put_retry($transport, $sibling, $relative . '.gz');
            return;
        }

        $tmp = self::worker_tmp('upload.gz');
        if (Compressor::gzip_to($source, $tmp)) {
            self::put_retry($transport, $tmp, $relative . '.gz');
            wp_delete_file($tmp);
        }
    }

    /**
     * Write generated content (a page or rewritten CSS) into the cache, gzip it,
     * and record it in the upload plan.
     *
     * @param Job    $job      Active job.
     * @param string $relative Relative file path.
     * @param string $content  File contents.
     */
    private static function cache_file(Job $job, string $relative, string $content): void {
        $path = Paths::output_dir() . '/' . $relative;
        $dir  = dirname($path);

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return;
        }

        $bytes = file_put_contents($path, $content);
        if ($bytes === false) {
            return;
        }

        if (Compressor::is_compressible($relative)) {
            Compressor::write_sibling($path);
        }

        $job->data['plan'][$relative]  = wp_normalize_path($path);
        $job->data['counts']['bytes'] += (int) $bytes;
    }

    /**
     * Record a static asset to be uploaded straight from its source file —
     * without copying it into the cache.
     *
     * @param Job    $job      Active job.
     * @param string $relative Relative destination path.
     * @param string $source   Absolute source file path.
     */
    private static function plan_source(Job $job, string $relative, string $source): void {
        $job->data['plan'][$relative]  = wp_normalize_path($source);
        $job->data['counts']['bytes'] += (int) @filesize($source);
    }

    /**
     * Read CSS from its source file, or fetch it over HTTP if there is none.
     *
     * @param Job         $job    Active job.
     * @param string      $url    CSS URL.
     * @param string|null $source Source file path, if resolved.
     * @return string|null CSS contents, or null to skip.
     */
    private static function read_or_fetch_css(Job $job, string $url, ?string $source): ?string {
        if ($source !== null) {
            $css = file_get_contents($source);
            return $css === false ? null : $css;
        }

        $result = PageRenderer::fetch_asset($url);
        if ($result['code'] !== 200) {
            $job->skip($url, self::http_skip_reason((int) $result['code'], (string) ($result['location'] ?? '')));
            return null;
        }

        return $result['body'];
    }

    /**
     * Whether a relative path is a CSS file (by extension).
     *
     * @param string $relative Relative path.
     */
    private static function is_css_url(string $relative): bool {
        return strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'css';
    }

    /**
     * Recursively empty the output directory before a fresh run.
     */
    private static function reset_output(): void {
        $dir = Paths::output_dir();
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes an emptied directory inside the plugin's own cache dir.
                @rmdir($item->getPathname());
            } else {
                wp_delete_file($item->getPathname());
            }
        }
    }

    /**
     * Build a UI-facing snapshot of the job.
     *
     * @param Job $job Active job.
     * @return array<string,mixed>
     */
    private static function snapshot(Job $job): array {
        $d = $job->data;

        return [
            'phase'        => $d['phase'],
            'type'         => $d['type'],
            'message'      => $d['message'],
            'counts'       => $d['counts'],
            'totals'       => $d['totals'],
            'queued'       => [
                'pages'   => count($d['queue']['pages']),
                'assets'  => count($d['queue']['assets']),
                'uploads' => count($d['queue']['uploads'] ?? []),
                'prune'   => count($d['removedFiles'] ?? []),
            ],
            'removed'      => $d['removed'] ?? 0,
            'prune'        => !empty($d['prune']),
            // Sync reused the current render (no rendering): when it was made.
            'reusedRender' => (int) ($d['reusedRender'] ?? 0),
            'reusedRenderAt' => !empty($d['reusedRender']) ? wp_date((string) get_option('time_format'), (int) $d['reusedRender']) : '',
            'pageLimitHit' => !empty($d['pageLimitHit']),
            // A destination configured for package (zip) deploy fell back to
            // file-by-file because the sync runtime has no ZipArchive.
            'zipUnavailable' => !empty($d['zipUnavailable']),
            // The reason a package deploy was ATTEMPTED but fell back to
            // file-by-file (deployer message), '' if not applicable.
            'packageFallback' => (string) ($d['packageFallback'] ?? ''),
            'targets'      => [
                'index' => (int) ($d['targetIndex'] ?? 0),
                'total' => count($d['targets'] ?? []),
                'done'  => (int) ($d['targetsDone'] ?? 0),
                'name'  => self::target_name($d),
            ],
            // Parallel deploy: per-destination progress rows (empty for a
            // sequential run), plus the active pool size.
            'parallel'     => !empty($d['parallel']),
            'poolSize'     => (int) ($d['poolSize'] ?? 0),
            // The detached coordinator can't spawn the pool itself — the dashboard
            // launches the workers via POST /sync/deploy-dispatch when this is set.
            'needsDispatch'   => !empty($d['needsDispatch']),
            'parallelTargets' => array_values((array) ($d['parallelTargets'] ?? [])),
            'errorCount'   => count($d['errors']),
            'skippedCount' => count($d['skipped']),
            'failedCount'  => count($d['failed'] ?? []),
            'compatCount'  => count($d['compat'] ?? []),
            'errors'       => array_slice($d['errors'], -25),
            'skipped'      => array_slice($d['skipped'], -25),
            'compat'       => array_slice($d['compat'] ?? [], 0, 25),
            'startedAt'    => $d['startedAt'],
            'updatedAt'    => $d['updatedAt'],
            'running'      => !in_array($d['phase'], ['done', 'error', 'cancelled', 'idle'], true),
            'cliAlive'     => self::is_driver_alive(),
            'preview'      => $d['preview'] ?? null,
        ];
    }
}
