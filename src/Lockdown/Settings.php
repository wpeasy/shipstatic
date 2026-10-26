<?php
/**
 * Lockdown settings.
 *
 * @package WPEasy\ShipStatic
 * @since   2.3.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Lockdown;

use WPEasy\ShipStatic\Settings\Destinations;

defined('ABSPATH') || exit;

/**
 * The `shipstatic_lockdown` option: on/off, the unlock key, the allowed
 * addresses and where blocked visitors are sent. See docs/LOCKDOWN_PLAN.md.
 */
final class Settings {

    public const OPTION = 'shipstatic_lockdown';

    /** Most allowed entries kept. */
    public const MAX_ENTRIES = 50;

    /** Unlock cookie name and lifetime (12 hours). */
    public const COOKIE          = 'shipstatic_unlock';
    public const COOKIE_LIFETIME = 43200;

    /** Redirect choices besides a destination id. */
    public const REDIRECT_AUTO = '';
    public const REDIRECT_NONE = 'none';

    /** How an entry was added. */
    private const VIA = ['enable', 'key', 'manual', 'cli'];

    /**
     * The stored settings, with defaults filled in.
     *
     * @return array{enabled:bool,key:string,entries:array<int,array{cidr:string,label:string,added:int,via:string}>,redirect:string,notify:bool,prepend:string,written:string}
     */
    public static function get(): array {
        $raw = get_option(self::OPTION, []);
        $raw = is_array($raw) ? $raw : [];

        $entries = [];
        foreach ((array) ($raw['entries'] ?? []) as $e) {
            $cidr = is_array($e) ? Cidr::parse((string) ($e['cidr'] ?? '')) : null;
            if ($cidr !== null) {
                $entries[] = [
                    'cidr'  => $cidr,
                    'label' => sanitize_text_field((string) ($e['label'] ?? '')),
                    'added' => (int) ($e['added'] ?? 0),
                    'via'   => in_array($e['via'] ?? '', self::VIA, true) ? (string) $e['via'] : 'manual',
                ];
            }
        }

        $key = (string) ($raw['key'] ?? '');

        return [
            'enabled'  => !empty($raw['enabled']),
            'key'      => preg_match('/^[A-Za-z0-9]{32}$/', $key) ? $key : '',
            'entries'  => $entries,
            'redirect' => sanitize_key((string) ($raw['redirect'] ?? self::REDIRECT_AUTO)),
            'notify'   => !array_key_exists('notify', $raw) || !empty($raw['notify']),
            'prepend'  => (string) ($raw['prepend'] ?? ''),
            'written'  => (string) ($raw['written'] ?? ''),
        ];
    }

    /**
     * Merge changes into the stored settings.
     *
     * @param array<string,mixed> $patch Keys of get().
     */
    public static function update(array $patch): void {
        update_option(self::OPTION, array_merge(self::get(), $patch), false);
    }

    /**
     * Whether Lockdown is on.
     */
    public static function enabled(): bool {
        return self::get()['enabled'];
    }

    /**
     * The unlock key, generated on first use.
     */
    public static function key(): string {
        $key = self::get()['key'];
        if ($key === '') {
            $key = self::regenerate_key();
        }

        return $key;
    }

    /**
     * Replace the unlock key. Invalidates the old link and every unlock cookie.
     */
    public static function regenerate_key(): string {
        $key = wp_generate_password(32, false, false);
        self::update(['key' => $key]);

        return $key;
    }

    /**
     * The unlock link editors bookmark.
     */
    public static function unlock_url(): string {
        return add_query_arg('unlock', self::key(), admin_url('/'));
    }

    /**
     * The unlock cookie's value: fixed for a key (`.htaccess` can only match a
     * literal), so a new key invalidates every cookie.
     */
    public static function cookie_value(): string {
        return hash_hmac('sha256', self::key(), wp_salt('auth'));
    }

    /**
     * Normalised ranges of every entry.
     *
     * @return array<int,string>
     */
    public static function cidrs(): array {
        return array_column(self::get()['entries'], 'cidr');
    }

    /**
     * Validate a range typed by a person.
     *
     * @param string $input Address or range.
     * @return string|\WP_Error Normalised range.
     */
    public static function validate_cidr(string $input) {
        $cidr = Cidr::parse($input);
        if ($cidr === null) {
            /* translators: %s: what was typed. */
            return new \WP_Error('shipstatic_lockdown_bad_ip', sprintf(__('“%s” is not an IP address or range.', 'shipstatic'), $input));
        }
        // .htaccess can only match IPv6 ranges on whole groups (see Cidr::regex()).
        if (strpos($cidr, ':') !== false && ((int) explode('/', $cidr)[1]) % 16 !== 0) {
            return new \WP_Error('shipstatic_lockdown_bad_ip', __('IPv6 ranges must end on a whole group: /16, /32, /48, /64 … /128.', 'shipstatic'));
        }

        return $cidr;
    }

    /**
     * Add an entry (no duplicates; an address already covered is left alone).
     *
     * @param string $cidr  Normalised range.
     * @param string $label Label.
     * @param string $via   One of VIA.
     * @return bool Whether it was added.
     */
    public static function add_entry(string $cidr, string $label, string $via): bool {
        $settings = self::get();
        foreach ($settings['entries'] as $e) {
            if ($e['cidr'] === $cidr) {
                return false;
            }
        }
        if (count($settings['entries']) >= self::MAX_ENTRIES) {
            array_shift($settings['entries']);
        }
        $settings['entries'][] = [
            'cidr'  => $cidr,
            'label' => sanitize_text_field($label),
            'added' => time(),
            'via'   => in_array($via, self::VIA, true) ? $via : 'manual',
        ];
        self::update(['entries' => $settings['entries']]);

        return true;
    }

    /**
     * Remove an entry.
     *
     * @param string $cidr Normalised range.
     */
    public static function remove_entry(string $cidr): bool {
        $entries = self::get()['entries'];
        $kept    = array_values(array_filter($entries, static fn(array $e): bool => $e['cidr'] !== $cidr));
        self::update(['entries' => $kept]);

        return count($kept) !== count($entries);
    }

    /**
     * Where blocked visitors are sent: a destination's URL with a trailing
     * slash, or '' for a 403. Rebuilt from its parts with a strict character
     * set, because it is written into `.htaccess` and the gate.
     */
    public static function redirect_base(): string {
        $choice = self::get()['redirect'];
        if ($choice === self::REDIRECT_NONE) {
            return '';
        }

        foreach (Destinations::visible_objects() as $dest) {
            if ($choice !== self::REDIRECT_AUTO && $dest->id() !== $choice) {
                continue;
            }
            if ($choice === self::REDIRECT_AUTO && !$dest->get('enabled')) {
                continue;
            }
            $base = self::safe_url(trim((string) $dest->get('destinationUrl')));
            if ($base !== '') {
                return $base;
            }
        }

        return '';
    }

    /**
     * Whether WP-Cron only runs from a server cron job (DISABLE_WP_CRON).
     */
    public static function cron_disabled(): bool {
        return defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    }

    /**
     * A server cron job command that runs WP-Cron with command-line PHP, which
     * Lockdown never stops. One that fetches wp-cron.php over the web (wget,
     * curl, a cron service) carries no render token and is turned away.
     */
    public static function cron_command(): string {
        return 'php ' . escapeshellarg(ABSPATH . 'wp-cron.php') . ' >/dev/null 2>&1';
    }

    /**
     * A server cron job command that fetches wp-cron.php over the web with
     * the unlock key as a bearer token, for hosts that only offer web cron.
     */
    public static function cron_web_command(): string {
        return 'wget -q -O /dev/null --header=' . escapeshellarg('Authorization: Bearer ' . self::key()) . ' ' . escapeshellarg(site_url('wp-cron.php'));
    }

    /**
     * A URL safe to write into server config, or ''.
     *
     * @param string $url Destination URL.
     */
    private static function safe_url(string $url): string {
        $parts  = wp_parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        $path   = (string) ($parts['path'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || !preg_match('/^[a-z0-9.-]+$/', $host) || !preg_match('#^[A-Za-z0-9._~/-]*$#', $path)) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port . '/' . ltrim(rtrim($path, '/') . '/', '/');
    }
}
