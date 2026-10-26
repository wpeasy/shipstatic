<?php
/**
 * Setting up a folder on this server as a destination.
 *
 * @package WPEasy\ShipStatic
 * @since   2.4.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\REST;

use WP_REST_Request;
use WP_REST_Response;
use WPEasy\ShipStatic\Deploy\ServerFolder;
use WPEasy\ShipStatic\Deploy\WordPressMove;
use WPEasy\ShipStatic\Settings\Destinations;
use WPEasy\ShipStatic\Sync\Runner;

defined('ABSPATH') || exit;

/**
 * POST /server-folder/check, POST /destinations/{id}/folder — admin only
 * (SECURITY_PATTERNS.md §0). Both share Deploy\ServerFolder with WP-CLI's
 * `wp shipstatic folder`.
 */
final class ServerFolderController {

    private const NS = 'shipstatic/v1';

    /**
     * Register the routes.
     */
    public static function register(): void {
        register_rest_route(self::NS, '/server-folder/check', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'check'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/wordpress-move', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'move'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/wordpress-move/undo', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'move_undo'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/wordpress-move/forget', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'move_forget'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/destinations/(?P<id>[A-Za-z0-9]+)/folder', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'setup'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);
    }

    public static function can_manage(): bool {
        return current_user_can('manage_options');
    }

    /**
     * POST /server-folder/check — {id?, path?}. No side effects: suggestions,
     * and what a path would mean.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function check(WP_REST_Request $request): WP_REST_Response {
        $params = (array) $request->get_json_params();
        $path   = trim((string) ($params['path'] ?? ''));
        $id     = preg_replace('/[^A-Za-z0-9]/', '', (string) ($params['id'] ?? ''));

        \WPEasy\ShipStatic\Lockdown\Writer::remember_environment();

        return new WP_REST_Response([
            'server'      => \WPEasy\ShipStatic\Lockdown\Writer::environment()['server'],
            'suggestions' => ServerFolder::suggestions(self::document_root()),
            'account'     => ServerFolder::account_root(),
            'wordpress'   => ServerFolder::wp_roots()[0] ?? '',
            'folder'      => $path !== '' ? ServerFolder::inspect($path, (string) $id) : null,
            // WordPress sits at the root of its domain: the wizard offers to move it into /wp.
            'move'        => self::at_root() ? WordPressMove::check(self::document_root()) : null,
            'moveStatus'  => WordPressMove::status(),
        ]);
    }

    /**
     * POST /wordpress-move — {id, confirm}. Moves WordPress into /wp; the
     * files move after this response.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function move(WP_REST_Request $request): WP_REST_Response {
        $params = (array) $request->get_json_params();
        if (empty($params['confirm'])) {
            return new WP_REST_Response(['ok' => false, 'message' => __('Confirm that you have a backup first.', 'shipstatic')], 400);
        }

        return new WP_REST_Response(WordPressMove::run(self::document_root(), preg_replace('/[^A-Za-z0-9]/', '', (string) ($params['id'] ?? '')) ?? ''));
    }

    /**
     * POST /wordpress-move/undo.
     */
    public static function move_undo(): WP_REST_Response {
        return new WP_REST_Response(WordPressMove::undo());
    }

    /**
     * POST /wordpress-move/forget — keep the move; stop offering to undo it.
     */
    public static function move_forget(): WP_REST_Response {
        WordPressMove::forget();

        return new WP_REST_Response(['ok' => true, 'moveStatus' => WordPressMove::status()]);
    }

    /**
     * Whether WordPress is installed at the root of the domain serving it.
     */
    private static function at_root(): bool {
        $doc = self::document_root();
        if ($doc === '' || trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/') !== '') {
            return false;
        }

        return rtrim((string) realpath($doc), '/') === rtrim((string) realpath(ABSPATH), '/');
    }

    /**
     * POST /destinations/{id}/folder — {path, url, adopt?, replaceIndex?}.
     * Creates the folder (or takes over one that holds the static site),
     * switches the destination to it, and tests it.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function setup(WP_REST_Request $request): WP_REST_Response {
        $id   = (string) $request['id'];
        $dest = Destinations::get($id);
        if ($dest === null) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Unknown destination.'], 404);
        }
        $params = (array) $request->get_json_params();
        $result = self::run(
            $id,
            trim((string) ($params['path'] ?? '')),
            esc_url_raw(trim((string) ($params['url'] ?? ''))),
            !empty($params['adopt']),
            !empty($params['replaceIndex'])
        );

        return new WP_REST_Response($result);
    }

    /**
     * Set up a folder destination (shared with WP-CLI).
     *
     * @param string $id            Destination id.
     * @param string $path          Folder path.
     * @param string $url           Destination URL.
     * @param bool   $adopt         The folder already holds this static site.
     * @param bool   $replace_index Replacing an existing index.html is fine.
     * @return array{ok:bool,message:string,needsConfirm?:bool,warnings?:array<int,string>,path?:string}
     */
    public static function run(string $id, string $path, string $url, bool $adopt, bool $replace_index): array {
        if ($url !== '' && !in_array(strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return ['ok' => false, 'message' => __('The address must start with https:// (or http://).', 'shipstatic')];
        }
        $info = ServerFolder::inspect($path, $id);
        if (!$info['ok']) {
            return ['ok' => false, 'message' => $info['error']];
        }
        if ($info['hasIndex'] && !$adopt && !$replace_index) {
            return ['ok' => false, 'needsConfirm' => true, 'message' => __('That folder already has an index.html. Confirm that your home page may replace it, or choose “It’s my static site already”.', 'shipstatic')];
        }

        try {
            $real = ServerFolder::prepare($path, $id);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $dest = Destinations::set_folder($id, $real, $url);
        if ($dest === null) {
            return ['ok' => false, 'message' => 'Unknown destination.'];
        }

        $warnings = [];
        $message  = __('Folder ready.', 'shipstatic');
        if ($adopt) {
            if (Runner::render_is_current()) {
                $r       = Runner::rebuild_pushed_record($id, ServerFolder::inventory($real, $id));
                $message = $r['inSync']
                    /* translators: %d: number of files. */
                    ? sprintf(__('Folder ready. All %d files of your site are already there and match, so it’s in sync.', 'shipstatic'), $r['matched'])
                    /* translators: 1: matching files, 2: files to write. */
                    : sprintf(__('Folder ready. %1$d files already match; %2$d will be written on the next Sync.', 'shipstatic'), $r['matched'], $r['total'] - $r['matched']);
            } else {
                $warnings[] = __('Process the pages first and set it up again to skip rewriting files that are already there. Otherwise the first Sync rewrites them (same content, nothing lost).', 'shipstatic');
            }
        }

        $problem = ServerFolder::probe($real, (string) $dest->get('destinationUrl'));
        if ($problem !== '') {
            $warnings[] = $problem;
        }
        $ok = true;
        update_option(DestinationsController::conn_state_option($id), ['ok' => $problem === '', 'time' => time(), 'message' => $problem === '' ? $message : $problem], false);

        return ['ok' => $ok, 'message' => $message, 'warnings' => $warnings, 'path' => $real];
    }

    /**
     * This web request's document root (the folder the domain serves).
     */
    private static function document_root(): string {
        $root = isset($_SERVER['DOCUMENT_ROOT']) ? sanitize_text_field(wp_unslash((string) $_SERVER['DOCUMENT_ROOT'])) : '';

        return $root;
    }
}
