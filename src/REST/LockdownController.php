<?php
/**
 * Lockdown settings.
 *
 * @package WPEasy\ShipStatic
 * @since   2.3.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPEasy\ShipStatic\Lockdown\Cidr;
use WPEasy\ShipStatic\Lockdown\Guard;
use WPEasy\ShipStatic\Lockdown\Probe;
use WPEasy\ShipStatic\Lockdown\Settings;
use WPEasy\ShipStatic\Lockdown\Writer;

defined('ABSPATH') || exit;

/**
 * GET/POST /shipstatic/v1/lockdown, POST /lockdown/key, POST /lockdown/probe —
 * admin only (SECURITY_PATTERNS.md §0, §11).
 */
final class LockdownController {

    private const NS = 'shipstatic/v1';

    /**
     * Register the routes.
     */
    public static function register(): void {
        register_rest_route(self::NS, '/lockdown', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'get'],
                'permission_callback' => [self::class, 'can_manage'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'save'],
                'permission_callback' => [self::class, 'can_manage'],
            ],
        ]);
        register_rest_route(self::NS, '/lockdown/key', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'new_key'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/lockdown/probe', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'probe'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);
    }

    public static function can_manage(): bool {
        return current_user_can('manage_options');
    }

    /**
     * GET /lockdown.
     */
    public static function get(): WP_REST_Response {
        Writer::remember_environment();

        return new WP_REST_Response(self::payload());
    }

    /**
     * POST /lockdown — {enabled?, add?: {cidr, label}, remove?: cidr, redirect?, notify?}.
     * Any save rewrites the files, so an empty body repairs them.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function save(WP_REST_Request $request) {
        Writer::remember_environment();
        $params = (array) $request->get_json_params();

        if (isset($params['add']) && is_array($params['add'])) {
            $cidr = Settings::validate_cidr((string) ($params['add']['cidr'] ?? ''));
            if (is_wp_error($cidr)) {
                $cidr->add_data(['status' => 400]);
                return $cidr;
            }
            Settings::add_entry($cidr, (string) ($params['add']['label'] ?? ''), 'manual');
        }
        if (isset($params['remove'])) {
            Settings::remove_entry((string) $params['remove']);
        }
        if (isset($params['redirect'])) {
            Settings::update(['redirect' => sanitize_key((string) $params['redirect'])]);
        }
        if (isset($params['notify'])) {
            Settings::update(['notify' => !empty($params['notify'])]);
        }

        $turning_on = !empty($params['enabled']) && !Settings::enabled();
        if (isset($params['enabled'])) {
            if ($turning_on) {
                Settings::key();
                $ip   = Guard::remote_addr();
                $cidr = $ip !== '' ? Cidr::for_visitor($ip) : null;
                if ($cidr !== null && !Cidr::any(Settings::cidrs(), $ip)) {
                    Settings::add_entry($cidr, __('You (when Lockdown was turned on)', 'shipstatic'), 'enable');
                }
            }
            Settings::update(['enabled' => !empty($params['enabled'])]);
        }

        $errors = Writer::apply();
        if ($turning_on && !is_readable(Writer::gate_path())) {
            // Without the gate nothing is locked; don't claim it is.
            Settings::update(['enabled' => false]);
            Writer::remove_all();
        }
        if (Settings::enabled()) {
            // Keeps this browser in for 12 hours even if its address isn't matched.
            Guard::set_cookie();
        }

        return new WP_REST_Response(self::payload() + ['errors' => $errors]);
    }

    /**
     * POST /lockdown/key — a new unlock link (the old one and all cookies stop working).
     */
    public static function new_key(): WP_REST_Response {
        Writer::remember_environment();
        Settings::regenerate_key();
        $errors = Writer::apply();
        if (Settings::enabled()) {
            Guard::set_cookie();
        }

        return new WP_REST_Response(self::payload() + ['errors' => $errors]);
    }

    /**
     * POST /lockdown/probe.
     */
    public static function probe(): WP_REST_Response {
        return new WP_REST_Response(Settings::enabled() ? Probe::run() : ['result' => 'off', 'address' => '', 'message' => '']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function payload(): array {
        $settings = Settings::get();
        $ip       = Guard::remote_addr();
        $env      = Writer::environment();

        return [
            'enabled'        => $settings['enabled'],
            'entries'        => array_map(static fn(array $e): array => $e + ['display' => Cidr::display((string) $e['cidr'])], $settings['entries']),
            'redirect'       => $settings['redirect'],
            'redirectTarget' => Settings::redirect_base(),
            'notify'         => $settings['notify'],
            'link'           => Settings::unlock_url(),
            'checkUrl'       => home_url('/'),
            'yourIp'         => $ip,
            'yourIpAllowed'  => $ip !== '' && Cidr::any(Settings::cidrs(), $ip),
            'forwarded'      => Guard::forwarded_addr(),
            'server'         => $env['server'],
            'method'         => Writer::uses_htaccess() ? 'htaccess' : (Writer::uses_user_ini() ? 'userIni' : 'wordpress'),
            'layers'         => Writer::layers(),
            'nginxSnippet'   => $env['server'] === 'nginx' ? Writer::nginx_snippet() : '',
            'cronDisabled'   => Settings::cron_disabled(),
            'cronCommand'    => Settings::cron_command(),
        ];
    }
}
