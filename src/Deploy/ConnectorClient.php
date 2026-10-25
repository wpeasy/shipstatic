<?php
/**
 * HTTP client for the Connector receiver (signed requests).
 *
 * @package WPEasy\ShipStatic
 * @since   2.1.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Deploy;

use WPEasy\ShipStatic\Support\UrlSafety;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text for JSON responses (rendered as text by the dashboard) and WP-CLI, never echoed into HTML; esc_html() here would show entities to the user.

/**
 * Speaks the Connector wire protocol (see docs/CONNECTOR_PLAN.md §4 and the
 * receiver template). Every request is:
 *
 *   - POSTed through `UrlSafety::guarded_post()` (SSRF guard, redirects off);
 *   - authenticated with the raw secret (`X-BS-Key`, the receiver stores only
 *     its hash) AND signed: HMAC-SHA256 over `id \n timestamp \n nonce \n
 *     body-hash`, where body-hash is sha256 of the JSON body, or for multipart
 *     uploads sha256 of `meta \n sha256(file)`;
 *   - replay-protected by the timestamp window + single-use nonce the receiver
 *     enforces.
 *
 * Errors surface as typed exceptions so callers can distinguish "re-download
 * the connector" (auth), "connector is outdated" (version) and "the host isn't
 * running PHP here" (definitive, not retryable) from transient failures.
 */
final class ConnectorClient {

    /**
     * Never send chunks larger than this even if the host allows it.
     */
    public const MAX_CHUNK = 4 * 1024 * 1024;

    /**
     * Default chunk ceiling: under nginx's default 1 MB request limit
     * (client_max_body_size), which PHP can't see or report.
     */
    public const SAFE_CHUNK = 900 * 1024;

    /** Smallest chunk upload() shrinks to after HTTP 413s. */
    public const MIN_CHUNK = 64 * 1024;

    /** The chunk size the last upload() finished with (after any shrinking). */
    private int $last_chunk = 0;

    private string $url;
    private string $id;
    private string $secret;
    private bool $sslverify;

    /**
     * @param string $url    Destination URL (the directory the receiver lives in).
     * @param string $id     Connector id.
     * @param string $secret Raw pairing secret.
     */
    public function __construct(string $url, string $id, string $secret) {
        $this->url       = rtrim(trim($url), '/');
        $this->id        = $id;
        $this->secret    = $secret;
        $this->sslverify = PackageDeployer::sslverify($this->url);
    }

    /**
     * The receiver's full URL.
     */
    public function endpoint(): string {
        return $this->url . '/' . ConnectorScript::filename($this->id);
    }

    /**
     * Whether a Destination URL may carry the pairing secret: HTTPS, or a
     * local/dev host that can't hold a public certificate. Checked at pairing
     * AND before every request, so editing the URL to `http://` after pairing
     * can't make the client send the secret in cleartext.
     *
     * @param string $url Destination URL.
     */
    public static function url_is_secure(string $url): bool {
        $host = (string) (wp_parse_url($url, PHP_URL_HOST) ?? '');

        return $host !== '' && (strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) === 'https' || UrlSafety::is_dev_host($host));
    }

    /**
     * Whether this client has everything it needs to talk to a receiver.
     */
    public function configured(): bool {
        return $this->url !== '' && preg_match('/^[0-9a-f]{16}$/', $this->id) === 1 && $this->secret !== '';
    }

    /**
     * `ping` — capabilities + environment of the receiver.
     *
     * @return array<string,mixed>
     */
    public function ping(): array {
        return $this->json(['op' => 'ping'], 30);
    }

    /**
     * `stat` — which of the given relative paths exist.
     *
     * @param array<int,string> $paths Relative paths.
     * @return array<string,bool>
     */
    public function stat(array $paths): array {
        $r = $this->json(['op' => 'stat', 'paths' => array_values($paths)], 30);

        return is_array($r['exists'] ?? null) ? array_map('boolval', $r['exists']) : [];
    }

    /**
     * `get` — read an allow-listed file ('' when it doesn't exist).
     *
     * @param string $path Relative path (.htaccess or its backup).
     */
    public function get(string $path): string {
        $r = $this->json(['op' => 'get', 'path' => $path], 30);

        return !empty($r['exists']) ? (string) base64_decode((string) ($r['content'] ?? ''), true) : '';
    }

    /**
     * `manifest` — what the receiver's folder currently holds (size + md5 per
     * file; excludes our files, .gz siblings and .htaccess).
     *
     * @return array<string,array{size:int,md5:string}>
     */
    public function manifest(): array {
        $r   = $this->json(['op' => 'manifest'], 120);
        $out = [];
        foreach ((array) ($r['files'] ?? []) as $rel => $meta) {
            if (is_array($meta)) {
                $out[(string) $rel] = ['size' => (int) ($meta['size'] ?? 0), 'md5' => (string) ($meta['md5'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * `delete` — remove files (+ their .gz siblings are handled by extract/prune callers).
     *
     * @param array<int,string> $paths Relative paths.
     * @return int Files deleted.
     */
    public function delete(array $paths): int {
        $r = $this->json(['op' => 'delete', 'paths' => array_values($paths)], 60);

        return (int) ($r['deleted'] ?? 0);
    }

    /**
     * Upload a local file in chunks. With `$target` the receiver moves it into
     * place at that relative path; without, it is kept as a package for
     * `extract()`. Returns the upload id.
     *
     * @param string        $local     Local file path.
     * @param string|null   $target    Relative destination path, or null for a package.
     * @param int           $chunk     Chunk size in bytes.
     * @param callable|null $progress  fn(int $sent, int $total).
     * @throws \RuntimeException On any failure.
     */
    public function upload(string $local, ?string $target, int $chunk, ?callable $progress = null): string {
        // Streamed in chunks: WP_Filesystem has no partial-read API, and reading a
        // multi-MB package whole would defeat chunking.
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        $fh = @fopen($local, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot read ' . basename($local) . ' for upload.');
        }
        // fstat, not filesize(): the stat cache can be stale for a path that was
        // just rewritten (a temp file reused across uploads), which would end
        // the upload early and fail the checksum.
        $stat   = fstat($fh);
        $size   = (int) ($stat['size'] ?? 0);
        $upload = bin2hex(random_bytes(8));
        $sha    = (string) hash_file('sha256', $local);
        $chunk  = max(self::MIN_CHUNK, min($chunk, self::MAX_CHUNK));
        $offset = 0;

        try {
            do {
                fseek($fh, $offset);
                $data  = $size === 0 ? '' : (string) fread($fh, $chunk);
                $final = $offset + strlen($data) >= $size;
                $meta  = ['op' => 'chunk', 'upload' => $upload, 'offset' => $offset];
                if ($final) {
                    $meta['final']  = true;
                    $meta['sha256'] = $sha;
                    if ($target !== null) {
                        $meta['target'] = $target;
                    }
                }
                try {
                    $this->multipart($meta, $data, 120, 2);
                } catch (ConnectorTooLargeException $e) {
                    // The web server in front of PHP refused the size (PHP never
                    // saw it, so nothing was written): resend this piece smaller.
                    if ($chunk <= self::MIN_CHUNK) {
                        throw $e;
                    }
                    $chunk = max(self::MIN_CHUNK, intdiv($chunk, 2));
                    continue;
                }
                $offset += strlen($data);
                if ($progress !== null) {
                    $progress($offset, $size);
                }
            } while (!$final);
        } finally {
            fclose($fh);
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        $this->last_chunk = $chunk;

        return $upload;
    }

    /**
     * The chunk size the last upload() ended with (0 before any upload).
     */
    public function last_chunk(): int {
        return $this->last_chunk;
    }

    /**
     * `extract` — unpack an uploaded package and apply deletes.
     *
     * @param string            $upload  Upload id from `upload()`.
     * @param array<int,string> $deletes Relative paths to remove.
     * @param int               $timeout Seconds.
     * @return array{extracted:int,deleted:int,errors:array<int,string>}
     */
    public function extract(string $upload, array $deletes, int $timeout): array {
        $r = $this->json(['op' => 'extract', 'upload' => $upload, 'deletes' => array_values($deletes)], $timeout);

        return [
            'extracted' => (int) ($r['extracted'] ?? 0),
            'deleted'   => (int) ($r['deleted'] ?? 0),
            'errors'    => is_array($r['errors'] ?? null) ? array_map('strval', $r['errors']) : [],
        ];
    }

    /**
     * Send a JSON op.
     *
     * @param array<string,mixed> $body    Op + params.
     * @param int                 $timeout Seconds.
     * @return array<string,mixed> Decoded response (ok === true).
     */
    private function json(array $body, int $timeout): array {
        $raw = (string) wp_json_encode($body);

        return $this->send($raw, hash('sha256', $raw), ['Content-Type' => 'application/json'], $timeout, 1);
    }

    /**
     * Send a multipart op (`meta` JSON field + `file`).
     *
     * @param array<string,mixed> $meta    Op + params.
     * @param string              $data    File bytes.
     * @param int                 $timeout Seconds.
     * @param int                 $tries   Attempts (chunks are idempotent by offset).
     * @return array<string,mixed>
     */
    private function multipart(array $meta, string $data, int $timeout, int $tries): array {
        $meta_raw = (string) wp_json_encode($meta);
        $boundary = '----bs' . bin2hex(random_bytes(12));
        $body     = '--' . $boundary . "\r\n"
            . "Content-Disposition: form-data; name=\"meta\"\r\n\r\n"
            . $meta_raw . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"chunk\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $data . "\r\n"
            . '--' . $boundary . "--\r\n";
        $hash = hash('sha256', $meta_raw . "\n" . hash('sha256', $data));

        return $this->send($body, $hash, ['Content-Type' => 'multipart/form-data; boundary=' . $boundary], $timeout, $tries);
    }

    /**
     * Sign + POST, decode, map errors to exceptions.
     *
     * @param string               $body      Raw body.
     * @param string               $body_hash Signed body hash.
     * @param array<string,string> $headers   Content-type header.
     * @param int                  $timeout   Seconds.
     * @param int                  $tries     Attempts on transport error.
     * @return array<string,mixed>
     */
    private function send(string $body, string $body_hash, array $headers, int $timeout, int $tries): array {
        if (!$this->configured()) {
            throw new ConnectorAuthException('This destination is not paired with a connector yet.');
        }
        // The raw secret travels in every request — never over plain HTTP.
        if (!self::url_is_secure($this->url)) {
            throw new ConnectorHostException('The connector needs an HTTPS Destination URL (the pairing secret travels in each request). Change the Destination URL back to https://.');
        }

        $last = null;
        for ($attempt = 0; $attempt < max(1, $tries); $attempt++) {
            $ts    = (string) time();
            $nonce = bin2hex(random_bytes(16));
            $sig   = hash_hmac('sha256', implode("\n", [$this->id, $ts, $nonce, $body_hash]), $this->secret);

            $response = UrlSafety::guarded_post($this->endpoint(), [
                'timeout'   => $timeout,
                'sslverify' => $this->sslverify,
                'headers'   => $headers + [
                    'X-BS-Id'        => $this->id,
                    'X-BS-Timestamp' => $ts,
                    'X-BS-Nonce'     => $nonce,
                    'X-BS-Key'       => $this->secret,
                    'X-BS-Signature' => $sig,
                    'X-BS-Version'   => (string) ConnectorScript::VERSION,
                ],
                'body'      => $body,
            ]);

            if (is_wp_error($response)) {
                $last = new \RuntimeException('Could not reach the connector: ' . $response->get_error_message());
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $text = (string) wp_remote_retrieve_body($response);
            $data = json_decode($text, true);

            if ($code === 200 && is_array($data) && !empty($data['ok'])) {
                return $data;
            }

            // Only OUR receiver answers with {ok:false, error:…}; an HTML 403/404
            // is the web server refusing or missing the file (e.g. nginx denying
            // PHP under uploads/), which is a host problem, not a pairing one.
            $ours  = is_array($data) && array_key_exists('ok', $data);
            $error = $ours ? sanitize_text_field((string) ($data['error'] ?? '')) : '';

            if ($ours && $code === 403) {
                throw new ConnectorAuthException('The connector rejected this site\'s credentials — the file on the destination is not the one paired here. Re-pair and upload the new connector file.');
            }
            if ($ours && $code === 429) {
                throw new ConnectorAuthException('The connector is temporarily locked after repeated failed requests. Wait ten minutes and try again.');
            }
            if ($ours && $code === 409 && $error === 'version') {
                throw new ConnectorVersionException('The connector file on the destination is outdated. Re-pair and upload the new one.');
            }
            if ($code === 413) {
                throw new ConnectorTooLargeException(sprintf(
                    'The destination\'s web server refused a %s upload (HTTP 413), even after retrying in smaller pieces. On nginx, set client_max_body_size to at least 2m for this site (its default is 1m), then reload nginx.',
                    size_format(strlen($body))
                ));
            }
            if (!$ours && in_array($code, [502, 503, 504], true)) {
                // The web server gave up waiting for PHP (nginx: fastcgi_read_timeout,
                // 60 s by default) — worth retrying; the work may have finished.
                throw new \RuntimeException(sprintf(
                    'The destination\'s web server stopped waiting for the connector (HTTP %d). On nginx, raise fastcgi_read_timeout (for example to 300s) for this site; on other hosts, ask them about PHP time limits.',
                    $code
                ));
            }
            if (!$ours && $code === 405) {
                throw new ConnectorHostException(sprintf('The web server at %s refused to run the connector (HTTP 405): it is treating the file as static, not as PHP. Make sure PHP runs for .php files in that folder (on nginx, a location ~ \\.php$ block passing to PHP-FPM).', $this->endpoint()));
            }
            if ($code === 404) {
                throw new ConnectorHostException('Connector file not found at ' . $this->endpoint() . ' — upload it to the folder served at the Destination URL.');
            }
            if (!$ours) {
                // Our JSON never came back — served as text, refused by the web
                // server, or an error page: PHP isn't running the file at that URL.
                throw new ConnectorHostException(sprintf('No connector answered at %s (HTTP %d) — check the Destination URL, that the file is in that folder, and that the host runs PHP there.', $this->endpoint(), $code));
            }

            throw new \RuntimeException(sprintf('Connector error (HTTP %d%s).', $code, $error !== '' ? ': ' . $error : ''));
        }

        throw $last ?? new \RuntimeException('Connector request failed.');
    }
}
