<?php
/**
 * Spawns the WP-CLI runner as a detached background process.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\CLI;

use WPEasy\ShipStatic\Support\Paths;
use WPEasy\ShipStatic\System\WpCli;

defined('ABSPATH') || exit;

/**
 * Lets the dashboard run a sync via WP-CLI (no web-worker contention) whenever
 * WP-CLI is reachable — including Local by Flywheel, whose bundled php.exe +
 * wp-cli.phar are resolved by WpCli. A separate process renders the pages while
 * the browser polls status. Falls back to browser-driven curl when WP-CLI is
 * genuinely unavailable.
 */
final class Background {

    /**
     * Whether we can spawn a WP-CLI process on this host.
     */
    public static function can_spawn(): bool {
        $available = WpCli::status()['available'] === true;

        /**
         * Filters whether the dashboard may auto-run sync via WP-CLI.
         *
         * @param bool $can Whether spawning is allowed.
         */
        return (bool) apply_filters('shipstatic_can_spawn_cli', $available);
    }

    /**
     * Spawn `wp shipstatic run` (detached) to drive the existing job.
     *
     * @return bool True if the spawn was attempted.
     */
    public static function spawn_run(): bool {
        if (!self::can_spawn()) {
            return false;
        }

        $command = self::build('run');
        if ($command === null) {
            return false;
        }

        // The coordinator is a single launch — its own fixed .bat (overwritten
        // each run, never accumulating).
        return self::spawn_detached($command, '.bs-run.bat');
    }

    /**
     * Spawn N detached `wp shipstatic deploy_worker` processes — the parallel
     * deploy pool. Each worker claims and uploads one destination at a time (its
     * own transport connection), so independent remote pushes overlap.
     *
     * NOTE: the subcommand is `deploy_worker` with an UNDERSCORE — WP-CLI derives
     * subcommand names from the method name verbatim and does NOT convert `_`→`-`.
     * Using `deploy-worker` here silently spawns an unknown command that exits at
     * once (looks "spawned" but does zero work).
     *
     * @param int $count How many workers to launch.
     * @return int The number of workers actually spawned.
     */
    public static function spawn_workers(int $count): int {
        if (!self::can_spawn() || $count < 1) {
            return 0;
        }

        $command = self::build('deploy_worker');
        if ($command === null) {
            return 0;
        }

        // On Windows, write the worker launcher ONCE and start it N times. Every
        // worker runs the identical command, so a single shared, never-rewritten
        // .bat is safe to read concurrently — and avoids both the write/read race
        // and per-spawn filename churn. (An earlier unique-name + self-deleting
        // .bat caused "The batch file cannot be found" when the delete raced the
        // launch, and uniqid() collided within one microsecond in this loop.)
        if (DIRECTORY_SEPARATOR === '\\') {
            $bat = Paths::cache_dir() . '/.bs-worker.bat';
            if (!Paths::ensure() || file_put_contents($bat, "@echo off\r\n" . $command . " > NUL 2>&1\r\n") === false) {
                return 0;
            }
            $bat_win = str_replace('/', '\\', $bat);

            $spawned = 0;
            for ($i = 0; $i < $count; $i++) {
                if (self::launch_bat($bat_win)) {
                    $spawned++;
                }
            }

            return $spawned;
        }

        // POSIX: exec nohup per worker — no shared file, so no race.
        if (!function_exists('exec')) {
            return 0;
        }
        $spawned = 0;
        for ($i = 0; $i < $count; $i++) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- spawns a background WP-CLI deploy worker; the command is built internally, not from request input.
            @exec('nohup ' . $command . ' > /dev/null 2>&1 &');
            $spawned++;
        }

        return $spawned;
    }

    /**
     * Build the full shell command for `wp shipstatic <subcommand>`.
     *
     * @param string $subcommand The shipstatic subcommand.
     */
    private static function build(string $subcommand): ?string {
        $args = 'shipstatic ' . $subcommand . ' --path=' . escapeshellarg(rtrim((string) ABSPATH, '/\\'));
        // Without --url, WP-CLI runs on a network's main site, not this one.
        if (\WPEasy\ShipStatic\Support\Environment::is_multisite()) {
            $args .= ' --url=' . escapeshellarg(home_url('/'));
        }

        return WpCli::build_command($args);
    }

    /**
     * Launch a command detached so the web request returns immediately, wrapping
     * it in a fixed .bat on Windows (START needs a single clean argument —
     * nested quotes in the raw command break it).
     *
     * @param string $command   Fully-formed shell command.
     * @param string $bat_name  Basename of the .bat to (re)write in the cache dir.
     */
    private static function spawn_detached(string $command, string $bat_name): bool {
        if (DIRECTORY_SEPARATOR === '\\') {
            $bat = Paths::cache_dir() . '/' . $bat_name;
            if (!Paths::ensure() || file_put_contents($bat, "@echo off\r\n" . $command . " > NUL 2>&1\r\n") === false) {
                return false;
            }

            return self::launch_bat(str_replace('/', '\\', $bat));
        }

        if (function_exists('exec')) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- spawns the background WP-CLI sync runner; the command is built internally, not from request input.
            @exec('nohup ' . $command . ' > /dev/null 2>&1 &');
            return true;
        }

        return false;
    }

    /**
     * START an already-written .bat detached (Windows).
     *
     * @param string $bat_win Windows-style absolute path to the .bat.
     */
    private static function launch_bat(string $bat_win): bool {
        // Redirect START's stdio to NUL so the detached child doesn't inherit (and
        // then write to) this process's stdout pipe after we pclose it — that write
        // is what prints "The process tried to write to a nonexistent pipe" into a
        // console-run `wp shipstatic sync`.
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_popen -- spawns the background WP-CLI runner; the command is built from internal paths, not request input.
        $handle = @popen('start "shipstatic" /B "' . $bat_win . '" <NUL >NUL 2>&1', 'r');
        if (is_resource($handle)) {
            pclose($handle);
            return true;
        }

        return false;
    }
}
