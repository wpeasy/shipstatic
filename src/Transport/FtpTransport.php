<?php
/**
 * FTP transport (ext-ftp).
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Transport;

use WPEasy\ShipStatic\Support\Url;
use WPEasy\ShipStatic\Support\UrlSafety;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text for JSON responses (rendered as text by the dashboard) and WP-CLI, never echoed into HTML; esc_html() here would show entities to the user.

/**
 * FTP / FTPS destination using PHP's ext-ftp.
 *
 * In secure mode it uses explicit FTPS (FTP over TLS via ftp_ssl_connect()).
 * Implicit FTPS (port 990) is not supported by ext-ftp. Availability is
 * runtime-detected; the dashboard disables options the server can't provide.
 * Plain FTP is unencrypted — prefer FTPS or SFTP where the host supports them.
 */
final class FtpTransport implements TransportInterface {

    /**
     * Default FTP/FTPS (explicit) control port.
     */
    private const DEFAULT_PORT = 21;

    /**
     * Connection config: host, port, username, password, remotePath.
     *
     * @var array<string,mixed>
     */
    private array $config;

    /**
     * Whether to use explicit FTPS (TLS).
     */
    private bool $secure;

    /**
     * Active FTP connection resource/object.
     *
     * @var \FTP\Connection|resource|null
     */
    private $conn = null;

    /**
     * Remote directories already created/verified this session, so we don't spend
     * an ftp_chdir round trip per file (the connection is reused across the whole
     * sync). Keyed by absolute remote dir.
     *
     * @var array<string,true>
     */
    private array $ensured_dirs = [];

    /**
     * @param array<string,mixed> $config Connection config.
     * @param bool                $secure Use explicit FTPS (TLS) instead of plain FTP.
     */
    public function __construct(array $config, bool $secure = false) {
        $this->config = $config;
        $this->secure = $secure;
    }

    /**
     * Whether plain FTP is available.
     */
    public static function is_available(): bool {
        return function_exists('ftp_connect');
    }

    /**
     * Whether explicit FTPS (FTP over TLS) is available.
     */
    public static function is_secure_available(): bool {
        return function_exists('ftp_ssl_connect');
    }

    /**
     * @inheritDoc
     */
    public function connect(): void {
        $label = $this->secure ? 'FTPS' : 'FTP';

        if ($this->secure && !self::is_secure_available()) {
            throw new \RuntimeException('FTPS (FTP over TLS) is not available on this server.');
        }
        if (!$this->secure && !self::is_available()) {
            throw new \RuntimeException('FTP support (ext-ftp) is not available on this server.');
        }

        $host = (string) ($this->config['host'] ?? '');
        if ($host === '') {
            throw new \RuntimeException(sprintf('%s host is required.', $label));
        }

        $blocked = UrlSafety::multisite_host_error($host);
        if ($blocked !== null) {
            throw new \RuntimeException($blocked);
        }

        $port = (int) ($this->config['port'] ?? 0) ?: self::DEFAULT_PORT;
        $conn = $this->secure
            ? @ftp_ssl_connect($host, $port, 15)
            : @ftp_connect($host, $port, 15);

        if ($conn === false) {
            throw new \RuntimeException(sprintf('Could not establish a %s connection to %s:%d.', $label, $host, $port));
        }

        if (!@ftp_login($conn, (string) ($this->config['username'] ?? ''), (string) ($this->config['password'] ?? ''))) {
            ftp_close($conn);
            throw new \RuntimeException(sprintf('%s authentication failed. Check username and password.', $label));
        }

        ftp_pasv($conn, true);
        $this->conn = $conn;
    }

    /**
     * @inheritDoc
     */
    public function disconnect(): void {
        if ($this->conn !== null) {
            @ftp_close($this->conn);
            $this->conn = null;
        }
        $this->ensured_dirs = [];
    }

    /**
     * @inheritDoc
     */
    public function put(string $local_path, string $remote_path): void {
        $conn   = $this->require_connection();
        $target = $this->absolute($remote_path);

        $this->ensure_dir(dirname($target));

        if (!@ftp_put($conn, $target, $local_path, FTP_BINARY)) {
            throw new \RuntimeException(sprintf('FTP upload failed for %s.', $remote_path));
        }
    }

    /**
     * @inheritDoc
     */
    public function mkdir(string $remote_path): void {
        $this->require_connection();
        $this->ensure_dir($this->absolute($remote_path));
    }

    /**
     * @inheritDoc
     */
    public function exists(string $remote_path): bool {
        $conn = $this->require_connection();

        return @ftp_size($conn, $this->absolute($remote_path)) !== -1;
    }

    /**
     * @inheritDoc
     */
    public function rename(string $from, string $to): bool {
        return @ftp_rename($this->require_connection(), $this->absolute($from), $this->absolute($to));
    }

    /**
     * @inheritDoc
     */
    public function get(string $remote_path): string {
        $conn = $this->require_connection();
        // get_temp_dir()/tempnam are available in every context; wp_tempnam() lives
        // in wp-admin/includes/file.php, which isn't loaded during a CLI/loopback sync.
        $tmp = tempnam(get_temp_dir(), 'bs-ftp-');
        if ($tmp === false) {
            return '';
        }

        if (!@ftp_get($conn, $tmp, $this->absolute($remote_path), FTP_BINARY)) {
            wp_delete_file($tmp);
            return '';
        }

        $data = (string) file_get_contents($tmp);
        wp_delete_file($tmp);

        return $data;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $remote_path): bool {
        return @ftp_delete($this->require_connection(), $this->absolute($remote_path));
    }

    /**
     * Recursively create a remote directory tree.
     *
     * @param string $dir Absolute remote directory.
     */
    private function ensure_dir(string $dir): void {
        $conn = $this->require_connection();
        $dir  = rtrim($dir, '/');
        if ($dir === '' || isset($this->ensured_dirs[$dir])) {
            return;
        }

        // Fast path: the directory already exists (one round trip, then cached).
        if (@ftp_chdir($conn, $dir)) {
            $this->ensured_dirs[$dir] = true;
            return;
        }

        $parts = explode('/', ltrim($dir, '/'));
        $path  = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $path .= '/' . $part;
            if (isset($this->ensured_dirs[$path])) {
                continue;
            }
            if (!@ftp_chdir($conn, $path)) {
                @ftp_mkdir($conn, $path);
            }
            $this->ensured_dirs[$path] = true;
        }
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
     *
     * @return \FTP\Connection|resource
     */
    private function require_connection() {
        if ($this->conn === null) {
            throw new \RuntimeException('FTP is not connected.');
        }

        return $this->conn;
    }
}
