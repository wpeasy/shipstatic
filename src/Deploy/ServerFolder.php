<?php
/**
 * A folder on this server that a destination publishes into.
 *
 * @package WPEasy\ShipStatic
 * @since   2.4.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Deploy;

use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Support\UrlSafety;
use WPEasy\ShipStatic\Sync\Manifest;
use WPEasy\ShipStatic\Sync\Runner;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text for JSON responses (rendered as text by the dashboard) and WP-CLI, never echoed into HTML; esc_html() here would show entities to the user.

/**
 * Decides which folders the "This server (folder)" deploy method may use, and
 * sets one up (docs/SERVER_FOLDER_PLAN.md). Two layouts:
 *
 * - **domainRoot** — the folder is the one WordPress's folder sits in
 *   (`public_html` with WordPress in `public_html/wp`): one domain, the static
 *   site at its root. WordPress's folder is then protected: nothing is ever
 *   written into or deleted from it.
 * - **separate** — a folder that doesn't contain WordPress at all, such as a
 *   sibling `public_html/static` served by another domain.
 *
 * Every rule is checked again on every use (FolderTransport::connect()), so a
 * stored path that has since become unsafe is refused, never trusted.
 */
final class ServerFolder {

    /**
     * Ownership marker written into the folder at setup (holds the destination
     * id). The transport refuses a folder without the matching marker.
     */
    public const MARKER = '.shipstatic-folder';

    /**
     * Top-level folders never written into or deleted from, in any layout.
     */
    public const ALWAYS_PROTECTED = ['cgi-bin', '.well-known'];

    /** Permissions for what is written (WordPress's FS_CHMOD_* when set). */
    public static function dir_mode(): int {
        return defined('FS_CHMOD_DIR') ? (int) FS_CHMOD_DIR : 0755;
    }

    public static function file_mode(): int {
        return defined('FS_CHMOD_FILE') ? (int) FS_CHMOD_FILE : 0644;
    }

    /**
     * Inspect a folder path: may it be used, and what is in it?
     *
     * @param string $path    Absolute path (need not exist yet; its parent must).
     * @param string $dest_id Destination id the marker must match ('' = any).
     * @return array{ok:bool,error:string,path:string,exists:bool,layout:string,protected:array<int,string>,marker:string,empty:bool,hasIndex:bool,writable:bool}
     */
    public static function inspect(string $path, string $dest_id = ''): array {
        $out = [
            'ok'        => false,
            'error'     => '',
            'path'      => '',
            'exists'    => false,
            'layout'    => 'separate',
            'protected' => [],
            'marker'    => 'none',
            'empty'     => true,
            'hasIndex'  => false,
            'writable'  => false,
        ];

        $path = self::normalize($path);
        if ($path === null) {
            $out['error'] = __('Enter the full path of a folder on this server, for example /home/you/public_html.', 'shipstatic');
            return $out;
        }

        if (is_dir($path)) {
            $real          = (string) realpath($path);
            $out['exists'] = true;
        } else {
            // Only the last folder may be new: its parent must already exist.
            $parent = realpath(dirname($path));
            $name   = basename($path);
            if ($parent === false || !is_dir($parent)) {
                $out['error'] = __('Its parent folder doesn’t exist. Create the folders above it first, or pick an existing folder.', 'shipstatic');
                return $out;
            }
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
                $out['error'] = __('Use letters, digits, dots, dashes and underscores for the new folder’s name.', 'shipstatic');
                return $out;
            }
            $real = rtrim($parent, '/') . '/' . $name;
        }
        $out['path'] = $real;

        $account = self::account_root();
        if (!self::is_inside($real, $account)) {
            /* translators: %s: folder path the site's account lives in. */
            $out['error'] = sprintf(__('Pick a folder inside this hosting account (%s).', 'shipstatic'), $account);
            return $out;
        }

        foreach (self::wp_roots() as $root) {
            if ($real === $root) {
                $out['error'] = __('That is WordPress’s own folder. The static site needs a folder of its own.', 'shipstatic');
                return $out;
            }
            if (self::is_inside($real, $root)) {
                $out['error'] = __('That folder is inside WordPress. Pick the folder WordPress is in, or a folder beside it.', 'shipstatic');
                return $out;
            }
            if (self::is_inside($root, $real)) {
                if (dirname($root) !== $real) {
                    $out['error'] = __('WordPress is several folders below that one. Pick the folder WordPress is directly in, or a folder beside it.', 'shipstatic');
                    return $out;
                }
                $out['layout']      = 'domainRoot';
                $out['protected'][] = basename($root);
            }
        }
        $out['protected'] = array_values(array_unique($out['protected']));

        if ($out['exists']) {
            // PHP files, which an export never contains (its copied
            // wp-includes/ CSS and JS don't count).
            if ($out['layout'] === 'separate' && (is_file($real . '/wp-config.php') || is_file($real . '/wp-load.php') || is_file($real . '/wp-includes/version.php'))) {
                $out['error'] = __('That folder holds another WordPress site.', 'shipstatic');
                return $out;
            }
            $marker = self::marker($real);
            if ($marker !== '') {
                $out['marker'] = ($dest_id === '' || hash_equals($marker, $dest_id)) ? 'ours' : 'other';
            }
            if ($out['marker'] === 'other' && Destinations::get($marker) !== null) {
                $out['error'] = __('Another destination already publishes into that folder.', 'shipstatic');
                return $out;
            }
            $out['empty']    = self::is_empty($real, $out['protected']);
            $out['hasIndex'] = is_file($real . '/index.html');
            $out['writable'] = wp_is_writable($real);
        } else {
            $out['writable'] = wp_is_writable(dirname($real));
        }
        if (!$out['writable']) {
            $out['error'] = __('PHP can’t write to that folder. Check its permissions, or ask your host.', 'shipstatic');
            return $out;
        }

        $out['ok'] = true;

        return $out;
    }

    /**
     * Folders worth offering: the one WordPress sits in when that is the
     * domain's document root (one domain, WordPress in a sub-folder), a
     * sibling folder named after the destination's host (VPS and cPanel
     * set-ups usually name a domain's folder after it: /var/www/static.example.com),
     * and sibling folders that already hold a site.
     *
     * @param string $doc_root The current web request's document root ('' from WP-CLI).
     * @param string $dest_url The destination's address, when known.
     * @return array<int,array{path:string,url:string,layout:string}>
     */
    public static function suggestions(string $doc_root = '', string $dest_url = ''): array {
        $out  = [];
        $root = self::wp_roots()[0] ?? '';
        if ($root === '') {
            return $out;
        }
        $parent = dirname($root);

        // One domain: WordPress is at https://example.com/wp and the folder
        // above it is the domain's document root.
        $home_path = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        $doc_real  = $doc_root !== '' ? realpath($doc_root) : false;
        if ($home_path === basename($root) && $doc_real === $parent) {
            $scheme = (string) wp_parse_url(home_url('/'), PHP_URL_SCHEME);
            $host   = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            $port   = wp_parse_url(home_url('/'), PHP_URL_PORT);
            $out[]  = [
                'path'   => $parent,
                'url'    => $scheme . '://' . $host . ($port ? ':' . (int) $port : '') . '/',
                'layout' => 'domainRoot',
            ];
        }

        // A sibling named after the destination's host, empty or not.
        $host = strtolower((string) wp_parse_url($dest_url, PHP_URL_HOST));
        if ($host !== '' && preg_match('/^[a-z0-9.-]+$/', $host) && strpos($host, '..') === false) {
            $dir = $parent . '/' . $host;
            if ($dir !== $root && is_dir($dir) && self::inspect($dir)['ok']) {
                $out[] = ['path' => $dir, 'url' => trailingslashit($dest_url), 'layout' => 'separate'];
            }
        }

        // Siblings that already hold a site (a static site moved here from FTP).
        $entries = @scandir($parent); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable parent just means no suggestions.
        foreach (is_array($entries) ? $entries : [] as $name) {
            $dir = $parent . '/' . $name;
            if ($name[0] === '.' || in_array($name, self::ALWAYS_PROTECTED, true) || $dir === $root || $name === $host || !is_dir($dir) || !is_file($dir . '/index.html')) {
                continue;
            }
            if (self::inspect($dir)['ok']) {
                $out[] = ['path' => $dir, 'url' => '', 'layout' => 'separate'];
            }
        }

        return array_slice($out, 0, 8);
    }

    /**
     * Create the folder (when new) and write the marker.
     *
     * @param string $path    Folder path.
     * @param string $dest_id Destination id.
     * @return string The folder's real path.
     * @throws \RuntimeException When the folder may not be used or can't be written.
     */
    public static function prepare(string $path, string $dest_id): string {
        $info = self::inspect($path, $dest_id);
        if (!$info['ok']) {
            throw new \RuntimeException($info['error']);
        }
        $fs   = self::fs();
        $real = $info['path'];
        if (!$info['exists'] && !$fs->mkdir($real, self::dir_mode())) {
            throw new \RuntimeException(__('Couldn’t create the folder.', 'shipstatic'));
        }
        if (!$fs->put_contents($real . '/' . self::MARKER, $dest_id, self::file_mode())) {
            throw new \RuntimeException(__('Couldn’t write to the folder.', 'shipstatic'));
        }

        return (string) realpath($real);
    }

    /**
     * What the folder already holds, for files the current deploy would push:
     * relative path → size + md5, in the shape Runner::rebuild_pushed_record()
     * takes. Lets a folder that already holds the static site (moved here from
     * FTP or the Connector) be taken over without rewriting every file.
     *
     * @param string $folder  Folder's real path.
     * @param string $dest_id Destination id.
     * @return array<string,array{size:int,md5:string}>
     */
    public static function inventory(string $folder, string $dest_id): array {
        $out = [];
        foreach (array_keys(Runner::deploy_manifest_for(Manifest::load(Manifest::RENDER_OPTION), $dest_id)) as $rel) {
            $file = $folder . '/' . $rel;
            if (strpos($rel, '..') === false && is_file($file)) {
                $out[$rel] = ['size' => (int) filesize($file), 'md5' => (string) md5_file($file)];
            }
        }

        return $out;
    }

    /**
     * Check that the Destination URL serves this folder: write a probe file,
     * fetch it through the URL, compare, remove it.
     *
     * @param string $folder Folder's real path.
     * @param string $url    Destination URL.
     * @return string '' when it does, else why not.
     */
    public static function probe(string $folder, string $url): string {
        if (trim($url) === '') {
            return __('Enter the address the folder is served at (Destination URL) so Test can check it.', 'shipstatic');
        }
        // In a new sub-folder, made the way Sync makes them: a folder the web
        // server can't enter would otherwise pass here and 404 every page.
        $sub   = 'shipstatic-probe-' . bin2hex(random_bytes(6));
        $name  = $sub . '/probe.txt';
        $token = bin2hex(random_bytes(16));
        $fs    = self::fs();
        if (!$fs->mkdir($folder . '/' . $sub, self::dir_mode()) || !$fs->put_contents($folder . '/' . $name, $token, self::file_mode())) {
            $fs->rmdir($folder . '/' . $sub, true);
            return __('Couldn’t write a test file into the folder.', 'shipstatic');
        }
        $target   = rtrim($url, '/') . '/' . $name;
        $response = UrlSafety::guarded_get($target, [
            'timeout'             => 15,
            'sslverify'           => PackageDeployer::sslverify($target),
            'limit_response_size' => 4096,
        ]);
        wp_delete_file($folder . '/' . $name);
        $fs->rmdir($folder . '/' . $sub);

        if (is_wp_error($response)) {
            /* translators: 1: Destination URL, 2: error. */
            return sprintf(__('Couldn’t open %1$s: %2$s', 'shipstatic'), $url, $response->get_error_message());
        }
        if (trim((string) wp_remote_retrieve_body($response)) !== $token) {
            /* translators: 1: Destination URL, 2: HTTP status code. */
            return sprintf(__('%1$s doesn’t serve this folder yet (a test file came back as HTTP %2$d). Point the domain at the folder in your hosting panel, or correct the Destination URL.', 'shipstatic'), $url, (int) wp_remote_retrieve_response_code($response));
        }

        return '';
    }

    /**
     * The real paths of WordPress's own folders: the core folder and, when they
     * differ ("WordPress in its own directory"), the site folder.
     *
     * @return array<int,string>
     */
    public static function wp_roots(): array {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $roots = [];
        foreach ([ABSPATH, get_home_path()] as $dir) {
            $real = $dir !== '' ? realpath($dir) : false;
            if ($real !== false) {
                $roots[] = rtrim($real, '/');
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * The hosting account's folder: the PHP user's home directory when
     * WordPress is inside it, else the folder two levels above WordPress.
     * Never the filesystem root.
     */
    public static function account_root(): string {
        $wp   = self::wp_roots()[0] ?? rtrim((string) realpath(ABSPATH), '/');
        $home = '';
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            $home = is_array($user) ? (string) realpath((string) $user['dir']) : '';
        }
        if ($home !== '' && $home !== '/' && self::is_inside($wp, $home)) {
            return rtrim($home, '/');
        }
        $root = dirname($wp, 2);

        return $root === '/' ? dirname($wp) : $root;
    }

    /**
     * The marker's destination id, or ''.
     *
     * @param string $folder Folder.
     */
    public static function marker(string $folder): string {
        $file = $folder . '/' . self::MARKER;
        if (!is_file($file)) {
            return '';
        }
        $id = trim((string) self::fs()->get_contents($file));

        return preg_match('/^[A-Za-z0-9]{1,64}$/', $id) ? $id : '';
    }

    /**
     * Whether a path is strictly inside a folder.
     *
     * @param string $path   Path.
     * @param string $folder Folder.
     */
    public static function is_inside(string $path, string $folder): bool {
        return strpos($path, rtrim($folder, '/') . '/') === 0;
    }

    /**
     * Absolute, NUL-free, no dot segments; trailing slash removed.
     *
     * @param string $path Raw path.
     */
    private static function normalize(string $path): ?string {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false) {
            return null;
        }
        $path = (string) preg_replace('#/+#', '/', $path);
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }
        $path = rtrim($path, '/');

        return $path === '' ? null : $path;
    }

    /**
     * Whether a folder has nothing in it but our marker, hosting defaults
     * (cgi-bin, .well-known) and the protected WordPress folder.
     *
     * @param string            $folder    Folder.
     * @param array<int,string> $protected Protected names.
     */
    private static function is_empty(string $folder, array $protected): bool {
        $entries = @scandir($folder); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable = treated as not empty.
        if (!is_array($entries)) {
            return false;
        }
        $ignore = array_merge(['.', '..', self::MARKER, '.htaccess'], self::ALWAYS_PROTECTED, $protected);

        return array_diff($entries, $ignore) === [];
    }

    /**
     * Direct filesystem access (this server's own disk, as the PHP user).
     */
    public static function fs(): \WP_Filesystem_Direct {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

        return new \WP_Filesystem_Direct(null);
    }
}
