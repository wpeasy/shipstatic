<?php
/**
 * Bricks forms, query filters and AJAX loops on the static site: their
 * requests go to WordPress.
 *
 * @package WPEasy\ShipStatic
 * @since   2.4.9
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Bricks;

use WPEasy\ShipStatic\Deploy\PackageDeployer;
use WPEasy\ShipStatic\Settings\Destination;
use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Support\Url;

defined('ABSPATH') || exit;

/**
 * Bricks' dynamic features call two addresses from the page's `bricksData`:
 *
 * - `ajaxUrl` (admin-ajax.php): form submissions (`bricks_form_submit`) and
 *   fresh nonces (`bricks_regenerate_form_nonce`,
 *   `bricks_regenerate_query_nonce`) — Bricks asks for one on an
 *   `invalid_nonce` / `rest_cookie_invalid_nonce` reply and retries, so the
 *   always-stale nonce in an exported page heals itself;
 * - `restApiUrl` (`/wp-json/bricks/v1/`): query filters, sorting, live
 *   search, AJAX pagination and load more (`query_result`), infinite scroll
 *   (`load_query_page`) and AJAX popups (`load_popup_content`).
 *
 * The render rewrites both onto the static site, where they don't exist.
 * RelayReplacer points `ajaxUrl` back at WordPress and `restApiUrl` at
 * {@see \WPEasy\ShipStatic\REST\BricksRelayController}, which hands the request
 * to Bricks and rewrites the HTML in the reply for that destination (links,
 * images, its replacements). This class holds the setting and the WordPress
 * side:
 *
 * - lets a destination on another domain read admin-ajax.php's reply to
 *   these actions only, without credentials (cors_for_destination(); the
 *   REST API sends its own CORS headers);
 * - maps a form's Redirect action to the same page on the destination the
 *   form was sent from;
 * - gives Lockdown the exact addresses and actions to let through
 *   (SECURITY_PATTERNS.md §11).
 */
final class Relay {

    /** '1' / '0'; absent = on. */
    public const OPTION = 'shipstatic_bricks_relay';

    /** '1' / '0'; absent = off. Filter results keep their uploads on WordPress instead of the static site. */
    public const IMAGES_OPTION = 'shipstatic_relay_wp_images';

    /** Stands in for the uploads address while a relay reply is rewritten (see protect_uploads()). */
    private const UPLOADS_MARK = '__SHIPSTATIC_WPUP__';

    /** Image types the pass-through lets through Lockdown. Never svg (it can carry script) or any executable. */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /** The admin-ajax actions the static pages use. */
    private const ACTIONS = ['bricks_form_submit', 'bricks_regenerate_form_nonce', 'bricks_regenerate_query_nonce'];

    /** The Bricks REST endpoints the relay passes on (`/bricks/v1/<endpoint>`). */
    public const ENDPOINTS = ['query_result', 'load_query_page', 'load_popup_content'];

    /** The relay route below `shipstatic/v1` (destination id, then the endpoint). */
    public const ROUTE = 'bricks';

    /** What a destination id looks like in the relay's path. */
    public const DEST_ID = '[a-z0-9]{6,32}';

    /**
     * Hook in (always: the hooks themselves check the setting).
     */
    public static function init(): void {
        // admin_init runs in admin-ajax.php before the action is dispatched.
        add_action('admin_init', [self::class, 'cors_for_destination'], 0);
        add_filter('bricks/form/response', [self::class, 'map_redirect'], 20);
    }

    /**
     * Whether the static pages' Bricks forms, filters and AJAX loops use WordPress.
     */
    public static function enabled(): bool {
        return get_option(self::OPTION, '1') !== '0';
    }

    /**
     * Turn it on or off.
     *
     * @param bool $on On.
     */
    public static function set_enabled(bool $on): void {
        update_option(self::OPTION, $on ? '1' : '0', false);
    }

    /**
     * Whether filter results served through WordPress keep their images (the
     * uploads folder) on WordPress instead of pointing at the static site: an
     * image no export has uploaded yet then still shows. Needs the relay, and
     * Lockdown lets those image files through (Lockdown\Writer).
     */
    public static function images_from_wordpress(): bool {
        return self::enabled() && get_option(self::IMAGES_OPTION, '0') === '1';
    }

    /**
     * Turn the image pass-through on or off.
     *
     * @param bool $on On.
     */
    public static function set_images_from_wordpress(bool $on): void {
        update_option(self::IMAGES_OPTION, $on ? '1' : '0', false);
    }

    /**
     * The uploads folder's address and its path on this site ("/wp-content/uploads"),
     * or null when the path has characters we would not write into a server rule.
     *
     * @return array{url:string,path:string}|null
     */
    public static function uploads(): ?array {
        $dir  = wp_upload_dir(null, false);
        $url  = untrailingslashit((string) ($dir['baseurl'] ?? ''));
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if ($url === '' || !preg_match('#^/[A-Za-z0-9._~/-]+$#', $path) || strpos($path, '..') !== false) {
            return null;
        }

        return ['url' => $url, 'path' => $path];
    }

    /**
     * Hide the uploads address in markup/CSS so rewriting for a destination
     * leaves it alone: the pass-through keeps images where they are.
     *
     * @param string $value Markup or CSS from a relay reply.
     */
    public static function protect_uploads(string $value): string {
        $uploads = self::uploads();

        return $uploads === null ? $value : str_replace($uploads['url'] . '/', self::UPLOADS_MARK . '/', $value);
    }

    /**
     * Put the uploads address back (see protect_uploads()).
     *
     * @param string $value Rewritten markup or CSS.
     */
    public static function restore_uploads(string $value): string {
        $uploads = self::uploads();

        return $uploads === null ? $value : str_replace(self::UPLOADS_MARK . '/', $uploads['url'] . '/', $value);
    }

    /**
     * The admin-ajax actions let through Lockdown. Filterable
     * (`shipstatic_form_actions`) for another plugin's forms; only names made of
     * lowercase letters, digits, `_` and `-` are kept, since they are written
     * into the Lockdown gate.
     *
     * @return array<int,string>
     */
    public static function actions(): array {
        $actions = (array) apply_filters('shipstatic_form_actions', self::ACTIONS);
        $actions = array_filter($actions, static fn($a): bool => is_string($a) && preg_match('/^[a-z0-9_-]{1,64}$/', $a) === 1);

        return array_values(array_unique($actions));
    }

    /**
     * WordPress's admin-ajax.php URL.
     */
    public static function endpoint(): string {
        return admin_url('admin-ajax.php');
    }

    /**
     * The endpoint's URL path (`/wp/wp-admin/admin-ajax.php`).
     */
    public static function endpoint_path(): string {
        return (string) wp_parse_url(self::endpoint(), PHP_URL_PATH);
    }

    /**
     * The relay's base URL for a destination (Bricks appends the endpoint
     * name), or '' when the REST API has no pretty address here (plain
     * permalinks put the route in the query string, which Bricks' "base +
     * endpoint" can't extend, and which Lockdown can't match exactly).
     *
     * @param string $dest_id Destination id.
     */
    public static function rest_base(string $dest_id): string {
        if (!preg_match('/^' . self::DEST_ID . '$/', $dest_id)) {
            return '';
        }
        $url = rest_url('shipstatic/v1/' . self::ROUTE . '/' . $dest_id . '/');

        return strpos($url, '?') === false ? $url : '';
    }

    /**
     * The URL path every relay request starts with (`/wp/wp-json/shipstatic/v1/bricks/`),
     * or '' without pretty REST addresses.
     */
    public static function rest_prefix(): string {
        $url = rest_url('shipstatic/v1/' . self::ROUTE . '/');

        return strpos($url, '?') === false ? (string) wp_parse_url($url, PHP_URL_PATH) : '';
    }

    /**
     * A URL as a destination's pages should call it: a root-relative path
     * when the destination is on WordPress's own origin (both `http://` and
     * `https://` work then), else the full URL.
     *
     * @param string $url       WordPress URL.
     * @param string $dest_base The destination's public base URL ('' if unknown).
     */
    public static function url_for(string $url, string $dest_base): string {
        $origin = self::origin($url);
        if ($origin === '' || $origin !== self::origin($dest_base)) {
            return $url;
        }
        $parts = wp_parse_url($url);

        return (string) ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Let a destination on another domain read the reply to one of
     * actions() — and nothing else. Not through `allowed_http_origins`: core
     * then sends `Access-Control-Allow-Credentials: true` for every admin-ajax
     * action, so a script on the static site could read a logged-in editor's
     * nonces (Heartbeat hands out a fresh REST nonce) and act as them. Bricks
     * sends these requests without credentials, so none are allowed here.
     */
    public static function cors_for_destination(): void {
        if (!self::enabled() || !wp_doing_ajax() || headers_sent()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks whether to add a CORS header; Bricks checks its own nonce.
        $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        $origin = strtolower((string) get_http_origin());
        if ($origin === '' || !in_array($action, self::actions(), true)) {
            return;
        }
        foreach (self::destination_bases() as $base) {
            if (self::origin($base) === $origin) {
                header('Access-Control-Allow-Origin: ' . self::origin($base));
                header('Vary: Origin', false);
                return;
            }
        }
    }

    /**
     * `bricks/form/response`: a Redirect action's address on WordPress becomes
     * the same page on the destination the form was sent from (Bricks posts
     * the page's address as `referrer`). Anything else is left alone.
     *
     * @param mixed $response Response data.
     * @return mixed
     */
    public static function map_redirect($response) {
        if (!self::enabled() || !is_array($response) || empty($response['redirectTo']) || !is_string($response['redirectTo'])) {
            return $response;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Bricks verified its form nonce before this filter; the value only picks one of the configured destinations.
        $referrer = isset($_POST['referrer']) && is_string($_POST['referrer']) ? esc_url_raw(wp_unslash($_POST['referrer'])) : '';
        $base     = self::destination_for($referrer);
        $to       = $response['redirectTo'];
        if ($base === '' || !Url::is_internal($to) || !Url::in_home($to)) {
            return $response;
        }

        $parts = wp_parse_url($to);
        if (!is_array($parts)) {
            return $response;
        }
        $path = Url::remap_site_path((string) ($parts['path'] ?? '/'));
        $url  = untrailingslashit($base) . $path;
        if (isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }
        $response['redirectTo'] = $url;

        return $response;
    }

    /**
     * A destination by id, when the relay may serve it.
     *
     * @param string $dest_id Destination id.
     */
    public static function destination(string $dest_id): ?Destination {
        if (!self::enabled() || !preg_match('/^' . self::DEST_ID . '$/', $dest_id)) {
            return null;
        }
        foreach (Destinations::visible_objects() as $dest) {
            if ($dest->id() === $dest_id) {
                return $dest;
            }
        }

        return null;
    }

    /**
     * The destination base URL a page address belongs to ('' for none): the
     * longest base it starts with, at a path boundary.
     *
     * @param string $url Page address.
     */
    private static function destination_for(string $url): string {
        if ($url === '') {
            return '';
        }
        $best = '';
        foreach (self::destination_bases() as $base) {
            $prefix = untrailingslashit($base);
            $next   = substr($url, strlen($prefix), 1);
            if (strpos($url, $prefix) === 0 && in_array($next, ['', '/', '?', '#'], true) && strlen($prefix) > strlen($best)) {
                $best = $prefix;
            }
        }

        return $best;
    }

    /**
     * Every destination's base URL — switched-off ones too: what they
     * published earlier is still online, forms included.
     *
     * @return array<int,string>
     */
    private static function destination_bases(): array {
        $out = [];
        foreach (Destinations::visible_objects() as $dest) {
            $base = PackageDeployer::base_url($dest);
            if (preg_match('#^https?://#i', $base)) {
                $out[] = $base;
            }
        }

        return $out;
    }

    /**
     * A URL's origin (`https://host[:port]`, lowercase), or ''.
     *
     * @param string $url URL.
     */
    private static function origin(string $url): string {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return '';
        }

        return strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}
