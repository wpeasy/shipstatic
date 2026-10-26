<?php
/**
 * "This server (folder)" transport.
 *
 * @package WPEasy\ShipStatic
 * @since   2.4.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Transport;

use WPEasy\ShipStatic\Deploy\ServerFolder;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text for JSON responses (rendered as text by the dashboard) and WP-CLI, never echoed into HTML; esc_html() here would show entities to the user.

/**
 * Publishes into a folder on this server by writing files directly — no FTP,
 * Connector or HTTP (docs/SERVER_FOLDER_PLAN.md, SECURITY_PATTERNS.md §1b).
 * An ordinary per-file transport, so hashing, the holding page, .htaccess
 * merge, .gz siblings and prune all work unchanged.
 *
 * The folder is public and PHP runs in it, so every key is confined:
 * - joined to the folder, no dot segments, and the real parent must stay
 *   inside it (no writing through a symlink);
 * - never into a protected top-level folder (WordPress's own folder when the
 *   static site sits above it; cgi-bin; .well-known);
 * - never a PHP file or `.user.ini`, never the ownership marker, and
 *   `.htaccess` only at the top.
 */
final class FolderTransport implements TransportInterface {

    /** Folder as stored (re-validated in connect()). */
    private string $folder;

    /** Destination id the folder's marker must hold. */
    private string $dest_id;

    /** The folder's real path once connected. */
    private string $root = '';

    /**
     * Protected top-level names (lowercase), set by connect().
     *
     * @var array<int,string>
     */
    private array $protected = [];

    /**
     * Names of WordPress folders below the static site (layout "domainRoot").
     *
     * @var array<int,string>
     */
    private array $wp_dirs = [];

    /**
     * @param array<string,mixed> $config Destination connection config.
     */
    public function __construct(array $config) {
        $this->folder  = (string) ($config['folderPath'] ?? '');
        $this->dest_id = (string) ($config['destId'] ?? '');
    }

    /**
     * Always usable: it only needs PHP's filesystem functions.
     */
    public static function is_available(): bool {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function connect(): void {
        if ($this->folder === '') {
            throw new \RuntimeException(__('No folder is set up for this destination yet. Use “Set up the folder”.', 'shipstatic'));
        }
        $info = ServerFolder::inspect($this->folder, $this->dest_id);
        if (!$info['ok']) {
            throw new \RuntimeException($info['error']);
        }
        if (!$info['exists'] || $info['marker'] !== 'ours') {
            throw new \RuntimeException(__('This folder isn’t set up for this destination (its .shipstatic-folder file is missing or belongs to another one). Use “Set up the folder” again.', 'shipstatic'));
        }

        $this->root      = $info['path'];
        $this->wp_dirs   = $info['protected'];
        $this->protected = array_map('strtolower', array_merge(ServerFolder::ALWAYS_PROTECTED, $info['protected']));
    }

    /**
     * @inheritdoc
     */
    public function disconnect(): void {
        // Nothing to close.
    }

    /**
     * The folder's real path (after connect()).
     */
    public function root(): string {
        return $this->root;
    }

    /**
     * WordPress folders that sit below the static site, which the static
     * site's .htaccess rules must stay out of.
     *
     * @return array<int,string>
     */
    public function wordpress_dirs(): array {
        return $this->wp_dirs;
    }

    /**
     * Summary for the connection Test.
     */
    public function describe(): string {
        $free = @disk_free_space($this->root); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not available on every host; false is handled.
        $bits = [$this->root];
        if ($this->wp_dirs !== []) {
            /* translators: %s: WordPress folder name(s). */
            $bits[] = sprintf(__('WordPress folder %s is left alone', 'shipstatic'), implode(', ', array_map(static fn(string $d): string => $d . '/', $this->wp_dirs)));
        }
        if (is_float($free)) {
            /* translators: %s: free disk space. */
            $bits[] = sprintf(__('%s free', 'shipstatic'), size_format($free));
        }

        return implode(' — ', $bits);
    }

    /**
     * @inheritdoc
     */
    public function put(string $local_path, string $remote_path): void {
        $target = $this->path($remote_path, true);
        $this->ensure_dir(dirname($target));

        // Write beside the target, then rename over it: a visitor never gets
        // half a file.
        $fs  = ServerFolder::fs();
        $tmp = dirname($target) . '/.' . basename($target) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (!$fs->copy($local_path, $tmp, true, ServerFolder::file_mode())) {
            /* translators: %s: file path. */
            throw new \RuntimeException(sprintf(__('Couldn’t write %s.', 'shipstatic'), $remote_path));
        }
        if (!$fs->move($tmp, $target, true)) {
            wp_delete_file($tmp);
            /* translators: %s: file path. */
            throw new \RuntimeException(sprintf(__('Couldn’t write %s.', 'shipstatic'), $remote_path));
        }
    }

    /**
     * @inheritdoc
     */
    public function mkdir(string $remote_path): void {
        $this->ensure_dir($this->path(rtrim($remote_path, '/'), false));
    }

    /**
     * @inheritdoc
     */
    public function exists(string $remote_path): bool {
        try {
            return file_exists($this->path($remote_path, false));
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function rename(string $from, string $to): bool {
        try {
            $source = $this->path($from, true);
            $target = $this->path($to, true);
        } catch (\RuntimeException $e) {
            return false;
        }
        if (!is_file($source)) {
            return false;
        }
        $this->ensure_dir(dirname($target));

        return ServerFolder::fs()->move($source, $target, true);
    }

    /**
     * @inheritdoc
     */
    public function get(string $remote_path): string {
        try {
            $file = $this->path($remote_path, false);
        } catch (\RuntimeException $e) {
            return '';
        }

        return is_file($file) ? (string) ServerFolder::fs()->get_contents($file) : '';
    }

    /**
     * @inheritdoc
     */
    public function delete(string $remote_path): bool {
        try {
            $file = $this->path($remote_path, true);
        } catch (\RuntimeException $e) {
            return false;
        }
        if (!is_file($file)) {
            return true;
        }
        wp_delete_file($file);

        return !file_exists($file);
    }

    /**
     * The absolute path for a key, or an exception when the key may not be
     * used. Its existing parent folders must resolve inside the folder.
     *
     * @param string $remote_path Key relative to the folder.
     * @param bool   $write       Whether it will be written or deleted.
     * @throws \RuntimeException When the key is refused.
     */
    private function path(string $remote_path, bool $write): string {
        if ($this->root === '') {
            throw new \RuntimeException('Not connected.');
        }
        $rel = ltrim(str_replace('\\', '/', $remote_path), '/');
        if ($rel === '' || strpos($rel, "\0") !== false) {
            throw new \RuntimeException(__('Refused an empty or invalid file name.', 'shipstatic'));
        }
        $segments = explode('/', $rel);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                /* translators: %s: file path. */
                throw new \RuntimeException(sprintf(__('Refused %s: not a plain path.', 'shipstatic'), $rel));
            }
        }
        if (in_array(strtolower($segments[0]), $this->protected, true)) {
            /* translators: %s: file path. */
            throw new \RuntimeException(sprintf(__('Refused %s: that folder belongs to WordPress or the hosting account.', 'shipstatic'), $rel));
        }

        $name = strtolower((string) end($segments));
        if ($name === strtolower(ServerFolder::MARKER)) {
            /* translators: %s: file path. */
            throw new \RuntimeException(sprintf(__('Refused %s.', 'shipstatic'), $rel));
        }
        if ($write) {
            if (preg_match('/\.(php\d*|phtml|phar|phps|pht)$/', $name) || $name === '.user.ini') {
                /* translators: %s: file path. */
                throw new \RuntimeException(sprintf(__('Refused %s: PHP files are never published.', 'shipstatic'), $rel));
            }
            if (strpos($name, '.htaccess') === 0 && count($segments) > 1) {
                /* translators: %s: file path. */
                throw new \RuntimeException(sprintf(__('Refused %s.', 'shipstatic'), $rel));
            }
        }

        // The deepest folder that already exists must be inside ours (a
        // symlinked folder pointing elsewhere is refused).
        $existing = $this->root . '/' . $rel;
        while (!file_exists($existing) && $existing !== $this->root) {
            $existing = dirname($existing);
        }
        $check = is_dir($existing) ? $existing : dirname($existing);
        $real  = realpath($check);
        if ($real === false || ($real !== $this->root && !ServerFolder::is_inside($real, $this->root))) {
            /* translators: %s: file path. */
            throw new \RuntimeException(sprintf(__('Refused %s: it leads outside the folder.', 'shipstatic'), $rel));
        }
        if (is_link($this->root . '/' . $rel)) {
            /* translators: %s: file path. */
            throw new \RuntimeException(sprintf(__('Refused %s: it leads outside the folder.', 'shipstatic'), $rel));
        }

        return $this->root . '/' . $rel;
    }

    /**
     * Create a folder (and its parents) inside the folder.
     *
     * @param string $dir Absolute path, already validated by path().
     * @throws \RuntimeException On failure.
     */
    private function ensure_dir(string $dir): void {
        if (is_dir($dir)) {
            return;
        }
        if (!wp_mkdir_p($dir)) {
            /* translators: %s: folder path. */
            throw new \RuntimeException(sprintf(__('Couldn’t create the folder %s.', 'shipstatic'), $dir));
        }
    }
}
