<?php
/**
 * SFTP transport (phpseclib).
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Transport;

use phpseclib3\Net\SFTP;
use WPEasy\ShipStatic\Support\Url;
use WPEasy\ShipStatic\Support\UrlSafety;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text for JSON responses (rendered as text by the dashboard) and WP-CLI, never echoed into HTML; esc_html() here would show entities to the user.

/**
 * SFTP destination using the bundled phpseclib library (no ext-ssh2 required).
 */
final class SftpTransport implements TransportInterface {

    /**
     * Default SFTP port.
     */
    private const DEFAULT_PORT = 22;

    /**
     * Option holding trusted server host keys, keyed by "host:port".
     */
    private const KNOWN_HOSTS_OPTION = 'shipstatic_sftp_known_hosts';

    /**
     * Connection config: host, port, username, password, remotePath.
     *
     * @var array<string,mixed>
     */
    private array $config;

    /**
     * Active connection.
     *
     * @var SFTP|null
     */
    private ?SFTP $sftp = null;

    /**
     * @param array<string,mixed> $config Connection config.
     */
    public function __construct(array $config) {
        $this->config = $config;
    }

    /**
     * Whether phpseclib is available.
     */
    public static function is_available(): bool {
        return class_exists(SFTP::class);
    }

    /**
     * @inheritDoc
     */
    public function connect(): void {
        if (!self::is_available()) {
            throw new \RuntimeException('SFTP support (phpseclib) is not available.');
        }

        $host = (string) ($this->config['host'] ?? '');
        if ($host === '') {
            throw new \RuntimeException('SFTP host is required.');
        }

        $blocked = UrlSafety::multisite_host_error($host);
        if ($blocked !== null) {
            throw new \RuntimeException($blocked);
        }

        $port = (int) ($this->config['port'] ?? 0) ?: self::DEFAULT_PORT;
        $sftp = new SFTP($host, $port, 15);

        // Verify the server identity BEFORE sending credentials, so a MITM that
        // impersonates the destination can't capture the password.
        self::verify_host_key($sftp, $host, $port);

        if (!$sftp->login((string) ($this->config['username'] ?? ''), (string) ($this->config['password'] ?? ''))) {
            throw new \RuntimeException('SFTP authentication failed. Check host, port, username and password.');
        }

        $this->sftp = $sftp;
    }

    /**
     * Trust-on-first-use host-key check: record the server's public host key on
     * the first connection to a host:port, and refuse to continue if it later
     * changes (a man-in-the-middle would present a different key). Reading the key
     * triggers the SSH key exchange but happens before login(), so credentials are
     * never sent to an unverified or changed host.
     *
     * @param SFTP   $sftp Unauthenticated SFTP connection.
     * @param string $host Host.
     * @param int    $port Port.
     * @throws \RuntimeException On a host-key mismatch or unreadable key.
     */
    private static function verify_host_key(SFTP $sftp, string $host, int $port): void {
        /** Filters whether SFTP host-key verification is enforced. */
        if (!apply_filters('shipstatic_sftp_verify_host_key', true, $host, $port)) {
            return;
        }

        $key = $sftp->getServerPublicHostKey();
        if (!is_string($key) || $key === '') {
            throw new \RuntimeException('Could not read the SFTP server host key to verify the server identity.');
        }

        $id     = strtolower($host) . ':' . $port;
        $known  = get_option(self::KNOWN_HOSTS_OPTION, []);
        $known  = is_array($known) ? $known : [];
        $stored = isset($known[$id]) ? (string) $known[$id] : '';

        if ($stored === '') {
            $known[$id] = $key; // Trust on first use.
            update_option(self::KNOWN_HOSTS_OPTION, $known, false);
            return;
        }

        if (!hash_equals($stored, $key)) {
            throw new \RuntimeException(sprintf(
                'SFTP host key for %s has changed since the first connection. If the server was legitimately rebuilt or migrated, use "Reset sync state" to clear the stored key; otherwise this may be a man-in-the-middle attempt and the connection was refused.',
                $id
            ));
        }
    }

    /**
     * Forget the stored host key for a host:port (e.g. when the destination is
     * re-pointed, or on reset), so the next connection re-establishes trust.
     *
     * @param string $host Host ('' clears all).
     * @param int    $port Port.
     */
    public static function forget_host_key(string $host = '', int $port = 0): void {
        if ($host === '') {
            delete_option(self::KNOWN_HOSTS_OPTION);
            return;
        }

        $known = get_option(self::KNOWN_HOSTS_OPTION, []);
        if (!is_array($known)) {
            return;
        }
        $id = strtolower($host) . ':' . ($port ?: self::DEFAULT_PORT);
        if (isset($known[$id])) {
            unset($known[$id]);
            update_option(self::KNOWN_HOSTS_OPTION, $known, false);
        }
    }

    /**
     * @inheritDoc
     */
    public function disconnect(): void {
        if ($this->sftp !== null) {
            $this->sftp->disconnect();
            $this->sftp = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function put(string $local_path, string $remote_path): void {
        $sftp   = $this->require_connection();
        $target = $this->absolute($remote_path);

        $dir = dirname($target);
        if ($dir !== '' && $dir !== '.' && !$sftp->is_dir($dir)) {
            $sftp->mkdir($dir, -1, true);
        }

        if (!$sftp->put($target, $local_path, SFTP::SOURCE_LOCAL_FILE)) {
            throw new \RuntimeException(sprintf('SFTP upload failed for %s.', $remote_path));
        }
    }

    /**
     * @inheritDoc
     */
    public function mkdir(string $remote_path): void {
        $sftp = $this->require_connection();
        $dir  = $this->absolute($remote_path);

        if (!$sftp->is_dir($dir) && !$sftp->mkdir($dir, -1, true)) {
            throw new \RuntimeException(sprintf('SFTP mkdir failed for %s.', $remote_path));
        }
    }

    /**
     * @inheritDoc
     */
    public function exists(string $remote_path): bool {
        return $this->require_connection()->file_exists($this->absolute($remote_path));
    }

    /**
     * @inheritDoc
     */
    public function rename(string $from, string $to): bool {
        return $this->require_connection()->rename($this->absolute($from), $this->absolute($to));
    }

    /**
     * @inheritDoc
     */
    public function get(string $remote_path): string {
        $data = $this->require_connection()->get($this->absolute($remote_path));

        return is_string($data) ? $data : '';
    }

    /**
     * @inheritDoc
     */
    public function delete(string $remote_path): bool {
        return $this->require_connection()->delete($this->absolute($remote_path), false);
    }

    /**
     * Resolve a path relative to the configured remote root.
     *
     * @param string $remote_path Relative remote path.
     */
    private function absolute(string $remote_path): string {
        $root = trim((string) ($this->config['remotePath'] ?? ''), '/');
        $rel  = ltrim($remote_path, '/');

        // Upload and delete keys come from crawled URLs: never let one climb
        // out of the remote root (SECURITY_PATTERNS.md §5).
        if (Url::has_traversal($rel)) {
            throw new \RuntimeException('Refused an unsafe remote path: ' . $rel);
        }

        return ($root === '' ? '' : '/' . $root) . '/' . $rel;
    }

    /**
     * Get the active connection or fail.
     */
    private function require_connection(): SFTP {
        if ($this->sftp === null) {
            throw new \RuntimeException('SFTP is not connected.');
        }

        return $this->sftp;
    }
}
