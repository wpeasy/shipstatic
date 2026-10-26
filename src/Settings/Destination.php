<?php
/**
 * A single sync destination (connection + meta + text replacements).
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Settings;

use WPEasy\ShipStatic\Support\Crypto;

defined('ABSPATH') || exit;

/**
 * Wraps one destination's stored array and applies the connection-field rules
 * (wp-config constant overrides apply to the primary destination only; secret
 * fields are encrypted and never echoed to the browser).
 */
final class Destination {

    /**
     * Stored destination data.
     *
     * @var array<string,mixed>
     */
    private array $data;

    /**
     * Whether this is the primary destination (constants apply to it).
     */
    private bool $is_primary;

    /**
     * @param array<string,mixed> $data       Stored destination array.
     * @param bool                $is_primary Whether constants apply.
     */
    public function __construct(array $data, bool $is_primary = false) {
        $this->data       = $data;
        $this->is_primary = $is_primary;
    }

    /**
     * Destination id.
     */
    public function id(): string {
        return (string) ($this->data['id'] ?? '');
    }

    /**
     * The raw stored array (password stays encrypted).
     *
     * @return array<string,mixed>
     */
    public function raw(): array {
        return $this->data;
    }

    /**
     * Effective value of a connection field (constant override on primary, else
     * stored; secrets decrypted) or a meta field.
     *
     * @param string $key Field key.
     * @return mixed
     */
    public function get(string $key) {
        $fields = Schema::fields();

        if (!isset($fields[$key])) {
            return $this->data[$key] ?? null;
        }

        if ($this->is_from_constant($key)) {
            return constant($fields[$key]['constant']);
        }

        $raw = $this->data[$key] ?? $fields[$key]['default'];

        if (Schema::is_secret($key)) {
            return is_string($raw) && Crypto::is_encrypted($raw) ? Crypto::decrypt($raw) : (string) $raw;
        }

        return $raw;
    }

    /**
     * Whether a connection field is supplied by a wp-config constant.
     *
     * @param string $key Field key.
     */
    public function is_from_constant(string $key): bool {
        $field = Schema::fields()[$key] ?? null;

        return $this->is_primary && $field !== null && $field['constant'] !== '' && defined($field['constant']);
    }

    /**
     * Connection config for the transport layer (decrypted password included).
     *
     * @return array<string,mixed>
     */
    public function connection_config(): array {
        return [
            'transport'       => (string) $this->get('transport'),
            'host'            => (string) $this->get('host'),
            'port'            => (int) $this->get('port'),
            'username'        => (string) $this->get('username'),
            'password'        => (string) $this->get('password'),
            'remotePath'      => (string) $this->get('remotePath'),
            // Connector transport: the public URL the receiver lives under + its pairing.
            'url'             => (string) $this->get('destinationUrl'),
            'connectorId'     => (string) $this->get('connectorId'),
            'connectorSecret' => (string) $this->get('connectorSecret'),
            'connectorVersion'=> (int) $this->get('connectorVersion'),
            // This server (folder) transport.
            'folderPath'      => (string) $this->get('folderPath'),
            'destId'          => $this->id(),
            // Cloudflare transport.
            'cfAccountId'     => (string) $this->get('cfAccountId'),
            'cfWorker'        => (string) $this->get('cfWorker'),
            'cfApiToken'      => (string) $this->get('cfApiToken'),
            // GitHub Pages transport.
            'ghOwner'         => (string) $this->get('ghOwner'),
            'ghRepo'          => (string) $this->get('ghRepo'),
            'ghBranch'        => (string) $this->get('ghBranch'),
            'ghFolder'        => (string) $this->get('ghFolder'),
            'ghToken'         => (string) $this->get('ghToken'),
        ];
    }

    /**
     * Whether this destination deploys through the Connector (HTTPS) receiver.
     */
    public function is_connector(): bool {
        return (string) $this->get('transport') === 'connector';
    }

    /**
     * Whether this destination publishes to Cloudflare (Workers static assets).
     */
    public function is_cloudflare(): bool {
        return (string) $this->get('transport') === 'cloudflare';
    }

    /**
     * Where this destination serves the site from, as a path ("/" = domain
     * root). Taken from the Destination URL when one is set — the URL already
     * says it (https://owner.github.io/repo/ → "/repo/"), so asking again
     * only invites a mismatch. The "Served from sub-path" field applies only
     * when there is no Destination URL, or when SHIPSTATIC_BASE_PATH pins it.
     */
    public function served_base_path(): string {
        $url = trim((string) $this->get('destinationUrl'));
        if (!$this->is_from_constant('basePath') && $url !== '' && (string) wp_parse_url($url, PHP_URL_HOST) !== '') {
            $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
            return $path === '' ? '/' : '/' . $path . '/';
        }

        return (string) $this->get('basePath');
    }

    /**
     * Whether this is the primary (first) destination — the one whose
     * "Served from sub-path" links are rendered for.
     */
    public function is_primary(): bool {
        return $this->is_primary;
    }

    /**
     * Whether this destination publishes into a folder on this server.
     */
    public function is_folder(): bool {
        return (string) $this->get('transport') === 'folder';
    }

    /**
     * Record the folder this destination publishes into (validated by
     * Deploy\ServerFolder first). The only writer for `folderPath`.
     *
     * @param string $path Folder's real path.
     * @return array<string,mixed> Updated stored data.
     */
    public function set_folder(string $path): array {
        $this->data['folderPath'] = $path;

        return $this->data;
    }

    /**
     * Whether this destination publishes to GitHub Pages (commits to a branch).
     */
    public function is_github(): bool {
        return (string) $this->get('transport') === 'github';
    }

    /**
     * Whether this destination publishes whole snapshots (see
     * `Transport\SnapshotCapableTransport`) rather than uploading a delta.
     */
    public function is_snapshot(): bool {
        return \WPEasy\ShipStatic\Transport\TransportFactory::is_snapshot((string) $this->get('transport'));
    }

    /**
     * Record a Connector pairing (id + secret, encrypted at rest) or clear it.
     * The only writer for the internal connector fields.
     *
     * @param string $id      Connector id ('' clears the pairing).
     * @param string $secret  Raw secret ('' clears).
     * @param int    $version Protocol version the file was generated for.
     * @return array<string,mixed> Updated stored data.
     */
    public function set_connector(string $id, string $secret, int $version): array {
        $this->data['connectorId']      = $id;
        $this->data['connectorSecret']  = $secret === '' ? '' : Crypto::encrypt($secret);
        $this->data['connectorVersion'] = $id === '' ? 0 : $version;

        return $this->data;
    }

    /**
     * Per-destination text replacements [{search, replace}, …].
     *
     * @return array<int,array{search:string,replace:string}>
     */
    public function replacements(): array {
        $out = [];
        foreach ((array) ($this->data['replacements'] ?? []) as $row) {
            if (is_array($row) && isset($row['search']) && $row['search'] !== '') {
                $out[] = [
                    'search'  => (string) $row['search'],
                    'replace' => (string) ($row['replace'] ?? ''),
                    'rich'    => (bool) ($row['rich'] ?? false),
                ];
            }
        }

        return $out;
    }

    /**
     * Per-destination, PER-PAGE media swaps [{page, from, to, toId}, …] — replace
     * `from` (original media URL) with `to` (a WordPress media-library URL) ONLY on
     * the page whose export-relative path is `page` (e.g. 'about/index.html').
     *
     * @return array<int,array{page:string,from:string,to:string,toId:int}>
     */
    public function media_replacements(): array {
        $out = [];
        foreach ((array) ($this->data['mediaReplacements'] ?? []) as $row) {
            if (is_array($row) && !empty($row['page']) && !empty($row['from']) && !empty($row['to'])) {
                $out[] = [
                    'page' => (string) $row['page'],
                    'from' => (string) $row['from'],
                    'to'   => (string) $row['to'],
                    // Attachment id of the replacement, so its size variants
                    // (srcset) can be rebuilt at deploy time. 0 if unknown.
                    'toId' => (int) ($row['toId'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /**
     * Per-destination link swaps [{from, to}, …] — original link target → its
     * replacement URL (applied to <a>/<button> href).
     *
     * @return array<int,array{from:string,to:string}>
     */
    public function link_replacements(): array {
        $out = [];
        foreach ((array) ($this->data['linkReplacements'] ?? []) as $row) {
            if (is_array($row) && !empty($row['from']) && !empty($row['to'])) {
                $out[] = ['from' => (string) $row['from'], 'to' => (string) $row['to']];
            }
        }

        return $out;
    }

    /**
     * Per-destination, PER-PAGE video swaps [{page, from, to, toId}, …] — original
     * embed/video src → its replacement, applied only on the page `page`. `toId` is
     * the attachment id for a local-video swap (so its file can be uploaded); 0 for
     * an external embed URL.
     *
     * @return array<int,array{page:string,from:string,to:string,toId:int}>
     */
    public function video_replacements(): array {
        $out = [];
        foreach ((array) ($this->data['videoReplacements'] ?? []) as $row) {
            if (is_array($row) && !empty($row['page']) && !empty($row['from']) && !empty($row['to'])) {
                $out[] = [
                    'page' => (string) $row['page'],
                    'from' => (string) $row['from'],
                    'to'   => (string) $row['to'],
                    'toId' => (int) ($row['toId'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /**
     * Display payload (no secrets) for the dashboard.
     *
     * @return array<string,mixed>
     */
    public function for_display(): array {
        $out = [
            'id'                      => $this->id(),
            'name'                    => (string) ($this->data['name'] ?? ''),
            'enabled'                 => (bool) ($this->data['enabled'] ?? true),
            'isPrimary'               => $this->is_primary,
            'replacements'            => $this->replacements(),
            'mediaReplacements'       => $this->media_replacements(),
            'linkReplacements'        => $this->link_replacements(),
            'videoReplacements'       => $this->video_replacements(),
        ];

        foreach (Schema::fields() as $key => $field) {
            if (Schema::is_secret($key)) {
                $out[$key] = ['hasValue' => $this->get($key) !== '', 'fromConstant' => $this->is_from_constant($key)];
                continue;
            }
            $out[$key] = ['value' => $this->get($key), 'fromConstant' => $this->is_from_constant($key)];
        }

        return $out;
    }

    /**
     * Apply a settings payload, returning the updated stored array.
     *
     * @param array<string,mixed> $input Raw input.
     * @return array<string,mixed> Updated stored data.
     */
    public function apply(array $input): array {
        // Meta.
        if (array_key_exists('name', $input)) {
            $this->data['name'] = sanitize_text_field((string) $input['name']);
        }
        if (array_key_exists('enabled', $input)) {
            $this->data['enabled'] = (bool) $input['enabled'];
        }
        if (array_key_exists('replacements', $input) && is_array($input['replacements'])) {
            $this->data['replacements'] = self::sanitize_replacements($input['replacements']);
        }
        if (array_key_exists('mediaReplacements', $input) && is_array($input['mediaReplacements'])) {
            $this->data['mediaReplacements'] = self::sanitize_page_replacements(
                $input['mediaReplacements'],
                PHP_INT_MAX
            );
        }
        if (array_key_exists('linkReplacements', $input) && is_array($input['linkReplacements'])) {
            $this->data['linkReplacements'] = self::sanitize_link_replacements($input['linkReplacements']);
        }
        if (array_key_exists('videoReplacements', $input) && is_array($input['videoReplacements'])) {
            // Same per-page shape as media — no per-page cap.
            $this->data['videoReplacements'] = self::sanitize_page_replacements(
                $input['videoReplacements'],
                PHP_INT_MAX
            );
        }

        // Connection fields.
        foreach (Schema::fields() as $key => $field) {
            if ($this->is_from_constant($key) || Schema::is_internal($key)) {
                continue; // Constants win; internal (Connector) fields have their own writer.
            }

            if (Schema::is_secret($key)) {
                if (!empty($input['clearPassword'])) {
                    $this->data[$key] = '';
                } elseif (isset($input[$key]) && (string) $input[$key] !== '') {
                    $this->data[$key] = Crypto::encrypt((string) $input[$key]);
                }
                continue;
            }

            if (array_key_exists($key, $input)) {
                $this->data[$key] = Schema::sanitize($key, $input[$key]);
            }
        }

        return $this->data;
    }

    /**
     * Sanitise a replacements list (literal search/replace; empty search dropped).
     *
     * @param array<int,mixed> $rows Raw rows.
     * @return array<int,array{search:string,replace:string}>
     */
    private static function sanitize_replacements(array $rows): array {
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $search = sanitize_text_field((string) ($row['search'] ?? ''));
            if ($search === '') {
                continue;
            }
            // Rich replacements may carry safe inline HTML; plain ones are text.
            $rich    = !empty($row['rich']);
            $replace = (string) ($row['replace'] ?? '');
            $replace = $rich ? wp_kses_post($replace) : sanitize_text_field($replace);
            $clean[] = ['search' => $search, 'replace' => $replace, 'rich' => $rich];
        }

        return $clean;
    }

    /**
     * Sanitise a PER-PAGE replacement list ([{page, from, to, toId}, …]; both sides
     * are URLs, empties dropped) and enforce a per-page cap. Shared by media and
     * video; the cap is what makes media a Free-with-limit feature.
     *
     * @param array<int,mixed> $rows        Raw rows.
     * @param int              $cap_per_page Max entries allowed per page (PHP_INT_MAX = unlimited).
     * @return array<int,array{page:string,from:string,to:string,toId:int}>
     */
    private static function sanitize_page_replacements(array $rows, int $cap_per_page): array {
        $clean    = [];
        $per_page = []; // page => count kept, for the cap
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $page = sanitize_text_field((string) ($row['page'] ?? ''));
            $from = esc_url_raw((string) ($row['from'] ?? ''));
            $to   = esc_url_raw((string) ($row['to'] ?? ''));
            if ($page === '' || $from === '' || $to === '') {
                continue;
            }
            if (($per_page[$page] ?? 0) >= $cap_per_page) {
                continue; // Over the per-page cap (Free tier) — drop the extra.
            }
            $per_page[$page] = ($per_page[$page] ?? 0) + 1;
            $clean[] = ['page' => $page, 'from' => $from, 'to' => $to, 'toId' => absint($row['toId'] ?? 0)];
        }

        return $clean;
    }

    /**
     * Sanitise a link-replacement list (both sides are URLs; empties dropped).
     *
     * @param array<int,mixed> $rows Raw rows.
     * @return array<int,array{from:string,to:string}>
     */
    private static function sanitize_link_replacements(array $rows): array {
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $from = esc_url_raw((string) ($row['from'] ?? ''));
            $to   = esc_url_raw((string) ($row['to'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }
            $clean[] = ['from' => $from, 'to' => $to];
        }

        return $clean;
    }
}
