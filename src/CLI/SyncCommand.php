<?php
/**
 * WP-CLI command for rendering and syncing the static site.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\CLI;

use WPEasy\ShipStatic\Deploy\ConnectorScript;
use WPEasy\ShipStatic\Lockdown\Settings as LockdownSettings;
use WPEasy\ShipStatic\Lockdown\Writer as LockdownWriter;
use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Sync\Runner;
use WPEasy\ShipStatic\Transport\CloudflareTransport;
use WPEasy\ShipStatic\Transport\GitHubTransport;
use WPEasy\ShipStatic\Transport\ConnectorTransport;
use WPEasy\ShipStatic\Transport\TransportFactory;

defined('ABSPATH') || exit;

/**
 * Runs the sync from the command line.
 *
 * This is the reliable way to render on hosts that serialize PHP requests
 * (e.g. Local on Windows): a CLI process isn't a web request, so its loopback
 * page renders aren't competing with it for a web worker.
 */
final class SyncCommand {

    /**
     * Render the site and push it to the destination.
     *
     * ## OPTIONS
     *
     * [--check]
     * : Dry run — render locally and report the delta, without uploading.
     *
     * [--prune]
     * : Also remove destination files that no longer exist locally (sync only).
     *
     * [--dest=<id>]
     * : Sync only the destination with this id.
     *
     * [--all]
     * : Sync all enabled destinations (the default when no --dest is given).
     *
     * ## EXAMPLES
     *
     *     wp shipstatic sync                      # all enabled destinations
     *     wp shipstatic sync --check
     *     wp shipstatic sync --all --prune
     *     wp shipstatic sync --dest=ab12cd34ef56  # one destination
     *
     * @param array<int,string>    $args       Positional args (unused).
     * @param array<string,string> $assoc_args Flags.
     * @when after_wp_load
     */
    public function sync(array $args, array $assoc_args): void {
        $type  = isset($assoc_args['check']) ? 'check' : 'sync';
        $prune = isset($assoc_args['prune']);

        // A specific destination, otherwise every enabled one (incl. --all).
        $options['targets'] = isset($assoc_args['dest'])
            ? [(string) $assoc_args['dest']]
            : Destinations::enabled_ids();

        if (empty($options['targets'])) {
            \WP_CLI::error('No enabled destinations.');
            return;
        }

        $names = array_map(static function (string $id): string {
            $dest = Destinations::get($id);
            return $dest !== null ? (string) $dest->get('name') : $id;
        }, $options['targets']);
        \WP_CLI::log('Destinations: ' . implode(', ', $names));

        \WP_CLI::log(sprintf('Starting %s%s…', $type, $prune ? ' (prune)' : ''));

        try {
            $snapshot = Runner::start($type, $options);
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }

        $last_phase   = '';
        $last_targets = []; // destId => last-reported status, for per-destination lines
        while (!empty($snapshot['running'])) {
            $snapshot = Runner::tick();

            if (($snapshot['phase'] ?? '') !== $last_phase) {
                \WP_CLI::log('  ' . ($snapshot['message'] ?? $snapshot['phase']));
                $last_phase = $snapshot['phase'];
            }

            // Per-destination progress during a parallel deploy (the phase stays
            // 'deploy' throughout, so log each target as it starts and finishes).
            foreach ((array) ($snapshot['parallelTargets'] ?? []) as $t) {
                $id     = (string) ($t['destId'] ?? '');
                $status = (string) ($t['status'] ?? '');
                if ($id === '' || ($last_targets[$id] ?? '') === $status) {
                    continue;
                }
                $last_targets[$id] = $status;
                $label = [
                    'active'    => !empty($t['packaging']) ? 'packaging' : 'uploading',
                    'done'      => 'done',
                    'error'     => 'failed',
                    'cancelled' => 'cancelled',
                ][$status] ?? null;
                if ($label !== null) {
                    // Package deploy is a single server-side step — no per-file count.
                    $suffix = ($status === 'active' && !empty($t['packaging']))
                        ? ''
                        : sprintf(' (%d/%d)', (int) ($t['uploaded'] ?? 0), (int) ($t['total'] ?? 0));
                    \WP_CLI::log('    ' . (string) ($t['name'] ?? $id) . ': ' . $label . $suffix);
                }
            }
        }

        $counts = $snapshot['counts'] ?? [];
        \WP_CLI::log(sprintf(
            'Pages: %d, assets: %d, files: %d, uploaded: %d, removed: %d, errors: %d, skipped: %d',
            $counts['pagesDone'] ?? 0,
            $counts['assetsDone'] ?? 0,
            $counts['files'] ?? 0,
            $counts['uploaded'] ?? 0,
            $counts['pruned'] ?? 0,
            $snapshot['errorCount'] ?? 0,
            $snapshot['skippedCount'] ?? 0
        ));

        foreach (array_slice($snapshot['errors'] ?? [], 0, 25) as $error) {
            \WP_CLI::warning(sprintf('%s — %s', $error['url'], $error['error']));
        }

        if (($snapshot['compatCount'] ?? 0) > 0) {
            \WP_CLI::warning(sprintf('%d page(s) contain forms or dynamic endpoints that will not work on the static copy:', $snapshot['compatCount']));
            foreach (array_slice($snapshot['compat'] ?? [], 0, 10) as $c) {
                \WP_CLI::log('  - ' . $c['url']);
                foreach ($c['issues'] as $issue) {
                    $detail = $issue['link'] !== '' ? $issue['link'] : '(no link)';
                    \WP_CLI::log('      ' . $issue['type'] . ': ' . $detail);
                }
            }
        }

        if (($snapshot['phase'] ?? '') === 'done') {
            \WP_CLI::success((string) ($snapshot['message'] ?? 'Done.'));
        } else {
            \WP_CLI::error((string) ($snapshot['message'] ?? 'Finished with errors.'));
        }
    }

    /**
     * Set up or test a destination's Cloudflare (Workers static assets)
     * connection without the dashboard. See docs/CLOUDFLARE_PLAN.md.
     *
     * ## OPTIONS
     *
     * <action>
     * : What to do.
     * ---
     * options:
     *   - set
     *   - test
     *   - status
     * ---
     *
     * [<dest>]
     * : Destination id (see `status`). Defaults to the primary destination.
     *
     * [--account=<id>]
     * : For `set`: the Cloudflare account ID (32 hex characters).
     *
     * [--worker=<name>]
     * : For `set`: the Worker name to publish to (created on first sync).
     *
     * [--token-from-env]
     * : For `set`: read the API token from the CLOUDFLARE_API_TOKEN environment variable (keeps it out of shell history).
     *
     * [--dest-url=<url>]
     * : For `set`: the Destination URL (your custom domain or the workers.dev address). (`--url` is WP-CLI's own flag, hence the name.)
     *
     * ## EXAMPLES
     *
     *     wp shipstatic cloudflare status
     *     CLOUDFLARE_API_TOKEN=… wp shipstatic cloudflare set ab12cd34ef56 --account=0123…cdef --worker=my-site --token-from-env
     *     wp shipstatic cloudflare test ab12cd34ef56
     *
     * @param array<int,string>    $args       [action, dest].
     * @param array<string,string> $assoc_args Flags.
     * @when after_wp_load
     */
    public function cloudflare(array $args, array $assoc_args): void {
        $action  = (string) ($args[0] ?? 'status');
        $dest_id = (string) ($args[1] ?? Destinations::primary()->id());
        $dest    = Destinations::get($dest_id);
        if ($dest === null) {
            \WP_CLI::error('Unknown destination: ' . $dest_id);
        }

        switch ($action) {
            case 'status':
                foreach (Destinations::visible_objects() as $d) {
                    if (!$d->is_cloudflare()) {
                        continue;
                    }
                    \WP_CLI::log(sprintf(
                        '%s  %-24s worker=%-20s account=%s token=%s url=%s',
                        $d->id(),
                        (string) $d->get('name'),
                        (string) $d->get('cfWorker'),
                        (string) $d->get('cfAccountId'),
                        (string) $d->get('cfApiToken') !== '' ? 'set' : 'missing',
                        (string) $d->get('destinationUrl')
                    ));
                }
                return;

            case 'set':
                $input = ['transport' => 'cloudflare'];
                if (isset($assoc_args['account'])) {
                    $input['cfAccountId'] = (string) $assoc_args['account'];
                }
                if (isset($assoc_args['worker'])) {
                    $input['cfWorker'] = (string) $assoc_args['worker'];
                }
                if (isset($assoc_args['dest-url'])) {
                    $input['destinationUrl'] = (string) $assoc_args['dest-url'];
                }
                if (!empty($assoc_args['token-from-env'])) {
                    $token = (string) getenv('CLOUDFLARE_API_TOKEN');
                    if ($token === '') {
                        \WP_CLI::error('CLOUDFLARE_API_TOKEN is not set.');
                    }
                    $input['cfApiToken'] = $token;
                }
                Destinations::update($dest_id, $input);
                \WP_CLI::success('Saved. Run `wp shipstatic cloudflare test ' . $dest_id . '` next.');
                return;

            case 'test':
                if (!$dest->is_cloudflare()) {
                    \WP_CLI::error('That destination does not use the Cloudflare transport (`cloudflare set` switches it).');
                }
                $transport = TransportFactory::make($dest->connection_config());
                try {
                    $transport->connect();
                } catch (\Throwable $e) {
                    update_option('shipstatic_conn_' . $dest_id, ['ok' => false, 'time' => time(), 'message' => $e->getMessage()], false);
                    \WP_CLI::error($e->getMessage());
                }
                if (!$transport instanceof CloudflareTransport) {
                    \WP_CLI::error('Not a Cloudflare transport.');
                }
                update_option('shipstatic_conn_' . $dest_id, ['ok' => true, 'time' => time(), 'message' => $transport->describe()], false);
                \WP_CLI::success('Cloudflare token accepted — ' . $transport->describe() . '.');
                if (trim((string) $dest->get('destinationUrl')) === '' && $transport->workers_dev_url() !== '') {
                    \WP_CLI::log('Tip: set the Destination URL with `cloudflare set ' . $dest_id . ' --dest-url=' . $transport->workers_dev_url() . '`.');
                }
                return;
        }

        \WP_CLI::error('Unknown action: ' . $action);
    }

    /**
     * Set up or test a destination's GitHub Pages connection without the
     * dashboard. See docs/GITHUB_PAGES_PLAN.md.
     *
     * ## OPTIONS
     *
     * <action>
     * : What to do.
     * ---
     * options:
     *   - set
     *   - test
     *   - status
     * ---
     *
     * [<dest>]
     * : Destination id (see `status`). Defaults to the primary destination.
     *
     * [--owner=<owner>]
     * : For `set`: the account or organisation that owns the repository.
     *
     * [--repo=<repo>]
     * : For `set`: the repository name.
     *
     * [--branch=<branch>]
     * : For `set`: the branch to publish to (default gh-pages).
     *
     * [--folder=<folder>]
     * : For `set`: `/` (the whole branch) or `/docs`.
     *
     * [--token-from-env]
     * : For `set`: read the access token from the GITHUB_TOKEN environment variable (keeps it out of shell history).
     *
     * [--dest-url=<url>]
     * : For `set`: the Destination URL (the Pages address or your custom domain). (`--url` is WP-CLI's own flag, hence the name.)
     *
     * ## EXAMPLES
     *
     *     wp shipstatic github status
     *     GITHUB_TOKEN=… wp shipstatic github set ab12cd34ef56 --owner=my-account --repo=my-site --token-from-env
     *     wp shipstatic github test ab12cd34ef56
     *
     * @param array<int,string>    $args       [action, dest].
     * @param array<string,string> $assoc_args Flags.
     * @when after_wp_load
     */
    public function github(array $args, array $assoc_args): void {
        $action  = (string) ($args[0] ?? 'status');
        $dest_id = (string) ($args[1] ?? Destinations::primary()->id());
        $dest    = Destinations::get($dest_id);
        if ($dest === null) {
            \WP_CLI::error('Unknown destination: ' . $dest_id);
        }

        switch ($action) {
            case 'status':
                foreach (Destinations::visible_objects() as $d) {
                    if (!$d->is_github()) {
                        continue;
                    }
                    \WP_CLI::log(sprintf(
                        '%s  %-24s repo=%s/%s branch=%s folder=%s token=%s url=%s',
                        $d->id(),
                        (string) $d->get('name'),
                        (string) $d->get('ghOwner'),
                        (string) $d->get('ghRepo'),
                        (string) $d->get('ghBranch'),
                        (string) $d->get('ghFolder'),
                        (string) $d->get('ghToken') !== '' ? 'set' : 'missing',
                        (string) $d->get('destinationUrl')
                    ));
                }
                return;

            case 'set':
                $input = ['transport' => 'github'];
                foreach (['owner' => 'ghOwner', 'repo' => 'ghRepo', 'branch' => 'ghBranch', 'folder' => 'ghFolder', 'dest-url' => 'destinationUrl'] as $flag => $key) {
                    if (isset($assoc_args[$flag])) {
                        $input[$key] = (string) $assoc_args[$flag];
                    }
                }
                if (!empty($assoc_args['token-from-env'])) {
                    $token = (string) getenv('GITHUB_TOKEN');
                    if ($token === '') {
                        \WP_CLI::error('GITHUB_TOKEN is not set.');
                    }
                    $input['ghToken'] = $token;
                }
                Destinations::update($dest_id, $input);
                \WP_CLI::success('Saved. Run `wp shipstatic github test ' . $dest_id . '` next.');
                return;

            case 'test':
                if (!$dest->is_github()) {
                    \WP_CLI::error('That destination does not use the GitHub Pages transport (`github set` switches it).');
                }
                $transport = TransportFactory::make($dest->connection_config());
                if (!$transport instanceof GitHubTransport) {
                    \WP_CLI::error('Not a GitHub Pages transport.');
                }
                try {
                    $check = $transport->check();
                } catch (\Throwable $e) {
                    $check = ['ok' => false, 'message' => $e->getMessage(), 'suggestedUrl' => '', 'warnings' => []];
                }
                update_option('shipstatic_conn_' . $dest_id, ['ok' => $check['ok'], 'time' => time(), 'message' => $check['message']], false);
                foreach ($check['warnings'] as $warning) {
                    \WP_CLI::warning($warning);
                }
                if (!$check['ok']) {
                    \WP_CLI::error($check['message']);
                }
                \WP_CLI::success($check['message'] . '.');
                if (trim((string) $dest->get('destinationUrl')) === '' && $check['suggestedUrl'] !== '') {
                    \WP_CLI::log('Tip: set the Destination URL with `github set ' . $dest_id . ' --dest-url=' . $check['suggestedUrl'] . '`.');
                }
                return;
        }

        \WP_CLI::error('Unknown action: ' . $action);
    }

    /**
     * Manage a destination's Connector (HTTPS) pairing without the dashboard.
     *
     * ## OPTIONS
     *
     * <action>
     * : What to do.
     * ---
     * options:
     *   - pair
     *   - revoke
     *   - test
     *   - rebuild
     *   - status
     * ---
     *
     * [<dest>]
     * : Destination id (see `status`). Defaults to the primary destination.
     *
     * [--to=<dir>]
     * : For `pair`: directory to write the connector file into (default: current directory).
     *
     * [--dest-url=<url>]
     * : For `pair`: set the Destination URL first (https://…, the folder the connector will live in). (`--url` is WP-CLI's own flag, hence the name.)
     *
     * ## EXAMPLES
     *
     *     wp shipstatic connector status
     *     wp shipstatic connector pair ab12cd34ef56 --dest-url=https://example.com --to=/tmp
     *     wp shipstatic connector test ab12cd34ef56
     *     wp shipstatic connector rebuild ab12cd34ef56
     *     wp shipstatic connector revoke ab12cd34ef56
     *
     * @param array<int,string>    $args       [action, dest].
     * @param array<string,string> $assoc_args Flags.
     * @when after_wp_load
     */
    public function connector(array $args, array $assoc_args): void {
        $action  = (string) ($args[0] ?? 'status');
        $dest_id = (string) ($args[1] ?? Destinations::primary()->id());
        $dest    = Destinations::get($dest_id);
        if ($dest === null) {
            \WP_CLI::error('Unknown destination: ' . $dest_id);
        }

        switch ($action) {
            case 'status':
                foreach (Destinations::visible_objects() as $d) {
                    $paired = (string) $d->get('connectorId');
                    \WP_CLI::log(sprintf(
                        '%s  %-24s transport=%-9s %s',
                        $d->id(),
                        (string) $d->get('name'),
                        (string) $d->get('transport'),
                        $d->is_connector() ? ($paired !== '' ? 'paired: ' . ConnectorScript::filename($paired) : 'not paired') : ''
                    ));
                }
                return;

            case 'pair':
                if (isset($assoc_args['dest-url'])) {
                    Destinations::update($dest_id, ['destinationUrl' => (string) $assoc_args['dest-url'], 'transport' => 'connector']);
                } elseif (!$dest->is_connector()) {
                    Destinations::update($dest_id, ['transport' => 'connector']);
                }
                $dest = Destinations::get($dest_id);
                $url  = trim((string) $dest->get('destinationUrl'));
                if ($url === '') {
                    \WP_CLI::error('Set the Destination URL first (--dest-url=https://…).');
                }
                $pair = Destinations::pair_connector($dest_id);
                if ($pair === null) {
                    \WP_CLI::error('Pairing failed.');
                }
                $dir  = rtrim((string) ($assoc_args['to'] ?? getcwd()), '/\\');
                $path = $dir . DIRECTORY_SEPARATOR . ConnectorScript::filename($pair['id']);
                if (file_put_contents($path, ConnectorScript::generate($pair['id'], $pair['secret'])) === false) {
                    \WP_CLI::error('Could not write ' . $path);
                }
                \WP_CLI::success(sprintf('Paired. Upload %s to the folder served at %s, then run `connector test`.', $path, $url));
                return;

            case 'revoke':
                Destinations::revoke_connector($dest_id);
                \WP_CLI::success('Connector revoked — the file on the destination will refuse every request from now on.');
                return;

            case 'test':
            case 'rebuild':
                if (!$dest->is_connector()) {
                    \WP_CLI::error('That destination does not use the Connector transport.');
                }
                try {
                    $transport = TransportFactory::make($dest->connection_config());
                    $transport->connect();
                } catch (\Throwable $e) {
                    \WP_CLI::error($e->getMessage());
                }
                if (!$transport instanceof ConnectorTransport) {
                    \WP_CLI::error('Not a connector transport.');
                }
                if ($action === 'test') {
                    update_option('shipstatic_conn_' . $dest_id, ['ok' => true, 'time' => time(), 'message' => $transport->describe()], false);
                    \WP_CLI::success('Connector reached — ' . $transport->describe());
                    return;
                }
                if (!Runner::render_is_current()) {
                    \WP_CLI::error('Process the pages first (`wp shipstatic sync --check`) — the push record is rebuilt against the current render.');
                }
                $r = Runner::rebuild_pushed_record($dest_id, $transport->manifest());
                \WP_CLI::success(sprintf('Push record rebuilt: %d match, %d will upload, %d unmanaged files left alone. %s', $r['matched'], $r['total'] - $r['matched'], $r['unmanaged'], $r['inSync'] ? 'In sync.' : 'Run Sync to finish.'));
                return;
        }

        \WP_CLI::error('Unknown action: ' . $action);
    }

    /**
     * Lock this WordPress site to its allowed addresses, or get back in. See
     * docs/LOCKDOWN_PLAN.md. WP-CLI itself is never locked out.
     *
     * ## OPTIONS
     *
     * <action>
     * : What to do.
     * ---
     * options:
     *   - status
     *   - on
     *   - off
     *   - allow
     *   - remove
     *   - link
     * ---
     *
     * [<address>]
     * : For `allow` / `remove`: an IP address or range (203.0.113.7, 203.0.113.0/24, 2001:db8::/64).
     *
     * [--label=<text>]
     * : For `allow`: a label for the address.
     *
     * [--regenerate]
     * : For `link`: make a new unlock link (the old one and every unlock cookie stop working).
     *
     * ## EXAMPLES
     *
     *     wp shipstatic lockdown status
     *     wp shipstatic lockdown allow 203.0.113.7 --label="Office"
     *     wp shipstatic lockdown off
     *
     * @param array<int,string>    $args       [action, address].
     * @param array<string,string> $assoc_args Flags.
     * @when after_wp_load
     */
    public function lockdown(array $args, array $assoc_args): void {
        $action = (string) ($args[0] ?? 'status');

        switch ($action) {
            case 'status':
                $s = LockdownSettings::get();
                \WP_CLI::log('Lockdown: ' . ($s['enabled'] ? 'on' : 'off'));
                foreach ($s['entries'] as $e) {
                    \WP_CLI::log(sprintf('  %-24s %-8s %s', $e['cidr'], $e['via'], $e['label']));
                }
                foreach (LockdownWriter::layers() as $layer => $state) {
                    \WP_CLI::log(sprintf('  %-9s %s', $layer, $state));
                }
                if (LockdownWriter::environment()['server'] === '') {
                    \WP_CLI::warning('The server type is not known yet: open the Lockdown tab once so .htaccess / .user.ini can be written.');
                }
                return;

            case 'on':
                if (LockdownSettings::cidrs() === []) {
                    \WP_CLI::error('Add an address first (`lockdown allow <address>`): from the command line there is no visitor address to add.');
                }
                LockdownSettings::key();
                LockdownSettings::update(['enabled' => true]);
                break;

            case 'off':
                LockdownSettings::update(['enabled' => false]);
                break;

            case 'allow':
            case 'remove':
                $cidr = LockdownSettings::validate_cidr((string) ($args[1] ?? ''));
                if (is_wp_error($cidr)) {
                    \WP_CLI::error($cidr->get_error_message());
                }
                if ($action === 'allow') {
                    LockdownSettings::add_entry($cidr, (string) ($assoc_args['label'] ?? ''), 'cli');
                } elseif (!LockdownSettings::remove_entry($cidr)) {
                    \WP_CLI::error($cidr . ' is not on the list.');
                }
                break;

            case 'link':
                if (!empty($assoc_args['regenerate'])) {
                    LockdownSettings::regenerate_key();
                    $this->lockdown_apply();
                }
                \WP_CLI::log(LockdownSettings::unlock_url());
                return;

            default:
                \WP_CLI::error('Unknown action: ' . $action);
        }

        $this->lockdown_apply();
        \WP_CLI::success('Lockdown is ' . (LockdownSettings::enabled() ? 'on' : 'off') . '.');
    }

    /**
     * Write or remove the Lockdown files, reporting problems.
     */
    private function lockdown_apply(): void {
        foreach (LockdownWriter::apply() as $problem) {
            \WP_CLI::warning($problem);
        }
    }

    /**
     * Drive an already-started job to completion (used by the dashboard's
     * auto-run; not normally invoked by hand).
     *
     * @param array<int,string>    $args       Positional args (unused).
     * @param array<string,string> $assoc_args Flags (unused).
     * @when after_wp_load
     */
    public function run(array $args, array $assoc_args): void {
        $snapshot = Runner::status();

        if (($snapshot['phase'] ?? 'idle') === 'idle') {
            \WP_CLI::warning('No active job to run.');
            return;
        }

        // This is the detached, console-less coordinator: it must not try to spawn
        // the parallel deploy pool itself (Windows `start` needs a console) — the
        // dashboard dispatches the workers from a web request instead.
        Runner::mark_detached();

        // Only one driver may advance a job (see Runner::claim_driver). If the
        // browser already took over (because this spawn was slow to launch), stand
        // down instead of double-driving and starving the worker pool.
        if (Runner::claim_driver('cli') !== 'cli') {
            \WP_CLI::warning('Another driver is already running this job; nothing to do.');
            return;
        }

        while (!empty($snapshot['running'])) {
            $snapshot = Runner::tick();
        }

        \WP_CLI::success((string) ($snapshot['message'] ?? 'Done.'));
    }

    /**
     * Deploy-pool worker: claim and upload one destination at a time until the
     * pool's target list is exhausted. Spawned (detached, one per concurrency
     * slot) by the parallel deploy phase; not normally invoked by hand.
     *
     * @param array<int,string>    $args       Positional args (unused).
     * @param array<string,string> $assoc_args Flags (unused).
     * @when after_wp_load
     */
    public function deploy_worker(array $args, array $assoc_args): void {
        Runner::run_deploy_worker();
        \WP_CLI::success('Deploy worker finished.');
    }
}
