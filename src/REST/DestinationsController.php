<?php
/**
 * Destinations CRUD + per-destination connection test.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\REST;

use WP_REST_Request;
use WP_REST_Response;
use WPEasy\ShipStatic\Deploy\ConnectorScript;
use WPEasy\ShipStatic\Deploy\PackageDeployer;
use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Settings\Schema;
use WPEasy\ShipStatic\Support\UrlSafety;
use WPEasy\ShipStatic\Sync\Manifest;
use WPEasy\ShipStatic\Sync\Runner;
use WPEasy\ShipStatic\Transport\CloudflareTransport;
use WPEasy\ShipStatic\Transport\GitHubTransport;
use WPEasy\ShipStatic\Transport\ConnectorTransport;
use WPEasy\ShipStatic\Transport\FolderTransport;
use WPEasy\ShipStatic\Transport\TransportFactory;

defined('ABSPATH') || exit;

/**
 * Manages the list of destinations and tests a given destination's connection.
 */
final class DestinationsController {

    /**
     * REST namespace.
     */
    private const NS = 'shipstatic/v1';

    /**
     * Register routes.
     */
    public static function register(): void {
        register_rest_route(self::NS, '/destinations', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'index'],
                'permission_callback' => [self::class, 'can_manage'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'add'],
                'permission_callback' => [self::class, 'can_manage'],
            ],
        ]);

        register_rest_route(self::NS, '/destinations/(?P<id>[A-Za-z0-9]+)', [
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'update'],
                'permission_callback' => [self::class, 'can_manage'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [self::class, 'remove'],
                'permission_callback' => [self::class, 'can_manage'],
            ],
        ]);

        register_rest_route(self::NS, '/destinations/(?P<id>[A-Za-z0-9]+)/test', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'test'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/destinations/(?P<id>[A-Za-z0-9]+)/package-test', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'package_test'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/destinations/(?P<id>[A-Za-z0-9]+)/connector/pair', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'connector_pair'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/destinations/(?P<id>[A-Za-z0-9]+)/connector/revoke', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'connector_revoke'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        register_rest_route(self::NS, '/destinations/(?P<id>[A-Za-z0-9]+)/connector/rebuild', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'connector_rebuild'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        // Streams the generated receiver (single-use token from /connector/pair).
        // Same shape as /export/download — a shipstatic/v1 REST route, not admin_post_*.
        register_rest_route(self::NS, '/connector/download', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'connector_download'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);
    }

    /**
     * Transient prefix for connector download tokens.
     */
    private const CONNECTOR_DL = 'shipstatic_connector_dl_';

    /**
     * POST /destinations/{id}/connector/pair — generate a fresh pairing
     * (rotating any previous one) and hand back a one-time download token for
     * the receiver file. The raw secret lives only in the encrypted option and,
     * for the next 10 minutes, in the download transient; the file carries its
     * hash.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function connector_pair(WP_REST_Request $request): WP_REST_Response {
        $id   = (string) $request['id'];
        $dest = Destinations::get($id);
        if ($dest === null) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Unknown destination.'], 404);
        }

        $url  = trim((string) $dest->get('destinationUrl'));
        $host = (string) (wp_parse_url($url, PHP_URL_HOST) ?? '');
        if ($url === '' || $host === '') {
            return new WP_REST_Response(['ok' => false, 'message' => __('Enter and save the Destination URL first — the connector is called at that address.', 'shipstatic')]);
        }
        if (!\WPEasy\ShipStatic\Deploy\ConnectorClient::url_is_secure($url)) {
            return new WP_REST_Response(['ok' => false, 'message' => __('The connector needs an HTTPS Destination URL (the pairing secret travels in each request).', 'shipstatic')]);
        }
        if (!UrlSafety::is_safe_url($url)) {
            return new WP_REST_Response(['ok' => false, 'message' => __('That Destination URL resolves to a private or reserved network address and cannot be used.', 'shipstatic')]);
        }

        $pair = Destinations::pair_connector($id);
        if ($pair === null) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Unknown destination.'], 404);
        }

        $token = bin2hex(random_bytes(24));
        set_transient(self::CONNECTOR_DL . $token, ['dest' => $id, 'id' => $pair['id'], 'secret' => $pair['secret']], 10 * MINUTE_IN_SECONDS);

        return new WP_REST_Response([
            'ok'            => true,
            'connectorId'   => $pair['id'],
            'filename'      => ConnectorScript::filename($pair['id']),
            'downloadToken' => $token,
            'message'       => __('Paired. Download the connector file and upload it to the folder served at the Destination URL.', 'shipstatic'),
        ]);
    }

    /**
     * POST /destinations/{id}/connector/revoke — forget the pairing; the file
     * on the destination refuses everything from now on.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function connector_revoke(WP_REST_Request $request): WP_REST_Response {
        $ok = Destinations::revoke_connector((string) $request['id']);

        return new WP_REST_Response(['ok' => $ok, 'message' => $ok ? __('Connector revoked. Delete the file from the destination when convenient.', 'shipstatic') : 'Unknown destination.'], $ok ? 200 : 404);
    }

    /**
     * POST /destinations/{id}/connector/rebuild — ask the receiver what it
     * holds and rebuild this destination's push record from it (see
     * Runner::rebuild_pushed_record()). Needs a current render, like Check.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function connector_rebuild(WP_REST_Request $request): WP_REST_Response {
        $id   = (string) $request['id'];
        $dest = Destinations::get($id);
        if ($dest === null) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Unknown destination.'], 404);
        }
        if (!$dest->is_connector()) {
            return new WP_REST_Response(['ok' => false, 'message' => __('Only a Connector destination can report its files.', 'shipstatic')]);
        }
        if (!Runner::render_is_current()) {
            return new WP_REST_Response(['ok' => false, 'needsProcess' => true, 'message' => __('Process the pages first — the push record is rebuilt against the current render.', 'shipstatic')]);
        }

        try {
            $transport = TransportFactory::make($dest->connection_config());
            if (!$transport instanceof ConnectorTransport) {
                return new WP_REST_Response(['ok' => false, 'message' => 'Not a connector transport.']);
            }
            $transport->connect();
            $remote = $transport->manifest();
        } catch (\Throwable $e) {
            return new WP_REST_Response(['ok' => false, 'message' => $e->getMessage()]);
        }

        $r = Runner::rebuild_pushed_record($id, $remote);

        $message = $r['inSync']
            /* translators: %d: number of files found on the destination */
            ? sprintf(__('Push record rebuilt — all %d files are already on the destination and match. In sync.', 'shipstatic'), $r['matched'])
            /* translators: 1: matching files, 2: differing/missing files */
            : sprintf(__('Push record rebuilt — %1$d files match, %2$d will upload on the next Sync.', 'shipstatic'), $r['matched'], $r['total'] - $r['matched']);
        if ($r['unmanaged'] > 0) {
            /* translators: %d: number of files on the destination the plugin doesn't manage */
            $message .= ' ' . sprintf(__('(%d other files there are left alone.)', 'shipstatic'), $r['unmanaged']);
        }

        return new WP_REST_Response(['ok' => true, 'message' => $message] + $r);
    }

    /**
     * GET /connector/download?token=… — stream the generated receiver and exit
     * (mirrors ExportDownload: manage_options + wp_rest nonce via ?_wpnonce,
     * opaque single-use token, nothing path-like taken from the request).
     *
     * @param WP_REST_Request $request Request.
     */
    public static function connector_download(WP_REST_Request $request) {
        $token  = sanitize_text_field((string) $request->get_param('token'));
        $record = $token !== '' ? get_transient(self::CONNECTOR_DL . $token) : false;
        if (!is_array($record) || empty($record['id']) || empty($record['secret'])) {
            return new WP_REST_Response(['message' => __('This download link has expired — pair again to get a new connector file.', 'shipstatic')], 404);
        }
        delete_transient(self::CONNECTOR_DL . $token);

        // The pairing may have been rotated/revoked since the token was issued.
        $dest = Destinations::get((string) ($record['dest'] ?? ''));
        if ($dest === null || (string) $dest->get('connectorId') !== (string) $record['id']) {
            return new WP_REST_Response(['message' => __('This pairing is no longer current — pair again.', 'shipstatic')], 410);
        }

        $source = ConnectorScript::generate((string) $record['id'], (string) $record['secret']);

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . ConnectorScript::filename((string) $record['id']) . '"');
        header('Content-Length: ' . (string) strlen($source));
        echo $source; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated PHP source, streamed as a download.
        exit;
    }

    /**
     * POST /destinations/{id}/package-test — actually run a tiny package deploy to
     * confirm the host can extract it, and re-enable package deploy on success.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function package_test(WP_REST_Request $request): WP_REST_Response {
        $id   = (string) $request['id'];
        $dest = Destinations::get($id);
        if ($dest === null) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Unknown destination.'], 404);
        }
        if ($dest->is_snapshot()) {
            return new WP_REST_Response(['ok' => false, 'message' => __('This destination publishes whole snapshots — package deploy does not apply.', 'shipstatic')]);
        }

        if (!PackageDeployer::can_build()) {
            return new WP_REST_Response(['ok' => false, 'message' => 'ZipArchive is not available on this server.']);
        }
        $base = PackageDeployer::base_url($dest);
        if ($base === '') {
            return new WP_REST_Response(['ok' => false, 'message' => 'Set a Destination URL first so the helper can be reached.']);
        }

        // get_temp_dir()/tempnam work in every context; wp_tempnam() needs
        // wp-admin/includes/file.php, which isn't loaded on REST/frontend requests.
        $tmp = tempnam(get_temp_dir(), 'bs-pkgtest-');
        if ($tmp === false) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Could not create a temporary file for the test.']);
        }
        file_put_contents($tmp, '<!-- shipstatic package test -->');

        try {
            $transport = TransportFactory::make($dest->connection_config());
            $transport->connect();
            $result = PackageDeployer::deploy(
                $transport,
                $base,
                ['.bs-package-test/probe.html' => $tmp],
                [],
                PackageDeployer::sslverify($base),
                PackageDeployer::url_is_guess($dest)
            );
            if (!empty($result['ok'])) {
                $transport->delete('.bs-package-test/probe.html');
                $transport->delete('.bs-package-test/probe.html.gz');
            }
            $transport->disconnect();
        } catch (\Throwable $e) {
            wp_delete_file($tmp);
            return new WP_REST_Response(['ok' => false, 'message' => $e->getMessage()]);
        }
        wp_delete_file($tmp);

        if (!empty($result['ok'])) {
            delete_option(PackageDeployer::OFF_PREFIX . $id);
            return new WP_REST_Response(['ok' => true, 'message' => 'Package deploy works — re-enabled.']);
        }

        return new WP_REST_Response(['ok' => false, 'message' => $result['message'] ?? 'Package deploy failed.']);
    }

    /**
     * Capability gate.
     */
    public static function can_manage(): bool {
        return current_user_can('manage_options');
    }

    /**
     * GET /destinations — the list + transport capabilities.
     */
    public static function index(): WP_REST_Response {
        return self::list_response();
    }

    /**
     * POST /destinations — add a destination.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function add(WP_REST_Request $request): WP_REST_Response {
        Destinations::add((array) $request->get_json_params());

        return self::list_response();
    }

    /**
     * POST /destinations/{id} — update a destination.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function update(WP_REST_Request $request): WP_REST_Response {
        $updated = Destinations::update((string) $request['id'], (array) $request->get_json_params());
        if ($updated === null) {
            return new WP_REST_Response(['error' => 'Unknown destination.'], 404);
        }

        return self::list_response();
    }

    /**
     * DELETE /destinations/{id} — remove a destination.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function remove(WP_REST_Request $request): WP_REST_Response {
        if (!Destinations::remove((string) $request['id'])) {
            return new WP_REST_Response(['error' => 'Cannot remove the only destination.'], 400);
        }

        return self::list_response();
    }

    /**
     * POST /destinations/{id}/test — test that destination's connection.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function test(WP_REST_Request $request): WP_REST_Response {
        $dest = Destinations::get((string) $request['id']);
        if ($dest === null) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Unknown destination.'], 200);
        }

        $config     = $dest->connection_config();
        $saved_host = (string) $config['host'];
        $input      = (array) $request->get_json_params();
        foreach (['transport', 'host', 'port', 'username', 'remotePath'] as $key) {
            if (array_key_exists($key, $input) && !$dest->is_from_constant($key)) {
                $config[$key] = $key === 'port' ? (int) $input[$key] : sanitize_text_field((string) $input[$key]);
            }
        }
        // Cloudflare / GitHub fields go through the Schema sanitiser (strict
        // character sets — they end up in an API path).
        foreach (['cfAccountId', 'cfWorker', 'ghOwner', 'ghRepo', 'ghBranch', 'ghFolder'] as $key) {
            if (array_key_exists($key, $input) && !$dest->is_from_constant($key)) {
                $config[$key] = (string) Schema::sanitize($key, $input[$key]);
            }
        }
        if (!empty($input['password']) && !$dest->is_from_constant('password')) {
            $config['password'] = (string) $input['password'];
        } elseif (TransportFactory::needs_host((string) ($config['transport'] ?? '')) && !Destinations::same_host($saved_host, (string) $config['host'])) {
            // Never send the saved (or wp-config) password to a server typed
            // into the form — a different host needs it entered again.
            return new WP_REST_Response(['ok' => false, 'message' => __('This is a different server from the saved one — enter its password to test it.', 'shipstatic')], 200);
        }
        if (!empty($input['cfApiToken']) && !$dest->is_from_constant('cfApiToken')) {
            $config['cfApiToken'] = trim((string) $input['cfApiToken']);
        }
        if (!empty($input['ghToken']) && !$dest->is_from_constant('ghToken')) {
            $config['ghToken'] = trim((string) $input['ghToken']);
        }

        if (TransportFactory::needs_host((string) ($config['transport'] ?? '')) && $config['host'] === '') {
            return new WP_REST_Response(['ok' => false, 'message' => 'Enter a host first.'], 200);
        }

        $suggested = '';
        $warnings  = [];
        $transport = TransportFactory::make($config);
        try {
            $ok = true;
            if ($transport instanceof GitHubTransport) {
                // Its own check: read access, a write probe, and the Pages status.
                $check     = $transport->check();
                $ok        = $check['ok'];
                $message   = $check['message'];
                $suggested = $check['suggestedUrl'];
                $warnings  = $check['warnings'];
            } else {
                $transport->connect();
                if ($transport instanceof ConnectorTransport) {
                    /* translators: %s: receiver summary (PHP version, zip support, free space) */
                    $message = sprintf(__('Connector reached — %s.', 'shipstatic'), $transport->describe());
                } elseif ($transport instanceof FolderTransport) {
                    /* translators: %s: folder summary (path, free space) */
                    $message = sprintf(__('Folder ready — %s.', 'shipstatic'), $transport->describe());
                    // Does the Destination URL actually serve this folder?
                    $problem = \WPEasy\ShipStatic\Deploy\ServerFolder::probe($transport->root(), (string) $dest->get('destinationUrl'));
                    if ($problem !== '') {
                        $warnings[] = $problem;
                    }
                } elseif ($transport instanceof CloudflareTransport) {
                    /* translators: %s: Worker summary (name + workers.dev address) */
                    $message   = sprintf(__('Cloudflare token accepted — %s.', 'shipstatic'), $transport->describe());
                    $suggested = $transport->workers_dev_url();
                } else {
                    $message = 'Connected successfully.';
                }
            }
        } catch (\Throwable $e) {
            $ok = false;
            $message = $e->getMessage();
        } finally {
            $transport->disconnect();
        }

        update_option(self::conn_state_option((string) $request['id']), ['ok' => $ok, 'time' => time(), 'message' => $message], false);

        $out = ['ok' => $ok, 'message' => $message];
        if ($suggested !== '') {
            // The dashboard fills an empty Destination URL with this.
            $out['suggestedUrl'] = $suggested;
        }
        if ($ok && $warnings !== []) {
            $out['warnings'] = array_values($warnings);
        }

        return new WP_REST_Response($out, 200);
    }

    /**
     * Option holding a destination's last connection-test result.
     *
     * @param string $id Destination id.
     */
    public static function conn_state_option(string $id): string {
        return 'shipstatic_conn_' . $id;
    }

    /**
     * The destinations list payload, each with its per-destination status.
     */
    private static function list_response(): WP_REST_Response {
        $list = Destinations::for_display();

        foreach ($list as &$dest) {
            $id     = (string) $dest['id'];
            $state  = get_option(self::conn_state_option($id), []);
            $pushed = Manifest::load(Destinations::pushed_option($id));
            $obj    = Destinations::get($id);

            $dest['status'] = [
                'connected' => is_array($state) && !empty($state['ok']),
                'hasPushed' => !empty($pushed),
                // Signature-based: matches the render + this destination's
                // replacements, so a replacement destination isn't false-flagged.
                'inSync'    => !empty($pushed) && Runner::in_sync($id, $obj),
            ];

            // How this destination will be deployed (fast package vs per-file),
            // and whether an explicit Destination URL is set (we guess otherwise).
            $dest['deploy'] = [
                'strategy' => PackageDeployer::available_for($id, $obj) ? 'package' : 'perfile',
                'canBuild' => PackageDeployer::can_build(),
                'hasUrl'   => $obj !== null && trim((string) $obj->get('destinationUrl')) !== '',
                'disabled' => (bool) get_option(PackageDeployer::OFF_PREFIX . $id),
            ];
        }
        unset($dest);

        return new WP_REST_Response([
            'destinations' => $list,
            'capabilities' => TransportFactory::capabilities(),
        ]);
    }
}
