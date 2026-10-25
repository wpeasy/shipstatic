<?php
/**
 * Scheduled syncs (WP-Cron).
 *
 * @package WPEasy\ShipStatic
 * @since   2.3.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Sync;

use WPEasy\ShipStatic\CLI\Background;
use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Support\UrlSafety;

defined('ABSPATH') || exit;

/**
 * Runs a full Sync (render + deploy) on a schedule — hourly, daily, weekly or
 * monthly — to all enabled destinations or a chosen few.
 *
 * A scheduled run is an ordinary Runner sync. It is handed to a WP-CLI process
 * when the host can spawn one (same as the dashboard); otherwise WP-Cron drives
 * it in ~25 s slices, each slice queuing the next and kicking a cron loopback
 * so a quiet site doesn't wait for a visitor. It never starts over a run that
 * is already going (a sync the user started, or the previous scheduled one).
 */
final class Scheduler {

    /** Settings: {enabled, period, all, destinations}. */
    public const OPTION = 'shipstatic_schedule';

    /** Last scheduled run: {at, status, message, targets, jobStartedAt}. */
    public const LAST_OPTION = 'shipstatic_schedule_last';

    /** Recurring event that starts a run. */
    public const HOOK = 'shipstatic_scheduled_sync';

    /** One-off event that drives the next slice of a cron-driven run. */
    public const CONTINUE_HOOK = 'shipstatic_scheduled_continue';

    /** WP has no monthly recurrence; ours is 30 days. */
    private const MONTHLY = 'shipstatic_monthly';

    public const PERIODS = ['hourly', 'daily', 'weekly', 'monthly'];

    /** Seconds of work per cron slice — well inside a typical 30 s PHP limit. */
    private const SLICE_SECONDS = 25;

    /**
     * Register hooks.
     */
    public static function init(): void {
        add_filter('cron_schedules', [self::class, 'add_monthly']); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 30 days, far above the 15-minute guidance.
        add_action(self::HOOK, [self::class, 'run']);
        add_action(self::CONTINUE_HOOK, [self::class, 'drive']);
    }

    /**
     * Add the 30-day recurrence.
     *
     * @param array<string,array<string,mixed>> $schedules Cron schedules.
     * @return array<string,array<string,mixed>>
     */
    public static function add_monthly(array $schedules): array {
        $schedules[self::MONTHLY] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => __('Monthly (every 30 days)', 'shipstatic'),
        ];

        return $schedules;
    }

    /**
     * Saved settings with defaults.
     *
     * @return array{enabled:bool,period:string,all:bool,destinations:array<int,string>}
     */
    public static function settings(): array {
        $s = get_option(self::OPTION, []);
        $s = is_array($s) ? $s : [];

        return [
            'enabled'      => !empty($s['enabled']),
            'period'       => in_array($s['period'] ?? '', self::PERIODS, true) ? (string) $s['period'] : 'daily',
            'all'          => !array_key_exists('all', $s) || !empty($s['all']),
            'destinations' => array_values(array_filter(array_map('strval', (array) ($s['destinations'] ?? [])), static fn(string $id): bool => (bool) preg_match('/^[A-Za-z0-9]+$/', $id))),
        ];
    }

    /**
     * Save settings (partial updates allowed) and reschedule.
     *
     * @param array<string,mixed> $input Raw input.
     * @return array<string,mixed> The saved settings.
     */
    public static function save(array $input): array {
        $s = self::settings();
        if (array_key_exists('enabled', $input)) {
            $s['enabled'] = !empty($input['enabled']);
        }
        if (isset($input['period']) && in_array($input['period'], self::PERIODS, true)) {
            $s['period'] = (string) $input['period'];
        }
        if (array_key_exists('all', $input)) {
            $s['all'] = !empty($input['all']);
        }
        if (isset($input['destinations']) && is_array($input['destinations'])) {
            $known             = array_map(static fn($d): string => (string) $d->id(), Destinations::objects());
            $s['destinations'] = array_values(array_intersect($known, array_map('strval', $input['destinations'])));
        }

        $previous = self::settings();
        update_option(self::OPTION, $s, false);

        // Only a change of on/off or period moves the next run; picking
        // destinations doesn't.
        if ($previous['enabled'] !== $s['enabled'] || $previous['period'] !== $s['period'] || !wp_next_scheduled(self::HOOK)) {
            self::reschedule();
        }

        return $s;
    }

    /**
     * (Re)create the recurring event from the settings — first run one period
     * from now. Clears it when scheduling is off.
     */
    public static function reschedule(): void {
        wp_clear_scheduled_hook(self::HOOK);

        $s = self::settings();
        if (!$s['enabled']) {
            return;
        }

        $recurrence = $s['period'] === 'monthly' ? self::MONTHLY : $s['period'];
        $schedules  = wp_get_schedules();
        $interval   = (int) ($schedules[$recurrence]['interval'] ?? DAY_IN_SECONDS);
        wp_schedule_event(time() + $interval, $recurrence, self::HOOK);
    }

    /**
     * Remove every scheduled event (deactivation / uninstall).
     */
    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::CONTINUE_HOOK);
    }

    /**
     * Next scheduled run (Unix time), or null.
     */
    public static function next_run(): ?int {
        $next = wp_next_scheduled(self::HOOK);

        return $next ? (int) $next : null;
    }

    /**
     * Destination ids a run goes to: every enabled destination ("All"), or the
     * chosen ones that are still enabled.
     *
     * @return array<int,string>
     */
    public static function targets(): array {
        $s       = self::settings();
        $enabled = Destinations::enabled_ids();

        return $s['all'] ? $enabled : array_values(array_intersect($enabled, $s['destinations']));
    }

    /**
     * Cron: start a scheduled sync.
     */
    public static function run(): void {
        if (!self::settings()['enabled']) {
            return;
        }

        $targets = self::targets();
        if ($targets === []) {
            self::record('skipped', __('No enabled destinations are selected.', 'shipstatic'), []);
            return;
        }
        if (self::busy()) {
            self::record('skipped', __('Another sync was already running.', 'shipstatic'), $targets);
            return;
        }

        try {
            // Unattended: always a fresh full render, the safety net for changes
            // the change tracker can't see.
            $snapshot = Runner::start('sync', ['targets' => $targets, 'rerender' => true]);
        } catch (\Throwable $e) {
            self::record('error', $e->getMessage(), $targets);
            return;
        }

        // Tag the job so a gap between cron slices isn't mistaken for a dead run.
        $job = Job::load();
        if ($job !== null) {
            $job->data['scheduled'] = true;
            $job->save();
        }
        self::record('running', (string) ($snapshot['message'] ?? ''), $targets, (int) ($job->data['startedAt'] ?? 0));

        if (!Background::spawn_run()) {
            self::drive();
        }
    }

    /**
     * Cron: advance a cron-driven run for one slice, then queue the next.
     */
    public static function drive(): void {
        $job = Job::load();
        if ($job === null || empty($job->data['scheduled'])) {
            return; // Not ours (or already gone).
        }

        ignore_user_abort(true);
        /**
         * Filters the seconds of sync work per WP-Cron slice.
         *
         * @param int $seconds Default 25 — keep it under the host's PHP time limit.
         */
        $until = time() + max(1, (int) apply_filters('shipstatic_schedule_slice_seconds', self::SLICE_SECONDS));
        do {
            $snapshot = Runner::tick();
            $phase    = (string) ($snapshot['phase'] ?? 'idle');
            if (in_array($phase, ['done', 'error', 'cancelled', 'idle'], true)) {
                self::finish($phase, (string) ($snapshot['message'] ?? ''));
                return;
            }
        } while (time() < $until);

        wp_schedule_single_event(time(), self::CONTINUE_HOOK);
        // WP-Cron only runs on a page load; kick it ourselves once this request
        // ends so the next slice doesn't wait for a visitor.
        add_action('shutdown', [self::class, 'kick_cron'], 99);
    }

    /**
     * Fire a non-blocking wp-cron.php request, as core's spawn_cron() does (it
     * refuses to from inside a cron request, which is where we are).
     */
    public static function kick_cron(): void {
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            return; // A real system cron runs wp-cron.php for us.
        }
        $key = sprintf('%.22F', microtime(true));
        set_transient('doing_cron', $key);
        $url = add_query_arg('doing_wp_cron', $key, site_url('wp-cron.php'));
        wp_remote_post($url, [
            'timeout'   => 0.01,
            'blocking'  => false,
            // TLS stays on except for a local dev host (SECURITY_PATTERNS.md §2).
            'sslverify' => !UrlSafety::is_dev_host((string) wp_parse_url($url, PHP_URL_HOST)),
        ]);
    }

    /**
     * Whether a sync/check is in progress (so a scheduled run must wait).
     */
    private static function busy(): bool {
        $phase = (string) (Runner::status()['phase'] ?? 'idle');

        return !in_array($phase, ['idle', 'done', 'error', 'cancelled'], true);
    }

    /**
     * Record the outcome of a cron-driven run.
     *
     * @param string $phase   Final phase.
     * @param string $message Runner's message.
     */
    private static function finish(string $phase, string $message): void {
        $last   = self::last_raw();
        $status = $phase === 'done' ? 'done' : ($phase === 'cancelled' ? 'cancelled' : 'error');
        self::record($status, $message, (array) ($last['targets'] ?? []), (int) ($last['jobStartedAt'] ?? 0));
    }

    /**
     * Store the last scheduled run.
     *
     * @param string            $status   running | done | error | skipped | cancelled.
     * @param string            $message  Human-readable message.
     * @param array<int,string> $targets  Destination ids.
     * @param int               $started  The job's startedAt (to follow it live).
     */
    private static function record(string $status, string $message, array $targets, int $started = 0): void {
        update_option(self::LAST_OPTION, [
            'at'           => time(),
            'status'       => $status,
            'message'      => $message,
            'targets'      => array_values($targets),
            'jobStartedAt' => $started,
        ], false);
    }

    /**
     * @return array<string,mixed>
     */
    private static function last_raw(): array {
        $last = get_option(self::LAST_OPTION, []);

        return is_array($last) ? $last : [];
    }

    /**
     * The last scheduled run for display. A run handed to WP-CLI finishes
     * without telling us, so while the job it started is still the current one
     * its live phase/message is reported instead of the stored "running".
     *
     * @return array{at:int,status:string,message:string,targets:array<int,string>}|null
     */
    public static function last(): ?array {
        $last = self::last_raw();
        if (empty($last['at'])) {
            return null;
        }

        $status  = (string) ($last['status'] ?? '');
        $message = (string) ($last['message'] ?? '');
        if ($status === 'running') {
            $job = Job::load();
            if ($job !== null && (int) ($job->data['startedAt'] ?? -1) === (int) ($last['jobStartedAt'] ?? 0)) {
                $phase   = (string) ($job->data['phase'] ?? '');
                $message = (string) ($job->data['message'] ?? $message);
                if (in_array($phase, ['done', 'error', 'cancelled'], true)) {
                    $status = $phase;
                }
            } else {
                $status = 'unknown'; // Replaced by a later run before we saw it end.
            }
        }

        return [
            'at'      => (int) $last['at'],
            'status'  => $status,
            'message' => $message,
            'targets' => array_values(array_map('strval', (array) ($last['targets'] ?? []))),
        ];
    }
}
