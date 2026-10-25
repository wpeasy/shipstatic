<?php
/**
 * Writes and removes Lockdown's files.
 *
 * @package WPEasy\ShipStatic
 * @since   2.3.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Lockdown;

use WPEasy\ShipStatic\Render\PageRenderer;

defined('ABSPATH') || exit;

/**
 * Generates every Lockdown layer from the settings (docs/LOCKDOWN_PLAN.md §4):
 *
 * - the gate, `wp-content/shipstatic-lockdown.php` (always);
 * - the mu-plugin that runs it inside WordPress (always);
 * - a block at the top of `.htaccess` (Apache, LiteSpeed);
 * - a block in `.user.ini` that prepends the gate to every PHP file (nginx
 *   and other FastCGI hosts).
 *
 * Only validated values reach these files (SECURITY_PATTERNS.md §11): ranges
 * rebuilt by Cidr, letters/digits/hex secrets, a URL rebuilt by
 * Settings::redirect_base(). PHP values are written with var_export().
 */
final class Writer {

    /** Bump when a template changes, so existing sites rewrite their files. */
    public const VERSION = '2';

    private const BEGIN = 'BEGIN ShipStatic Lockdown';
    private const END   = 'END ShipStatic Lockdown';

    /** Scheduled event that deletes the stub gate (see remove_all()). */
    public const CLEANUP_HOOK = 'shipstatic_lockdown_cleanup';

    /** How long the stub stays: longer than PHP's default user_ini.cache_ttl (300 s). */
    private const STUB_SECONDS = 600;

    /** Server facts, saved from a web request (WP-CLI can't see them). */
    public const ENV_OPTION = 'shipstatic_lockdown_env';

    /**
     * Save what this web request says about the server.
     */
    public static function remember_environment(): void {
        if (PHP_SAPI === 'cli') {
            return;
        }
        global $is_apache, $is_nginx, $is_iis7;

        $software = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))) : '';
        if (strpos($software, 'litespeed') !== false) {
            $server = 'litespeed';
        } elseif (!empty($is_apache)) {
            $server = 'apache';
        } elseif (!empty($is_nginx)) {
            $server = 'nginx';
        } elseif (!empty($is_iis7)) {
            $server = 'iis';
        } else {
            $server = 'other';
        }

        $env = [
            'server'  => $server,
            'sapi'    => PHP_SAPI,
            'userIni' => (string) ini_get('user_ini.filename'),
            'prepend' => (string) ini_get('auto_prepend_file'),
        ];
        if (get_option(self::ENV_OPTION) !== $env) {
            update_option(self::ENV_OPTION, $env, false);
        }
    }

    /**
     * The saved server facts (empty values until a web request saved them).
     *
     * @return array{server:string,sapi:string,userIni:string,prepend:string}
     */
    public static function environment(): array {
        $env = get_option(self::ENV_OPTION, []);
        $env = is_array($env) ? $env : [];

        return [
            'server'  => (string) ($env['server'] ?? ''),
            'sapi'    => (string) ($env['sapi'] ?? ''),
            'userIni' => (string) ($env['userIni'] ?? ''),
            'prepend' => (string) ($env['prepend'] ?? ''),
        ];
    }

    /**
     * Whether this server reads `.htaccess`.
     */
    public static function uses_htaccess(): bool {
        return in_array(self::environment()['server'], ['apache', 'litespeed'], true);
    }

    /**
     * Whether `.user.ini` is the way in front of PHP here: a FastCGI PHP that
     * reads per-directory ini files, on a server without `.htaccess`.
     */
    public static function uses_user_ini(): bool {
        $env = self::environment();

        return !self::uses_htaccess()
            && in_array($env['sapi'], ['fpm-fcgi', 'cgi-fcgi', 'cgi'], true)
            && self::user_ini_name() !== '';
    }

    /**
     * Bring every layer in line with the settings: write them when Lockdown
     * is on, remove them when it is off.
     *
     * @return array<int,string> Problems (empty when everything was written).
     */
    public static function apply(): array {
        if (!Settings::enabled()) {
            return self::remove_all();
        }

        $errors = [];
        // The gate first: the other layers point at it.
        if (!self::write(self::gate_path(), self::gate_source())) {
            $errors[] = sprintf(
                /* translators: %s: file path. */
                __('Couldn’t write %s.', 'shipstatic'),
                self::gate_path()
            );
            return $errors;
        }
        if (!wp_mkdir_p(WPMU_PLUGIN_DIR) || !self::write(self::mu_path(), self::mu_source())) {
            /* translators: %s: file path. */
            $errors[] = sprintf(__('Couldn’t write %s.', 'shipstatic'), self::mu_path());
        }

        if (self::uses_htaccess()) {
            if (!self::put_block(self::htaccess_path(), self::htaccess_block(), true)) {
                /* translators: %s: file path. */
                $errors[] = sprintf(__('Couldn’t write %s.', 'shipstatic'), self::htaccess_path());
            }
        } else {
            self::strip_block(self::htaccess_path());
        }

        if (self::uses_user_ini()) {
            if (!self::put_block(self::user_ini_path(), self::user_ini_block(), false)) {
                /* translators: %s: file path. */
                $errors[] = sprintf(__('Couldn’t write %s.', 'shipstatic'), self::user_ini_path());
            }
        } elseif (self::user_ini_path() !== '') {
            self::strip_block(self::user_ini_path());
        }

        Settings::update(['written' => self::VERSION]);

        return $errors;
    }

    /**
     * Remove every layer (Lockdown off, deactivation, uninstall).
     *
     * @return array<int,string> Problems.
     */
    public static function remove_all(): array {
        $errors       = [];
        $had_user_ini = strpos(self::read(self::user_ini_path()), self::BEGIN) !== false;
        foreach ([self::htaccess_path(), self::user_ini_path()] as $file) {
            if ($file === '') {
                continue;
            }
            if (!self::strip_block($file)) {
                /* translators: %s: file path. */
                $errors[] = sprintf(__('Couldn’t remove the Lockdown lines from %s.', 'shipstatic'), $file);
            } elseif (file_exists($file . '.shipstatic-backup')) {
                // The block is out, so the copy taken before it went in has done its job.
                wp_delete_file($file . '.shipstatic-backup');
            }
        }

        // PHP caches .user.ini per worker for up to user_ini.cache_ttl (5 min),
        // and a missing auto_prepend_file is a fatal error on every request. So
        // the gate becomes a harmless stub for a while instead of disappearing.
        $files = [self::mu_path()];
        if ($had_user_ini) {
            self::write(self::gate_path(), self::stub_source());
            wp_schedule_single_event(time() + self::STUB_SECONDS, self::CLEANUP_HOOK);
        } elseif (!self::is_fresh_stub()) {
            $files[] = self::gate_path();
        }
        foreach ($files as $file) {
            if (file_exists($file)) {
                wp_delete_file($file);
                if (file_exists($file)) {
                    /* translators: %s: file path. */
                    $errors[] = sprintf(__('Couldn’t delete %s.', 'shipstatic'), $file);
                }
            }
        }
        Settings::update(['written' => '']);

        return $errors;
    }

    /**
     * Delete the stub once PHP can no longer be prepending it (the scheduled
     * {@see CLEANUP_HOOK}; also safe to call any time).
     */
    public static function cleanup(): void {
        if (!Settings::enabled() && self::is_stub() && !self::is_fresh_stub()) {
            wp_delete_file(self::gate_path());
        }
    }

    /**
     * Whether the gate file is the stub.
     */
    private static function is_stub(): bool {
        return strpos(self::read(self::gate_path()), 'SHIPSTATIC_LOCKDOWN_STUB') !== false;
    }

    /**
     * Whether the gate file is a stub PHP may still be prepending.
     */
    private static function is_fresh_stub(): bool {
        return self::is_stub() && time() - (int) filemtime(self::gate_path()) < self::STUB_SECONDS;
    }

    /**
     * The stub: runs only the auto_prepend_file the gate had replaced.
     */
    private static function stub_source(): string {
        $chain = Settings::get()['prepend'];
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- renders a PHP literal for the generated stub.
        $path = var_export($chain !== '' && is_readable($chain) ? $chain : '', true);

        return "<?php\n"
            . "// SHIPSTATIC_LOCKDOWN_STUB — ShipStatic Lockdown is off. PHP may keep running this file for a few\n"
            . "// minutes after .user.ini changed; ShipStatic deletes it after 10 minutes.\n"
            . 'if (' . $path . " !== '' && is_readable(" . $path . ')) { include_once ' . $path . "; }\n";
    }

    /**
     * The state of each layer, for the dashboard.
     *
     * @return array<string,string> layer => ok|missing|notFirst|old|na
     */
    public static function layers(): array {
        $on    = Settings::enabled();
        $state = static fn(bool $applies, bool $ok): string => !$applies ? 'na' : ($ok ? 'ok' : 'missing');

        $htaccess = 'na';
        if ($on && self::uses_htaccess()) {
            $content  = ltrim(self::read(self::htaccess_path()));
            $at       = strpos($content, '# ' . self::BEGIN);
            $htaccess = $at === 0 ? 'ok' : ($at === false ? 'missing' : 'notFirst');
        }

        return [
            'gate'     => $state($on, is_readable(self::gate_path())),
            'mu'       => $state($on, is_readable(self::mu_path())),
            'htaccess' => $htaccess,
            'userIni'  => $state($on && self::uses_user_ini(), strpos(self::read(self::user_ini_path()), '; ' . self::BEGIN) !== false),
            'version'  => $on && Settings::get()['written'] !== self::VERSION ? 'old' : 'ok',
        ];
    }

    /**
     * The `.htaccess` file this site's requests read.
     */
    public static function htaccess_path(): string {
        return self::root() . '.htaccess';
    }

    /**
     * The `.user.ini` file, or '' when PHP reads none.
     */
    public static function user_ini_path(): string {
        $name = self::user_ini_name();

        return $name === '' ? '' : self::root() . $name;
    }

    /**
     * The gate file.
     */
    public static function gate_path(): string {
        return wp_normalize_path(WP_CONTENT_DIR) . '/shipstatic-lockdown.php';
    }

    /**
     * The mu-plugin file.
     */
    public static function mu_path(): string {
        return wp_normalize_path(WPMU_PLUGIN_DIR) . '/shipstatic-lockdown.php';
    }

    /**
     * The `.htaccess` block for the current settings.
     */
    public static function htaccess_block(): string {
        $token = PageRenderer::token();
        $key   = Settings::key();
        if (!preg_match('/^[A-Za-z0-9]+$/', $token) || !preg_match('/^[A-Za-z0-9]+$/', $key)) {
            throw new \RuntimeException('Unexpected characters in a Lockdown secret.');
        }

        $ips = array_map(
            static fn(string $cidr): string => 'RewriteCond %{REMOTE_ADDR} !' . Cidr::regex($cidr) . ' [NC]',
            Settings::cidrs()
        );

        $base = Settings::redirect_base();
        $rule = $base !== '' ? 'RewriteRule ^(.*)$ ' . $base . '$1? [R=302,L]' : 'RewriteRule ^ - [F,L]';

        return strtr(self::template('htaccess.txt'), [
            '__BS_TOKEN__'       => $token,
            '__BS_KEY__'         => $key,
            '__BS_COOKIE_NAME__' => Settings::COOKIE,
            '__BS_COOKIE__'      => Settings::cookie_value(),
            '__BS_IPS__'         => implode("\n", $ips),
            '__BS_RULE__'        => $rule,
        ]);
    }

    /**
     * An optional snippet for nginx's `server {}` block, for people who manage
     * their own server. It holds the list as it is now: a later change
     * (including an address added by the unlock link) needs it pasted again.
     */
    public static function nginx_snippet(): string {
        $lines = [
            '# ShipStatic Lockdown — the list as of ' . gmdate('Y-m-d H:i') . ' UTC. Paste again (and reload nginx) after changes.',
            'set $shipstatic_locked 1;',
        ];
        foreach (Settings::cidrs() as $cidr) {
            $lines[] = 'if ($remote_addr ~* "' . Cidr::regex($cidr) . '") { set $shipstatic_locked 0; }';
        }
        $lines[] = 'if ($arg_unlock = "' . Settings::key() . '") { set $shipstatic_locked 0; }';
        $lines[] = 'if ($cookie_' . Settings::COOKIE . ' = "' . Settings::cookie_value() . '") { set $shipstatic_locked 0; }';
        $lines[] = 'if ($http_x_bs_render_token = "' . PageRenderer::token() . '") { set $shipstatic_locked 0; }';
        $lines[] = 'if ($uri ~ "^/\.well-known/") { set $shipstatic_locked 0; }';

        $base    = Settings::redirect_base();
        $lines[] = $base !== ''
            ? 'if ($shipstatic_locked) { return 302 ' . untrailingslashit($base) . '$uri; }'
            : 'if ($shipstatic_locked) { return 403; }';

        return implode("\n", $lines) . "\n";
    }

    /**
     * The gate's source for the current settings.
     */
    private static function gate_source(): string {
        $ranges = [];
        foreach (Settings::cidrs() as $cidr) {
            [$net, $prefix] = explode('/', $cidr);
            $ranges[]       = [$net, (int) $prefix];
        }

        $config = [
            'token'      => hash('sha256', PageRenderer::token()),
            'key'        => hash('sha256', Settings::key()),
            'cookieName' => Settings::COOKIE,
            'cookie'     => hash('sha256', Settings::cookie_value()),
            'ranges'     => $ranges,
            'redirect'   => Settings::redirect_base(),
            'home'       => untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH)),
        ];

        return strtr(self::template('gate.php.txt'), [
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- renders PHP literals for the generated gate, not debug output.
            '__BS_CONFIG__' => var_export($config, true),
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- as above.
            '__BS_CHAIN__'  => var_export(self::chained_prepend(), true),
        ]);
    }

    /**
     * The mu-plugin's source.
     */
    private static function mu_source(): string {
        return strtr(self::template('mu-plugin.php.txt'), [
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- renders PHP literals for the generated mu-plugin.
            '__BS_BASENAME__' => var_export(SHIPSTATIC_PLUGIN_BASENAME, true),
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- as above.
            '__BS_GATE__'     => var_export(self::gate_path(), true),
        ]);
    }

    /**
     * The `.user.ini` block.
     */
    private static function user_ini_block(): string {
        $gate = self::gate_path();
        if (strpbrk($gate, "\"\r\n") !== false) {
            throw new \RuntimeException('The gate path can’t be written into .user.ini.');
        }

        return strtr(self::template('user-ini.txt'), ['__BS_GATE__' => $gate]);
    }

    /**
     * An auto_prepend_file the server already had (a firewall, for example),
     * which the gate runs after itself. Remembered the first time, so turning
     * Lockdown off and on again doesn't lose it once the gate has replaced it.
     */
    private static function chained_prepend(): string {
        $current = wp_normalize_path(self::environment()['prepend']);
        if ($current !== '' && $current !== self::gate_path() && is_readable($current) && strpbrk($current, "\"'\r\n") === false) {
            Settings::update(['prepend' => $current]);
            return $current;
        }

        $saved = Settings::get()['prepend'];

        return $saved !== '' && is_readable($saved) ? $saved : '';
    }

    /**
     * The site root (where WordPress's `.htaccess` lives).
     */
    private static function root(): string {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        return trailingslashit(wp_normalize_path(get_home_path()));
    }

    /**
     * The per-directory ini filename PHP reads ('' when switched off).
     */
    private static function user_ini_name(): string {
        $name = self::environment()['userIni'];

        return preg_match('/^[A-Za-z0-9._-]+$/', $name) ? $name : '';
    }

    /**
     * A template's text.
     *
     * @param string $name File in templates/.
     */
    private static function template(string $name): string {
        return (string) file_get_contents(__DIR__ . '/templates/' . $name);
    }

    /**
     * Put our block in a file, replacing any earlier one: first in the file
     * (`.htaccess`, so it runs before WordPress's and any cache's rules) or
     * last (`.user.ini`, where the last setting wins). The original file is
     * backed up once, before the first block is added.
     *
     * @param string $file  Path.
     * @param string $block Block text.
     * @param bool   $first Prepend instead of append.
     */
    private static function put_block(string $file, string $block, bool $first): bool {
        $content = self::read($file);
        if ($content !== '' && strpos($content, self::BEGIN) === false) {
            copy($file, $file . '.shipstatic-backup');
        }
        $rest  = trim(self::without_block($content));
        $block = rtrim($block) . "\n";
        $next  = $first ? $block . ($rest !== '' ? "\n" . $rest . "\n" : '') : ($rest !== '' ? $rest . "\n\n" : '') . $block;

        return $next === $content || self::write($file, $next);
    }

    /**
     * Remove our block from a file (deleting the file if nothing else is left).
     *
     * @param string $file Path.
     */
    private static function strip_block(string $file): bool {
        $content = self::read($file);
        if (strpos($content, self::BEGIN) === false) {
            return true;
        }
        $rest = trim(self::without_block($content));
        if ($rest === '') {
            wp_delete_file($file);
            return !file_exists($file);
        }

        return self::write($file, $rest . "\n");
    }

    /**
     * A file's text without our block.
     *
     * @param string $content File text.
     */
    private static function without_block(string $content): string {
        return (string) preg_replace('/^[#;] ' . preg_quote(self::BEGIN, '/') . '.*?^[#;] ' . preg_quote(self::END, '/') . '[^\n]*\n?/ms', '', $content);
    }

    /**
     * A file's text, or ''.
     *
     * @param string $file Path.
     */
    private static function read(string $file): string {
        return $file !== '' && is_readable($file) ? (string) file_get_contents($file) : '';
    }

    /**
     * Write a file atomically (temp file + rename), so a server never reads a
     * half-written `.htaccess` or gate.
     *
     * @param string $file    Path.
     * @param string $content Text.
     */
    private static function write(string $file, string $content): bool {
        $tmp = $file . '.shipstatic-tmp';
        if (file_put_contents($tmp, $content) === false) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace of a config file the web server reads on every request.
        if (!rename($tmp, $file)) {
            wp_delete_file($tmp);
            return false;
        }
        if (function_exists('opcache_invalidate') && substr($file, -4) === '.php') {
            opcache_invalidate($file, true);
        }

        return true;
    }
}
