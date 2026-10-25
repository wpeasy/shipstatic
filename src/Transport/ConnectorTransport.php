<?php
/**
 * Connector (HTTPS) transport.
 *
 * @package WPEasy\ShipStatic
 * @since   2.1.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Transport;

use WPEasy\ShipStatic\Deploy\ConnectorAuthException;
use WPEasy\ShipStatic\Deploy\ConnectorClient;
use WPEasy\ShipStatic\Deploy\ConnectorHostException;
use WPEasy\ShipStatic\Deploy\ConnectorVersionException;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text for JSON responses (rendered as text by the dashboard) and WP-CLI, never echoed into HTML; esc_html() here would show entities to the user.

/**
 * Deploys through a user-installed receiver script on the destination (see
 * `Deploy\ConnectorScript`) instead of an FTP/SFTP account. Implements the
 * per-file `TransportInterface` so every existing Runner path (holding page,
 * .htaccess merge, per-file upload, prune) works unchanged, plus
 * `PackageCapableTransport` so a full deploy goes up as one chunked zip.
 *
 * "Connected" here means the receiver answered `ping` with our credentials.
 */
final class ConnectorTransport implements TransportInterface, PackageCapableTransport {

    private ConnectorClient $client;

    /**
     * Last `ping` result (capabilities of the far end).
     *
     * @var array<string,mixed>
     */
    private array $info = [];

    /** Destination URL (keys the remembered chunk size). */
    private string $url = '';

    /**
     * @param array<string,mixed> $config Destination connection config.
     */
    public function __construct(array $config) {
        $this->url    = (string) ($config['url'] ?? '');
        $this->client = new ConnectorClient(
            (string) ($config['url'] ?? ''),
            (string) ($config['connectorId'] ?? ''),
            (string) ($config['connectorSecret'] ?? '')
        );
    }

    /**
     * Always usable — needs nothing beyond the WP HTTP API.
     */
    public static function is_available(): bool {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function connect(): void {
        if (!$this->client->configured()) {
            throw new ConnectorAuthException('This destination is not paired with a connector yet — pair it and upload the connector file first.');
        }
        try {
            $this->info = $this->client->ping();
        } catch (ConnectorAuthException | ConnectorVersionException | ConnectorHostException $e) {
            throw $e; // Already user-facing.
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if (empty($this->info['writable'])) {
            throw new \RuntimeException('The connector answered, but its folder on the destination is not writable by PHP.');
        }
    }

    /**
     * @inheritdoc
     */
    public function disconnect(): void {
        // Stateless — nothing to close.
    }

    /**
     * Receiver environment from the last `connect()` (empty before it).
     *
     * @return array<string,mixed>
     */
    public function info(): array {
        return $this->info;
    }

    /**
     * Human summary of the receiver for the connection Test.
     */
    public function describe(): string {
        $i = $this->info;
        if ($i === []) {
            return '';
        }
        $bits = ['PHP ' . (string) ($i['php'] ?? '?')];
        $bits[] = !empty($i['zip']) ? 'package deploy available' : 'no ZipArchive (file-by-file)';
        if (!empty($i['free'])) {
            $bits[] = size_format((int) $i['free']) . ' free';
        }
        if (stripos((string) ($i['server'] ?? ''), 'nginx') !== false) {
            // nginx has limits PHP can't see (request size, PHP timeout).
            $bits[] = 'nginx — check its minimum setup under "Using nginx?"';
        }

        return implode(', ', $bits);
    }

    /**
     * Remote inventory (see ConnectorClient::manifest()).
     *
     * @return array<string,array{size:int,md5:string}>
     */
    public function manifest(): array {
        return $this->client->manifest();
    }

    /**
     * @inheritdoc
     */
    public function put(string $local_path, string $remote_path): void {
        $this->client->upload($local_path, ltrim($remote_path, '/'), $this->chunk_size());
        $this->remember_chunk();
    }

    /**
     * @inheritdoc
     */
    public function mkdir(string $remote_path): void {
        // The receiver creates parents on write.
    }

    /**
     * @inheritdoc
     */
    public function exists(string $remote_path): bool {
        $rel = ltrim($remote_path, '/');
        $map = $this->client->stat([$rel]);

        return !empty($map[$rel]);
    }

    /**
     * @inheritdoc — unsupported by the receiver (nothing in Runner needs it).
     */
    public function rename(string $from, string $to): bool {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function get(string $remote_path): string {
        return $this->client->get(ltrim($remote_path, '/'));
    }

    /**
     * @inheritdoc
     */
    public function delete(string $remote_path): bool {
        return $this->client->delete([ltrim($remote_path, '/')]) > 0;
    }

    /**
     * @inheritdoc
     */
    public function deploy_package(string $zip_path, array $deletes, ?callable $progress = null): array {
        $report = static function (string $stage) use ($progress): void {
            if ($progress !== null) {
                $progress($stage);
            }
        };
        $fail = static fn(string $msg, bool $retryable): array => [
            'ok' => false, 'retryable' => $retryable, 'extracted' => 0, 'deleted' => 0, 'errors' => [], 'message' => $msg,
        ];

        if ($this->info === []) {
            $this->connect();
        }
        if (empty($this->info['zip'])) {
            // Definitive: this receiver can't extract, so fall back to per-file
            // for good (until the pairing/URL changes and resets the flag).
            return $fail('The connector\'s host has no ZipArchive, so packages can\'t be extracted there.', false);
        }

        $size = (int) @filesize($zip_path);
        try {
            $report(sprintf('Uploading package (%s) to the connector…', size_format($size)));
            $upload = $this->client->upload($zip_path, null, $this->chunk_size(), static function (int $sent, int $total) use ($report): void {
                if ($total > 0 && $sent < $total) {
                    $report(sprintf('Uploading package… %d%%', (int) floor($sent / $total * 100)));
                }
            });
            $this->remember_chunk();

            $report('Extracting on the destination…');
            $r = $this->client->extract($upload, $deletes, max(10, (int) apply_filters('shipstatic_package_timeout', 120)));
        } catch (ConnectorAuthException | ConnectorVersionException | ConnectorHostException $e) {
            // Nothing transient about a bad pairing / missing receiver — but the
            // per-file fallback would fail the same way, so keep package enabled
            // and let the run error out with the real message.
            return $fail($e->getMessage(), true);
        } catch (\Throwable $e) {
            return $fail($e->getMessage(), true);
        }

        return [
            'ok'        => true,
            'retryable' => true,
            'extracted' => $r['extracted'],
            'deleted'   => $r['deleted'],
            'errors'    => $r['errors'],
            'message'   => 'Package deployed via connector.',
        ];
    }

    /**
     * Chunk size: under nginx's default 1 MB request limit (PHP can't see it,
     * so the receiver can't report it), under the receiver's PHP upload limit,
     * and no larger than a size that needed shrinking here before.
     */
    private function chunk_size(): int {
        /**
         * Filters the largest Connector upload chunk (bytes). Raise it for
         * fewer round trips on a host that accepts bigger requests (nginx:
         * client_max_body_size); the PHP upload limit still applies.
         *
         * @param int    $bytes Default ConnectorClient::SAFE_CHUNK (900 KiB).
         * @param string $url   Destination URL.
         */
        $chunk = (int) apply_filters('shipstatic_connector_chunk', ConnectorClient::SAFE_CHUNK, $this->url);

        $max = (int) ($this->info['uploadMax'] ?? 0);
        if ($max > 0) {
            // Leave headroom for the multipart envelope + meta field.
            $chunk = min($chunk, (int) floor($max * 0.9));
        }
        $remembered = (int) get_transient($this->chunk_key());
        if ($remembered > 0) {
            $chunk = min($chunk, $remembered);
        }

        return (int) max(ConnectorClient::MIN_CHUNK, min(ConnectorClient::MAX_CHUNK, $chunk));
    }

    /**
     * Remember a chunk size that had to shrink, so the next upload starts there.
     */
    private function remember_chunk(): void {
        $used = $this->client->last_chunk();
        if ($used > 0 && $used < $this->chunk_size()) {
            set_transient($this->chunk_key(), $used, MONTH_IN_SECONDS);
        }
    }

    /**
     * Transient key for this destination's remembered chunk size.
     */
    private function chunk_key(): string {
        return 'shipstatic_conn_chunk_' . md5($this->url);
    }
}
