<?php
/**
 * Persisted, resumable sync job state.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Sync;

use WPEasy\ShipStatic\Support\Url;

defined('ABSPATH') || exit;

/**
 * Holds a run's queue, seen-set, counts and errors in a single option so a
 * browser-driven tick loop can resume across requests. Queue items are URLs;
 * dedupe is by page-URL (pages) and by target file path (assets).
 */
final class Job {

    /**
     * Option storing the active (main) job.
     */
    public const OPTION = 'shipstatic_job';

    /**
     * Path segments that are never rendered (matched at segment boundaries).
     */
    private const EXCLUDED_PATTERN = '#(^|/)(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-cron\.php|feed|trackback)(/|$)#i';

    /**
     * Job state.
     *
     * @var array<string,mixed>
     */
    public array $data;

    /**
     * The option this job persists to. The main run uses {@see OPTION}; a
     * parallel per-destination deploy worker uses its own `shipstatic_target_<id>` key
     * so concurrent workers never clobber each other's state.
     */
    private string $option;

    /**
     * @param array<string,mixed> $data   Job state.
     * @param string              $option Storage option name.
     */
    private function __construct(array $data, string $option = self::OPTION) {
        $this->data   = $data;
        $this->option = $option;
    }

    /**
     * Load a job from its option, or null if none.
     *
     * @param string $option Storage option name (defaults to the main job).
     */
    public static function load(string $option = self::OPTION): ?self {
        $data = get_option($option);

        return is_array($data) && !empty($data) ? new self($data, $option) : null;
    }

    /**
     * Create and persist a fresh job.
     *
     * @param string $type   Run type ('check' for a dry run, 'sync' for a push).
     * @param string $option Storage option name (defaults to the main job).
     */
    public static function create(string $type, string $option = self::OPTION): self {
        $job = new self([
            'type'      => $type,
            'phase'     => 'collect',
            'queue'        => ['pages' => [], 'assets' => [], 'uploads' => []],
            'seen'         => ['pages' => [], 'assets' => []],
            // Upload plan: output relative path => absolute local source file.
            // Cached files (pages, rewritten CSS) point into the cache; binary
            // assets point straight at the source filesystem (never copied).
            'plan'         => [],
            'counts'       => ['pagesDone' => 0, 'assetsDone' => 0, 'uploaded' => 0, 'pruned' => 0, 'bytes' => 0, 'files' => 0],
            'totals'       => ['pages' => 0, 'assets' => 0, 'uploads' => 0],
            'prune'        => false,
            'destId'       => '',
            'targets'      => [],
            'targetIndex'  => 0,
            'targetsDone'  => 0,
            'htaccessDone' => false,
            'holdingShown' => false,
            'failed'       => [],
            'removedFiles' => [],
            'removed'      => 0,
            'errors'    => [],
            'skipped'   => [],
            'compat'    => [],
            'cancel'    => false,
            'message'   => 'Collecting URLs…',
            'startedAt' => time(),
            'updatedAt' => time(),
        ], $option);
        $job->save();

        return $job;
    }

    /**
     * Persist the job to its own option.
     */
    public function save(): void {
        $this->data['updatedAt'] = time();
        update_option($this->option, $this->data, false);
    }

    /**
     * Delete a job's option (defaults to the main job).
     *
     * @param string $option Storage option name.
     */
    public static function clear(string $option = self::OPTION): void {
        delete_option($option);
    }

    /**
     * Queue a page URL for rendering (deduped, filtered, query-less only).
     *
     * @param string $url Absolute URL.
     */
    public function enqueue_page(string $url): void {
        if (!Url::is_internal($url) || !Url::in_home($url)) {
            return; // Another site, or this host outside WordPress's sub-path.
        }

        if (wp_parse_url($url, PHP_URL_QUERY)) {
            return; // A page with a query string can't be a static file.
        }

        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        if (preg_match(self::EXCLUDED_PATTERN, $path) || strpos($path, '@') !== false) {
            return; // Excluded path, or a mis-parsed email/text link.
        }

        if (Url::to_relative_path($url) === null || isset($this->data['seen']['pages'][$url])) {
            return;
        }

        $this->data['seen']['pages'][$url] = true;
        $this->data['queue']['pages'][]    = $url;
    }

    /**
     * Queue an asset URL for copying (deduped by target file path).
     *
     * @param string $url Absolute URL.
     */
    public function enqueue_asset(string $url): void {
        if (!Url::is_internal($url)) {
            return;
        }

        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match(self::EXCLUDED_PATTERN, $path)) {
            return; // Don't mirror feeds, wp-json, etc. linked as <link href>.
        }

        $rel = Url::to_relative_path($url);
        if ($rel === null || isset($this->data['seen']['assets'][$rel])) {
            return;
        }

        $this->data['seen']['assets'][$rel] = true;
        $this->data['queue']['assets'][]    = $url;
    }

    /**
     * Record a skipped URL.
     *
     * @param string $url    URL.
     * @param string $reason Reason.
     */
    public function skip(string $url, string $reason): void {
        $this->data['skipped'][] = ['url' => $url, 'reason' => $reason];
    }

    /**
     * Record an error.
     *
     * @param string $url   URL.
     * @param string $error Message.
     */
    public function error(string $url, string $error): void {
        $this->data['errors'][] = ['url' => $url, 'error' => $error];
    }
}
