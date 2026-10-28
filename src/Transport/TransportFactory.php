<?php
/**
 * Transport factory + capability detection.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Transport;

use WPEasy\ShipStatic\Settings\Settings;

defined('ABSPATH') || exit;

/**
 * Builds a transport from a config array and reports which transports the
 * current runtime can use.
 */
final class TransportFactory {

    /**
     * Build a transport for the given config (defaults to the saved settings).
     *
     * @param array<string,mixed>|null $config Connection config, or null to use saved settings.
     */
    public static function make(?array $config = null): TransportInterface {
        $config = $config ?? Settings::connection_config();

        /**
         * Filters the transport instance, allowing a custom implementation
         * (e.g. a local filesystem transport for testing).
         *
         * @param TransportInterface|null $transport Override, or null for default.
         * @param array<string,mixed>     $config    Connection config.
         */
        $override = apply_filters('shipstatic_transport', null, $config);
        if ($override instanceof TransportInterface) {
            return $override;
        }

        switch ($config['transport'] ?? 'sftp') {
            case 'cloudflare':
                return new CloudflareTransport($config);
            case 'github':
                return new GitHubTransport($config);
            case 'connector':
                return new ConnectorTransport($config);
            case 'folder':
                return new FolderTransport($config);
            case 'ftps':
                return new FtpTransport($config, true);
            case 'ftp':
                return new FtpTransport($config, false);
            case 'sftp':
            default:
                return new SftpTransport($config);
        }
    }

    /**
     * Which transports are usable on this server.
     *
     * @return array{sftp:bool,ftps:bool,ftp:bool,connector:bool,cloudflare:bool,github:bool,folder:bool,multisite:bool}
     */
    public static function capabilities(): array {
        return [
            'sftp'       => SftpTransport::is_available(),
            'ftps'       => FtpTransport::is_secure_available(),
            'ftp'        => FtpTransport::is_available(),
            'connector'  => ConnectorTransport::is_available(),
            'cloudflare' => CloudflareTransport::is_available(),
            'github'     => GitHubTransport::is_available(),
            'folder'     => FolderTransport::is_available(),
            // Why "This server" is off: the panel says "Disabled on Multisite".
            'multisite'  => \WPEasy\ShipStatic\Support\Environment::is_multisite(),
        ];
    }

    /**
     * Whether a transport id connects to an FTP/SFTP host (and so needs the
     * host/port/username/password fields). The Connector, Cloudflare and
     * GitHub Pages are reached over HTTPS without one.
     *
     * @param string $transport Transport id.
     */
    public static function needs_host(string $transport): bool {
        return in_array($transport, ['sftp', 'ftps', 'ftp'], true);
    }

    /**
     * Whether a destination's upload root IS the path it serves the site from
     * — a GitHub project site's repository root is https://owner.github.io/repo/
     * — so its files are not moved into a sub-path folder at deploy. So is a
     * folder on this server: the folder IS what the Destination URL serves.
     * Every other transport uploads to a web root that the sub-path sits under.
     *
     * @param string $transport Transport id.
     */
    public static function root_is_served_path(string $transport): bool {
        return in_array($transport, ['github', 'folder'], true);
    }

    /**
     * Whether a transport id publishes whole snapshots (a
     * {@see SnapshotCapableTransport}) instead of uploading a delta to a
     * remote filesystem. Decided from the id alone so callers don't have to
     * build — or connect — a transport to ask.
     *
     * @param string $transport Transport id.
     */
    public static function is_snapshot(string $transport): bool {
        return in_array($transport, ['cloudflare', 'github'], true);
    }
}
