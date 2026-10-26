<?php
/**
 * The list of sync destinations + migration from the single-destination model.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Settings;

defined('ABSPATH') || exit;

/**
 * Manages the destinations list (stored in one option) and migrates the legacy
 * single `shipstatic_settings` destination into `destinations[0]` on first run.
 */
final class Destinations {

    /**
     * Option holding the destinations list.
     */
    public const OPTION = 'shipstatic_destinations';

    /**
     * Legacy single-destination option (migrated then left in place).
     */
    private const LEGACY_OPTION = 'shipstatic_settings';

    /**
     * All destination arrays (raw, password encrypted).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array {
        self::ensure_migrated();
        $list = get_option(self::OPTION, []);

        return is_array($list) ? array_values($list) : [];
    }

    /**
     * All destinations as objects (index 0 is primary).
     *
     * @return array<int,Destination>
     */
    public static function objects(): array {
        $out = [];
        foreach (self::all() as $i => $data) {
            $out[] = new Destination($data, $i === 0);
        }

        return $out;
    }

    /**
     * The primary destination (always exists after migration).
     */
    public static function primary(): Destination {
        $objects = self::objects();

        return $objects[0] ?? new Destination(self::blank(self::new_id(), 'Destination 1'), true);
    }

    /**
     * Destinations, as objects (index 0 is primary). Alias of {@see objects()}
     * kept as the canonical accessor for display/sync call sites.
     *
     * @return array<int,Destination>
     */
    public static function visible_objects(): array {
        return self::objects();
    }

    /**
     * Ids of all enabled destinations (used by "sync all").
     *
     * @return array<int,string>
     */
    public static function enabled_ids(): array {
        $ids = [];
        foreach (self::visible_objects() as $dest) {
            if ((bool) $dest->get('enabled')) {
                $ids[] = $dest->id();
            }
        }

        return $ids;
    }

    /**
     * Ids of enabled destinations opted into single-page (push-this-page) sync.
     *
     * @return array<int,string>
     */
    public static function single_page_ids(): array {
        // Single-page sync targets every enabled destination (same as a full sync).
        return self::enabled_ids();
    }

    /**
     * Find a destination by id.
     *
     * @param string $id Destination id.
     */
    public static function get(string $id): ?Destination {
        foreach (self::objects() as $dest) {
            if ($dest->id() === $id) {
                return $dest;
            }
        }

        return null;
    }

    /**
     * Display payload for all destinations.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function for_display(): array {
        return array_map(static fn(Destination $d): array => $d->for_display(), self::objects());
    }

    /**
     * Add a new destination.
     *
     * @param array<string,mixed> $input Initial values.
     */
    public static function add(array $input): Destination {
        $list  = self::all();
        $count = count($list) + 1;
        $dest  = new Destination(self::blank(self::new_id(), 'Destination ' . $count), false);

        $list[] = $dest->apply($input);
        update_option(self::OPTION, $list, false);

        return new Destination($list[array_key_last($list)], false);
    }

    /**
     * Update a destination by id.
     *
     * @param string              $id    Destination id.
     * @param array<string,mixed> $input New values.
     */
    public static function update(string $id, array $input): ?Destination {
        $list = self::all();

        foreach ($list as $i => $data) {
            if ((string) ($data['id'] ?? '') === $id) {
                $before     = self::target_signature(new Destination($data, $i === 0));
                $dest       = new Destination($data, $i === 0);
                $url_before = trim((string) $dest->get('destinationUrl'));
                $host_before = (string) $dest->connection_config()['host'];
                $list[$i]   = $dest->apply($input);

                // A saved password is never carried to a different server: a
                // new host needs the password typed again (SECURITY_PATTERNS.md §4).
                $moved = new Destination($list[$i], $i === 0);
                if (!self::same_host($host_before, (string) $moved->connection_config()['host'])
                    && empty($input['password']) && !$moved->is_from_constant('password')) {
                    $list[$i]['password'] = '';
                }
                update_option(self::OPTION, $list, false);

                $after     = new Destination($list[$i], $i === 0);
                $url_after = trim((string) $after->get('destinationUrl'));

                // If the destination now points at a different server/location,
                // its push record described the OLD target and is no longer valid
                // — drop it so the next sync uploads everything afresh, and
                // re-probe whether package deploy works on the new host.
                if (self::target_signature($after) !== $before) {
                    delete_option(self::pushed_option($id));
                    delete_option('shipstatic_pkg_off_' . $id);
                    delete_option('shipstatic_sync_sig_' . $id);
                    \WPEasy\ShipStatic\Sync\ServerProbe::forget($id);

                    // Re-pointed at a different server — forget the old target's
                    // trusted SFTP host key so the new one is established afresh.
                    $cfg = $after->connection_config();
                    \WPEasy\ShipStatic\Transport\SftpTransport::forget_host_key((string) $cfg['host'], (int) $cfg['port']);
                } elseif ($url_after !== $url_before) {
                    // Destination URL changed on its own (host unchanged) — the
                    // package-deploy probe result for the OLD guessed/explicit URL
                    // no longer applies, so give the new URL a fresh chance.
                    delete_option('shipstatic_pkg_off_' . $id);
                    \WPEasy\ShipStatic\Sync\ServerProbe::forget($id);
                }

                return $after;
            }
        }

        return null;
    }

    /**
     * Whether two FTP/SFTP hosts are the same server (case-insensitive).
     *
     * @param string $a Host.
     * @param string $b Host.
     */
    public static function same_host(string $a, string $b): bool {
        return strtolower(trim($a)) === strtolower(trim($b));
    }

    /**
     * A fingerprint of WHERE a destination uploads to (not the password). When
     * this changes, any recorded push manifest no longer reflects the remote.
     */
    private static function target_signature(Destination $d): string {
        $c = $d->connection_config();

        // A Connector destination is identified by WHERE the receiver answers,
        // not by an FTP account. (Re-pairing/rotating keeps the same target — the
        // files on the destination don't change — so the pairing isn't part of it.)
        if ($d->is_connector()) {
            return 'connector|' . strtolower(rtrim((string) ($c['url'] ?? ''), '/'));
        }

        // A Cloudflare destination IS its account + Worker; the token can be
        // rotated without the published files changing.
        if ($d->is_cloudflare()) {
            return 'cloudflare|' . (string) ($c['cfAccountId'] ?? '') . '|' . (string) ($c['cfWorker'] ?? '');
        }

        // A GitHub Pages destination IS its repo + branch + folder (GitHub
        // names are case-insensitive); the token can be rotated freely.
        if ($d->is_github()) {
            return strtolower(implode('|', ['github', (string) ($c['ghOwner'] ?? ''), (string) ($c['ghRepo'] ?? ''), (string) ($c['ghBranch'] ?? ''), (string) ($c['ghFolder'] ?? '')]));
        }

        return implode('|', [
            (string) ($c['transport'] ?? ''),
            strtolower((string) ($c['host'] ?? '')),
            (string) ($c['port'] ?? ''),
            (string) ($c['username'] ?? ''),
            trim((string) ($c['remotePath'] ?? ''), '/'),
        ]);
    }

    /**
     * Pair a destination with a new Connector: fresh id + 256-bit secret. Any
     * previous pairing is replaced (rotation), so a lost or compromised
     * connector file is invalidated simply by pairing again.
     *
     * @param string $id Destination id.
     * @return array{id:string,secret:string}|null The NEW pairing (the raw secret
     *                                              is returned once, to embed
     *                                              its hash in the file), or null
     *                                              for an unknown destination.
     */
    public static function pair_connector(string $id): ?array {
        $list = self::all();
        foreach ($list as $i => $data) {
            if ((string) ($data['id'] ?? '') !== $id) {
                continue;
            }
            $connector_id = bin2hex(random_bytes(8));
            $secret       = bin2hex(random_bytes(32));
            $dest         = new Destination($data, $i === 0);
            $list[$i]     = $dest->set_connector($connector_id, $secret, \WPEasy\ShipStatic\Deploy\ConnectorScript::VERSION);
            update_option(self::OPTION, $list, false);
            // The old file (if any) is dead; a stale "connected" state would mislead.
            delete_option('shipstatic_conn_' . $id);

            return ['id' => $connector_id, 'secret' => $secret];
        }

        return null;
    }

    /**
     * Point a destination at a folder on this server and switch it to the
     * folder deploy method. The path must come from Deploy\ServerFolder::prepare().
     *
     * @param string $id   Destination id.
     * @param string $path Folder's real path.
     * @param string $url  Destination URL ('' keeps the current one).
     */
    public static function set_folder(string $id, string $path, string $url = ''): ?Destination {
        $list = self::all();
        foreach ($list as $i => $data) {
            if ((string) ($data['id'] ?? '') !== $id) {
                continue;
            }
            $dest     = new Destination($data, $i === 0);
            $input    = ['transport' => 'folder'] + ($url !== '' ? ['destinationUrl' => $url] : []);
            $dest->set_folder($path);
            $list[$i] = $dest->apply($input);
            update_option(self::OPTION, $list, false);
            delete_option(\WPEasy\ShipStatic\REST\DestinationsController::conn_state_option($id));

            return new Destination($list[$i], $i === 0);
        }

        return null;
    }

    /**
     * Revoke a destination's Connector pairing. The receiver file on the
     * destination then refuses every request until a new one is uploaded.
     *
     * @param string $id Destination id.
     */
    public static function revoke_connector(string $id): bool {
        $list = self::all();
        foreach ($list as $i => $data) {
            if ((string) ($data['id'] ?? '') !== $id) {
                continue;
            }
            $dest     = new Destination($data, $i === 0);
            $list[$i] = $dest->set_connector('', '', 0);
            update_option(self::OPTION, $list, false);
            delete_option('shipstatic_conn_' . $id);

            return true;
        }

        return false;
    }

    /**
     * Remove a destination (and its pushed manifest). The primary cannot be
     * removed while it is the only destination.
     *
     * @param string $id Destination id.
     */
    public static function remove(string $id): bool {
        $list = self::all();
        if (count($list) <= 1) {
            return false;
        }

        $kept = array_values(array_filter($list, static fn(array $d): bool => (string) ($d['id'] ?? '') !== $id));
        if (count($kept) === count($list)) {
            return false;
        }

        update_option(self::OPTION, $kept, false);

        // Everything else kept per destination, so a destination added again
        // (or a new one reusing nothing) starts clean.
        delete_option(self::pushed_option($id));
        delete_option(\WPEasy\ShipStatic\Sync\Manifest::DEPLOY_OPTION . '_' . $id);
        delete_option(\WPEasy\ShipStatic\Sync\Runner::SYNC_SIG . $id);
        delete_option(\WPEasy\ShipStatic\REST\DestinationsController::conn_state_option($id));
        delete_option(\WPEasy\ShipStatic\Deploy\PackageDeployer::OFF_PREFIX . $id);
        delete_option(\WPEasy\ShipStatic\Sync\DeployPool::target_option($id));
        \WPEasy\ShipStatic\Sync\ServerProbe::forget($id);
        // Its per-destination deploy copies in the cache (ids are generated
        // hex, so they can't escape the deploy folder).
        if (preg_match('/^[a-z0-9]+$/i', $id)) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            $dir = \WPEasy\ShipStatic\Support\Paths::cache_dir() . '/deploy/' . $id;
            if (is_dir($dir)) {
                (new \WP_Filesystem_Direct(null))->rmdir($dir, true);
            }
        }

        return true;
    }

    /**
     * Option name holding a destination's pushed manifest.
     *
     * @param string $id Destination id.
     */
    public static function pushed_option(string $id): string {
        return 'shipstatic_pushed_' . $id;
    }

    /**
     * Ensure the destinations list exists, migrating the legacy option once.
     */
    public static function ensure_migrated(): void {
        $existing = get_option(self::OPTION, null);
        if (is_array($existing) && !empty($existing)) {
            return;
        }

        $id   = self::new_id();
        $dest = self::blank($id, 'Destination 1');

        $legacy = get_option(self::LEGACY_OPTION, null);
        if (is_array($legacy)) {
            foreach (Schema::fields() as $key => $field) {
                if (array_key_exists($key, $legacy)) {
                    $dest[$key] = $legacy[$key];
                }
            }
        }

        update_option(self::OPTION, [$dest], false);

        // Carry over the single pushed manifest to the migrated destination.
        $pushed = get_option('shipstatic_pushed_manifest', null);
        if (is_array($pushed)) {
            update_option(self::pushed_option($id), $pushed, false);
        }
    }

    /**
     * A blank destination seeded with field defaults.
     *
     * @param string $id   Destination id.
     * @param string $name Destination name.
     * @return array<string,mixed>
     */
    private static function blank(string $id, string $name): array {
        $data = [
            'id'                      => $id,
            'name'                    => $name,
            'enabled'                 => true,
            'replacements'            => [],
        ];

        foreach (Schema::fields() as $key => $field) {
            $data[$key] = $field['default'];
        }

        return $data;
    }

    /**
     * Generate a short, unique destination id.
     */
    public static function new_id(): string {
        return substr(str_replace('-', '', wp_generate_uuid4()), 0, 12);
    }
}
