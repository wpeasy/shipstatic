<?php
/**
 * Move a WordPress site installed at the domain root into /wp.
 *
 * @package WPEasy\ShipStatic
 * @since   2.4.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Deploy;

use WPEasy\ShipStatic\Lockdown\Settings as LockdownSettings;
use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Support\SerializedReplace;
use WPEasy\ShipStatic\Sync\HtaccessBuilder;
use WPEasy\ShipStatic\Sync\Manifest;
use WPEasy\ShipStatic\Sync\Runner;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are plain text for JSON responses and WP-CLI.
// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- moving WordPress's own folders must be an atomic rename on this disk, done after the response with no further WordPress file loaded; WP_Filesystem can't express that.

/**
 * The usual client starting point is WordPress at the domain root. This moves
 * it into /wp so the root can hold the static site (docs/SERVER_FOLDER_PLAN.md
 * §8): pre-flight → database (URLs and paths get /wp) → rename WordPress's own
 * folders and files into wp/ (instant, same disk) → .htaccess for both → the
 * calling destination switched to "This server" at the root. Everything is
 * recorded in an option so it can be undone.
 */
final class WordPressMove {

    /** The move record (survives the move: the database isn't moved). */
    public const OPTION = 'shipstatic_wp_move';

    /** The folder WordPress moves into. */
    public const FOLDER = 'wp';

    /** WordPress's own top-level entries (plus every wp-*.php). */
    private const ENTRIES = ['wp-admin', 'wp-includes', 'wp-content', 'wp-config.php', 'index.php', 'xmlrpc.php', 'license.txt', 'readme.html'];

    /** Copied rather than moved: both folders need them. */
    private const COPIES = ['.htaccess', 'php.ini'];

    /**
     * wp-config.php constants that pin URLs or paths we would have to change.
     */
    private const PINNED = ['WP_HOME', 'WP_SITEURL', 'WP_CONTENT_DIR', 'WP_CONTENT_URL', 'WP_PLUGIN_DIR', 'WP_PLUGIN_URL', 'UPLOADS'];

    /**
     * Can WordPress be moved into /wp here?
     *
     * @param string $doc_root The web request's document root ('' from WP-CLI).
     * @return array{ok:bool,problems:array<int,string>,warnings:array<int,string>,root:string,from:string,to:string,entries:array<int,string>}
     */
    public static function check(string $doc_root): array {
        $root     = rtrim((string) realpath(ABSPATH), '/');
        $home     = untrailingslashit(home_url());
        $problems = [];
        $warnings = [];

        if (is_multisite()) {
            $problems[] = __('This is a multisite network, which can’t be moved this way.', 'shipstatic');
        }
        if (get_option(self::OPTION)) {
            $problems[] = __('WordPress has already been moved. Undo that move first.', 'shipstatic');
        }
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (rtrim((string) realpath(get_home_path()), '/') !== $root) {
            $problems[] = __('WordPress is installed in its own directory (its files and its address are in different folders). Move it by hand instead.', 'shipstatic');
        }
        if (trim((string) wp_parse_url($home, PHP_URL_PATH), '/') !== '' || trim((string) wp_parse_url(site_url(), PHP_URL_PATH), '/') !== '') {
            $problems[] = __('WordPress isn’t at the root of its domain.', 'shipstatic');
        }
        $doc_real = $doc_root !== '' ? rtrim((string) realpath($doc_root), '/') : '';
        if ($doc_real !== '' && $doc_real !== $root) {
            $problems[] = __('WordPress’s folder isn’t the folder this domain serves.', 'shipstatic');
        }
        if (file_exists($root . '/' . self::FOLDER)) {
            /* translators: %s: folder name. */
            $problems[] = sprintf(__('A file or folder named “%s” already exists next to WordPress.', 'shipstatic'), self::FOLDER);
        }
        if (self::slug_taken()) {
            /* translators: %s: slug. */
            $problems[] = sprintf(__('A page or post uses the address /%s/, which WordPress would need.', 'shipstatic'), self::FOLDER);
        }
        $config = is_file($root . '/wp-config.php') ? (string) file_get_contents($root . '/wp-config.php') : '';
        if ($config === '') {
            $problems[] = __('wp-config.php isn’t in WordPress’s folder (it’s one level up). Move it in first, or move WordPress by hand.', 'shipstatic');
        }
        // WordPress defines most of these itself, so look at what wp-config.php sets.
        foreach (self::PINNED as $constant) {
            if (preg_match('/define\s*\(\s*[\'"]' . $constant . '[\'"]/', $config)) {
                /* translators: %s: constant name. */
                $problems[] = sprintf(__('wp-config.php sets %s, which would point to the old place. Remove it first.', 'shipstatic'), $constant);
            }
        }
        if (LockdownSettings::enabled()) {
            $problems[] = __('Turn Lockdown off first (it keeps paths that change). Turn it back on after the move.', 'shipstatic');
        }
        if (!Runner::render_is_current()) {
            $problems[] = __('Process all first, so the static site can go live straight after the move.', 'shipstatic');
        }

        $entries = self::entries($root);
        if (!wp_is_writable($root)) {
            $problems[] = __('PHP can’t write to WordPress’s folder.', 'shipstatic');
        }
        foreach ($entries as $entry) {
            if (is_dir($root . '/' . $entry) && !wp_is_writable($root . '/' . $entry)) {
                /* translators: %s: folder name. */
                $problems[] = sprintf(__('PHP can’t move %s (check its permissions).', 'shipstatic'), $entry);
            }
        }

        foreach (['.user.ini', 'php.ini'] as $ini) {
            $text = is_file($root . '/' . $ini) ? (string) file_get_contents($root . '/' . $ini) : '';
            if ($text !== '' && preg_match('/auto_prepend_file\s*=\s*["\']?' . preg_quote($root . '/', '/') . '/', $text)) {
                /* translators: %s: file name. */
                $warnings[] = sprintf(__('%s loads a file from WordPress’s folder before every request (a firewall such as Wordfence). After the move, re-run that plugin’s setup so it uses the new place.', 'shipstatic'), $ini);
            }
        }
        foreach (['advanced-cache.php', 'object-cache.php'] as $dropin) {
            if (is_file(WP_CONTENT_DIR . '/' . $dropin)) {
                /* translators: %s: file name. */
                $warnings[] = sprintf(__('A caching plugin installed %s. Purge and re-save that plugin’s settings after the move.', 'shipstatic'), $dropin);
            }
        }

        return [
            'ok'       => $problems === [],
            'problems' => $problems,
            'warnings' => $warnings,
            'root'     => $root,
            'from'     => $home,
            'to'       => $home . '/' . self::FOLDER,
            'entries'  => $entries,
        ];
    }

    /**
     * Move WordPress into /wp. The database changes now; the files move once
     * the response is sent (web) or straight away (WP-CLI).
     *
     * @param string $doc_root The web request's document root.
     * @param string $dest_id  Destination that will publish the static site at the root.
     * @return array{ok:bool,message:string,problems?:array<int,string>,redirect?:string}
     */
    public static function run(string $doc_root, string $dest_id): array {
        $check = self::check($doc_root);
        if (!$check['ok']) {
            return ['ok' => false, 'message' => __('WordPress can’t be moved yet.', 'shipstatic'), 'problems' => $check['problems']];
        }
        if (Destinations::get($dest_id) === null) {
            return ['ok' => false, 'message' => 'Unknown destination.'];
        }

        // Everything the move needs, loaded before any file of it moves.
        foreach ([SerializedReplace::class, HtaccessBuilder::class, Manifest::class, Runner::class, ServerFolder::class, Destinations::class] as $class) {
            class_exists($class);
        }

        $root   = $check['root'];
        $dest   = Destinations::get($dest_id);
        $record = [
            'state'      => 'database',
            'root'       => $root,
            'entries'    => $check['entries'],
            'from'       => $check['from'],
            'to'         => $check['to'],
            'siteurl'    => (string) get_option('siteurl'),
            'home'       => (string) get_option('home'),
            'htaccess'   => is_file($root . '/.htaccess') ? (string) file_get_contents($root . '/.htaccess') : null,
            'destId'     => $dest_id,
            'destBefore' => ['transport' => (string) $dest->get('transport'), 'destinationUrl' => (string) $dest->get('destinationUrl'), 'enabled' => (bool) $dest->get('enabled')],
            'time'       => time(),
            'error'      => '',
        ];
        // Everything written after the files move is prepared now: the
        // plugin's own templates move with wp-content.
        $record['rootHtaccess'] = HtaccessBuilder::merge((string) $record['htaccess'], [self::FOLDER]);
        $record['holding']      = Runner::holding_page();
        update_option(self::OPTION, $record, false);

        self::database($record, false);
        // The destination that publishes the static site at the root (its
        // folder is marked once the files have moved).
        Destinations::set_folder($dest_id, $root, trailingslashit((string) $record['from']));
        Destinations::update($dest_id, ['enabled' => true]);
        $record['state'] = 'files';
        update_option(self::OPTION, $record, false);

        $redirect = $check['to'] . '/wp-login.php?redirect_to=' . rawurlencode($check['to'] . '/wp-admin/admin.php?page=shipstatic');

        if (defined('WP_CLI') && WP_CLI) {
            $result = self::move_files();
            return ['ok' => $result === '', 'message' => $result === '' ? __('WordPress moved into /wp.', 'shipstatic') : $result, 'redirect' => $redirect];
        }

        // After this response: nothing may load a WordPress file once they move.
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            self::move_files();
        });

        /* translators: %s: new WordPress address. */
        return ['ok' => true, 'message' => sprintf(__('Moving WordPress to %s. Log in there to continue.', 'shipstatic'), $check['to']), 'redirect' => $redirect];
    }

    /**
     * Rename WordPress's entries into wp/, set up both .htaccess files, a
     * holding page and the static destination. On failure, everything goes
     * back. Must run when no further WordPress file will be loaded.
     *
     * @return string '' when moved, else the error.
     */
    public static function move_files(): string {
        $record = get_option(self::OPTION);
        if (!is_array($record) || ($record['state'] ?? '') !== 'files') {
            return 'Nothing to move.';
        }
        $root  = (string) $record['root'];
        if ($root === '' || $root[0] !== '/' || !is_dir($root)) {
            $record['state'] = 'failed';
            $record['error'] = 'The recorded WordPress folder is missing.';
            update_option(self::OPTION, $record, false);

            return $record['error'];
        }
        $dir   = $root . '/' . self::FOLDER;
        $moved = [];
        $error = '';

        if (!@mkdir($dir, 0755)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
            $error = 'Could not create ' . $dir;
        } else {
            @chmod($dir, 0755); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- not left to the umask: the web server must enter it.
            foreach ((array) $record['entries'] as $entry) {
                if (!@rename($root . '/' . $entry, $dir . '/' . $entry)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
                    $error = 'Could not move ' . $entry;
                    break;
                }
                $moved[] = $entry;
            }
        }

        if ($error !== '') {
            foreach (array_reverse($moved) as $entry) {
                @rename($dir . '/' . $entry, $root . '/' . $entry); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort back.
            }
            @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- only if empty.
            self::database($record, true);
            Destinations::update((string) $record['destId'], (array) $record['destBefore']);
            $record['state'] = 'failed';
            $record['error'] = $error;
            update_option(self::OPTION, $record, false);

            return $error;
        }

        foreach (self::COPIES as $copy) {
            if (is_file($root . '/' . $copy)) {
                @copy($root . '/' . $copy, $dir . '/' . $copy); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- same reason as above.
            }
        }
        // WordPress's rules now live in wp/ with its new base; the root keeps
        // everything else (a host's PHP handler) and gets the static rules.
        $wp_rules = self::wordpress_block();
        $copied   = is_file($dir . '/.htaccess') ? (string) file_get_contents($dir . '/.htaccess') : '';
        $copied   = (string) preg_replace('/\n*# BEGIN WordPress\b.*?# END WordPress[^\n]*\n?/s', "\n", $copied);
        $copied   = (string) preg_replace('/\n*# BEGIN ShipStatic\b.*?# END ShipStatic[^\n]*\n?/s', "\n", $copied);
        file_put_contents($dir . '/.htaccess', $wp_rules . "\n" . trim($copied) . "\n");
        file_put_contents($root . '/.htaccess', (string) $record['rootHtaccess']);

        // A holding page until the first Sync replaces it.
        if (!file_exists($root . '/index.html')) {
            file_put_contents($root . '/index.html', (string) $record['holding']);
        }
        file_put_contents($root . '/' . ServerFolder::MARKER, (string) $record['destId']);

        $record['state'] = 'moved';
        update_option(self::OPTION, $record, false);

        return '';
    }

    /**
     * Move WordPress back to the root: remove the static files the root
     * destination published, rename the entries back, restore the database
     * and the root .htaccess. Runs after the response (web) — this request's
     * own files move.
     *
     * @return array{ok:bool,message:string,redirect?:string}
     */
    public static function undo(): array {
        $record = get_option(self::OPTION);
        if (!is_array($record) || ($record['state'] ?? '') !== 'moved') {
            return ['ok' => false, 'message' => __('There is no move to undo.', 'shipstatic')];
        }
        foreach ([SerializedReplace::class, HtaccessBuilder::class, Destinations::class, Manifest::class] as $class) {
            class_exists($class);
        }
        $root = (string) $record['root'];
        $dir  = $root . '/' . self::FOLDER;
        foreach ((array) $record['entries'] as $entry) {
            if (file_exists($root . '/' . $entry)) {
                /* translators: %s: file name. */
                return ['ok' => false, 'message' => sprintf(__('%s already exists at the root, so WordPress can’t move back.', 'shipstatic'), $entry)];
            }
        }

        // The static site goes: only files ShipStatic published there.
        $dest_id = (string) $record['destId'];
        $dirs    = [];
        foreach (array_keys(Manifest::load(Destinations::pushed_option($dest_id))) as $rel) {
            $file = $root . '/' . $rel;
            if (strpos($rel, '..') === false && strpos($rel, self::FOLDER . '/') !== 0 && is_file($file) && !preg_match('/\.php\d*$/i', $rel)) {
                wp_delete_file($file);
                if (is_file($file . '.gz')) {
                    wp_delete_file($file . '.gz');
                }
                for ($d = dirname($rel); $d !== '.' && $d !== ''; $d = dirname($d)) {
                    $dirs[$d] = substr_count($d, '/');
                }
            }
        }
        // Emptied folders go too: an empty about/ at the root would answer
        // WordPress's own /about/ with a 403.
        arsort($dirs);
        foreach (array_keys($dirs) as $d) {
            @rmdir($root . '/' . $d); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- only removes empty folders.
        }
        delete_option(Destinations::pushed_option($dest_id));
        wp_delete_file($root . '/' . ServerFolder::MARKER);
        if (is_file($root . '/index.html') && (string) file_get_contents($root . '/index.html') === (string) ($record['holding'] ?? '')) {
            wp_delete_file($root . '/index.html');
        }
        // Back to how it published before, switched off (its address is WordPress again).
        Destinations::update($dest_id, ['enabled' => false] + (array) ($record['destBefore'] ?? []));
        Destinations::update($dest_id, ['enabled' => false]);

        self::database($record, true);
        $record['state'] = 'undoing';
        update_option(self::OPTION, $record, false);

        $back = static function () use ($record, $root, $dir): void {
            foreach ((array) $record['entries'] as $entry) {
                @rename($dir . '/' . $entry, $root . '/' . $entry); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort; the record says what was moved.
            }
            if (is_string($record['htaccess'])) {
                file_put_contents($root . '/.htaccess', $record['htaccess']);
            } else {
                wp_delete_file($root . '/.htaccess');
            }
            foreach (self::COPIES as $copy) {
                if (is_file($dir . '/' . $copy)) {
                    wp_delete_file($dir . '/' . $copy);
                }
            }
            @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- only if empty.
            delete_option(self::OPTION);
        };

        $redirect = $record['from'] . '/wp-login.php?redirect_to=' . rawurlencode($record['from'] . '/wp-admin/admin.php?page=shipstatic');
        if (defined('WP_CLI') && WP_CLI) {
            $back();
        } else {
            register_shutdown_function(static function () use ($back): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                $back();
            });
        }

        /* translators: %s: WordPress address. */
        return ['ok' => true, 'message' => sprintf(__('Moving WordPress back to %s. Log in there again.', 'shipstatic'), $record['from']), 'redirect' => $redirect];
    }

    /**
     * Forget a finished move (it can no longer be undone from the dashboard).
     */
    public static function forget(): void {
        $record = get_option(self::OPTION);
        if (is_array($record) && in_array($record['state'] ?? '', ['moved', 'failed'], true)) {
            delete_option(self::OPTION);
        }
    }

    /**
     * The move record for the dashboard (no paths beyond what an admin sees).
     *
     * @return array{state:string,from:string,to:string,error:string,time:int}|null
     */
    public static function status(): ?array {
        $record = get_option(self::OPTION);
        if (!is_array($record)) {
            return null;
        }

        return [
            'state' => (string) ($record['state'] ?? ''),
            'from'  => (string) ($record['from'] ?? ''),
            'to'    => (string) ($record['to'] ?? ''),
            'error' => (string) ($record['error'] ?? ''),
            'time'  => (int) ($record['time'] ?? 0),
        ];
    }

    /**
     * Database: URLs of this site get /wp (or lose it), file paths into
     * WordPress's folders move with them, home/siteurl set. ShipStatic's own
     * settings keep their URLs (a destination's address is the static site).
     *
     * @param array<string,mixed> $record Move record.
     * @param bool                $back   Undo instead.
     */
    private static function database(array $record, bool $back): void {
        global $wpdb;

        $root = (string) $record['root'];
        $host = (string) wp_parse_url((string) $record['from'], PHP_URL_HOST);
        if ($root === '' || $root[0] !== '/' || $host === '') {
            return; // Never run a replacement without both.
        }
        $url  = SerializedReplace::url_subpath($host, self::FOLDER, $back);
        $old  = [];
        $new  = [];
        foreach (['wp-content', 'wp-includes', 'wp-admin'] as $d) {
            $old[] = $root . '/' . $d;
            $new[] = $root . '/' . self::FOLDER . '/' . $d;
        }
        $paths = static fn(string $v): string => $back ? str_replace($new, $old, $v) : str_replace($old, $new, $v);

        SerializedReplace::run(
            static fn(string $v): string => $url($v),
            static function (string $table, string $column, array $row) use ($wpdb): bool {
                // Our settings (destination URLs) and the move record stay; home/siteurl are set below.
                return $table === $wpdb->options && (strpos((string) $row['option_name'], 'shipstatic_') === 0 || in_array($row['option_name'], ['home', 'siteurl', self::OPTION], true));
            }
        );
        SerializedReplace::run(
            $paths,
            static function (string $table, string $column, array $row) use ($wpdb): bool {
                return $table !== $wpdb->options || $row['option_name'] === self::OPTION;
            }
        );

        $wpdb->update($wpdb->options, ['option_value' => $back ? (string) $record['home'] : (string) $record['to']], ['option_name' => 'home']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no hooks may fire mid-move.
        $wpdb->update($wpdb->options, ['option_value' => $back ? (string) $record['siteurl'] : $url((string) $record['siteurl'])], ['option_name' => 'siteurl']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- as above.
        wp_cache_flush();
    }

    /**
     * WordPress's own entries at the root.
     *
     * @param string $root WordPress root.
     * @return array<int,string>
     */
    private static function entries(string $root): array {
        $out = [];
        foreach (self::ENTRIES as $entry) {
            if (file_exists($root . '/' . $entry)) {
                $out[] = $entry;
            }
        }
        foreach ((array) glob($root . '/wp-*.php') as $file) {
            $name = basename((string) $file);
            if (!in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * The standard WordPress rewrite block for a site in /wp/.
     */
    private static function wordpress_block(): string {
        $base = '/' . self::FOLDER . '/';

        return "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\nRewriteBase {$base}\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . {$base}index.php [L]\n</IfModule>\n# END WordPress\n";
    }

    /**
     * Whether a post or page already uses the folder's name as its address.
     */
    private static function slug_taken(): bool {
        global $wpdb;
        $types = array_values(get_post_types(['public' => true]));
        if ($types === []) {
            return false;
        }
        $in = implode(', ', array_fill(0, count($types), '%s'));

        return (bool) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-off check.
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_parent = 0 AND post_status IN ('publish', 'private', 'draft', 'future', 'pending') AND post_type IN ({$in}) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
            array_merge([self::FOLDER], $types)
        ));
    }
}
