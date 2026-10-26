<?php
/**
 * Settings field schema.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Settings;

use WPEasy\ShipStatic\Deploy\GitHubClient;

defined('ABSPATH') || exit;

/**
 * Declarative definition of the destination connection settings.
 *
 * One source of truth for: defaults, types/sanitisation, which fields are
 * secret (encrypted, never returned to JS), and which wp-config constant
 * overrides each field at read time.
 */
final class Schema {

    /**
     * Option key holding the settings array.
     */
    public const OPTION = 'shipstatic_settings';

    /**
     * Field definitions keyed by setting name.
     *
     * `internal` fields are managed by the plugin (the Connector pairing) and
     * are never written from REST input — see `Destination::apply()`.
     *
     * @return array<string,array{type:string,default:mixed,constant:string,secret?:bool,internal?:bool,options?:array<int,string>}>
     */
    public static function fields(): array {
        return [
            'transport'      => ['type' => 'enum',   'default' => 'ftps',          'constant' => 'SHIPSTATIC_TRANSPORT',       'options' => ['sftp', 'ftps', 'ftp', 'connector', 'cloudflare', 'github', 'folder']],
            'host'           => ['type' => 'string', 'default' => '',              'constant' => 'SHIPSTATIC_HOST'],
            'port'           => ['type' => 'int',    'default' => 0,               'constant' => 'SHIPSTATIC_PORT'],
            'username'       => ['type' => 'string', 'default' => '',              'constant' => 'SHIPSTATIC_USERNAME'],
            'password'       => ['type' => 'string', 'default' => '',              'constant' => 'SHIPSTATIC_PASSWORD', 'secret' => true],
            'remotePath'     => ['type' => 'path',   'default' => '',              'constant' => 'SHIPSTATIC_REMOTE_PATH'],
            'basePath'       => ['type' => 'path',   'default' => '/',             'constant' => 'SHIPSTATIC_BASE_PATH'],
            'destinationUrl' => ['type' => 'url',    'default' => '',              'constant' => 'SHIPSTATIC_DESTINATION_URL'],
            // Connector (HTTPS) pairing — set by Destinations::pair_connector(), never by REST input.
            'connectorId'     => ['type' => 'string', 'default' => '',             'constant' => 'SHIPSTATIC_CONNECTOR_ID',     'internal' => true],
            'connectorSecret' => ['type' => 'string', 'default' => '',             'constant' => 'SHIPSTATIC_CONNECTOR_SECRET', 'internal' => true, 'secret' => true],
            'connectorVersion'=> ['type' => 'int',    'default' => 0,              'constant' => '',                    'internal' => true],
            // This server (folder) — set by Destinations::set_folder() after
            // Deploy\ServerFolder validated it, never by REST input (it says where
            // on this server's disk Sync writes). See docs/SERVER_FOLDER_PLAN.md.
            'folderPath'     => ['type' => 'string', 'default' => '',              'constant' => '',                    'internal' => true],
            // Cloudflare (Workers static assets) — see docs/CLOUDFLARE_PLAN.md.
            'cfAccountId'    => ['type' => 'cf_account', 'default' => '',          'constant' => 'SHIPSTATIC_CF_ACCOUNT_ID'],
            'cfWorker'       => ['type' => 'cf_worker',  'default' => '',          'constant' => 'SHIPSTATIC_CF_WORKER'],
            'cfApiToken'     => ['type' => 'string',     'default' => '',          'constant' => 'SHIPSTATIC_CF_API_TOKEN', 'secret' => true],
            // GitHub Pages (commits to a branch) — see docs/GITHUB_PAGES_PLAN.md.
            'ghOwner'        => ['type' => 'gh_owner',   'default' => '',          'constant' => 'SHIPSTATIC_GH_OWNER'],
            'ghRepo'         => ['type' => 'gh_repo',    'default' => '',          'constant' => 'SHIPSTATIC_GH_REPO'],
            'ghBranch'       => ['type' => 'gh_branch',  'default' => 'gh-pages',  'constant' => 'SHIPSTATIC_GH_BRANCH'],
            'ghFolder'       => ['type' => 'enum',       'default' => '/',         'constant' => 'SHIPSTATIC_GH_FOLDER', 'options' => ['/', '/docs']],
            'ghToken'        => ['type' => 'string',     'default' => '',          'constant' => 'SHIPSTATIC_GH_TOKEN', 'secret' => true],
        ];
    }

    /**
     * Whether a field is plugin-managed (not writable from REST input).
     *
     * @param string $key Field key.
     */
    public static function is_internal(string $key): bool {
        return !empty(self::fields()[$key]['internal']);
    }

    /**
     * Whether a field stores a secret (encrypted; never echoed to JS).
     *
     * @param string $key Field key.
     */
    public static function is_secret(string $key): bool {
        return !empty(self::fields()[$key]['secret']);
    }

    /**
     * Sanitise a raw value according to its field type.
     *
     * @param string $key   Field key.
     * @param mixed  $value Raw value.
     * @return mixed Sanitised value.
     */
    public static function sanitize(string $key, $value) {
        $field = self::fields()[$key] ?? null;
        if ($field === null) {
            return null;
        }

        switch ($field['type']) {
            case 'int':
                return max(0, (int) $value);

            case 'enum':
                $value = is_string($value) ? $value : '';
                return in_array($value, $field['options'] ?? [], true) ? $value : $field['default'];

            case 'url':
                return esc_url_raw(trim((string) $value));

            case 'path':
                return self::sanitize_path((string) $value);

            case 'cf_account':
                // 32 hex chars, or nothing — it is placed in an API path.
                $value = strtolower(trim((string) $value));
                return preg_match('/^[a-f0-9]{32}$/', $value) ? $value : '';

            case 'cf_worker':
                return self::sanitize_worker((string) $value);

            // GitHub owner / repo / branch — strict, because each is placed
            // in an API path. Invalid → empty (owner/repo) or the default
            // branch, so a bad value can never reach a request.
            case 'gh_owner':
                $value = trim((string) $value);
                return GitHubClient::valid_owner($value) ? $value : '';

            case 'gh_repo':
                $value = trim((string) $value);
                $value = (string) preg_replace('/\.git$/i', '', $value);
                return GitHubClient::valid_repo($value) ? $value : '';

            case 'gh_branch':
                $value = trim((string) $value);
                return GitHubClient::valid_branch($value) ? $value : $field['default'];

            case 'string':
            default:
                return sanitize_text_field((string) $value);
        }
    }

    /**
     * Normalise a Cloudflare Worker name to what Cloudflare accepts (and what
     * is safe in an API path): lowercase letters, digits and dashes, no
     * leading/trailing dash, at most 63 characters. "My Site" → "my-site".
     *
     * @param string $value Raw name.
     */
    private static function sanitize_worker(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9-]+/', '-', $value) ?? '';
        $value = trim(substr(trim($value, '-'), 0, 63), '-');

        return $value;
    }

    /**
     * Normalise a remote/base path: trim, strip dangerous traversal, ensure a
     * single trailing slash.
     *
     * @param string $value Raw path.
     */
    private static function sanitize_path(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace('\\', '/', $value);
        $value = preg_replace('#\.\.+/#', '', $value) ?? '';
        $value = preg_replace('#/{2,}#', '/', $value) ?? '';

        return trailingslashit($value);
    }
}
