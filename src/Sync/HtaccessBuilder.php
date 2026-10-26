<?php
/**
 * Destination server config (.htaccess + nginx snippet).
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Sync;

defined('ABSPATH') || exit;

/**
 * Generates the destination web-server rules: serve pre-compressed `.gz` when
 * the client supports it, and apply sensible cache-control. Produces an Apache
 * `.htaccess` (uploaded automatically) and an equivalent nginx snippet (shown
 * for manual paste, since nginx config can't be applied over FTP/SFTP).
 */
final class HtaccessBuilder {

    /**
     * Long-cache asset extensions (immutable).
     */
    private const ASSET_EXT = 'css|js|mjs|jpg|jpeg|png|gif|webp|avif|svg|ico|woff|woff2|ttf|otf|eot|mp4|webm|ogg|mp3';

    /**
     * Marker name for our managed block.
     */
    private const MARKER = 'ShipStatic';

    /**
     * Merge our rules into an existing .htaccess: strip any previous Bricks
     * Static block and the WordPress rewrite block (which conflicts with static
     * serving), then prepend our fresh block. All other custom rules are kept.
     *
     * @param string            $existing Current remote .htaccess contents ('' if none).
     * @param array<int,string> $wp_dirs  WordPress folders below this one (a folder
     *                                    destination above WordPress): our cache
     *                                    rules must not reach them.
     */
    public static function merge(string $existing, array $wp_dirs = []): string {
        $existing = self::strip_block($existing, self::MARKER);
        $existing = self::strip_block($existing, 'WordPress');
        $existing = trim($existing);

        $block = self::block($wp_dirs);

        return $existing === '' ? $block . "\n" : $block . "\n\n" . $existing . "\n";
    }

    /**
     * Our managed block, wrapped in BEGIN/END markers for idempotent merging.
     *
     * @param array<int,string> $wp_dirs See merge().
     */
    public static function block(array $wp_dirs = []): string {
        return "# BEGIN " . self::MARKER . "\n" . self::rules($wp_dirs) . "\n# END " . self::MARKER . "\n";
    }

    /**
     * Build the standalone Apache .htaccess contents (our block only).
     */
    public static function htaccess(): string {
        return self::block();
    }

    /**
     * The long-cache asset extensions, as a list. Shared with `ServerProbe` so
     * the probe checks an extension the rules actually mark immutable.
     *
     * @return array<int,string>
     */
    public static function asset_extensions(): array {
        return explode('|', self::ASSET_EXT);
    }

    /**
     * Whether the given .htaccess content is one WE wrote (carries our marker).
     *
     * Used to tell an untouched third-party/host .htaccess — worth backing up
     * before we merge into it — apart from our own previous output, which is
     * regenerated every sync and has nothing worth preserving.
     *
     * @param string $content Current remote .htaccess contents.
     */
    public static function has_block(string $content): bool {
        return strpos($content, '# BEGIN ' . self::MARKER) !== false;
    }

    /**
     * Remove a "# BEGIN Name … # END Name" block from content.
     *
     * @param string $content Content.
     * @param string $name    Marker name.
     */
    private static function strip_block(string $content, string $name): string {
        $quoted  = preg_quote($name, '/');
        $pattern = '/\n*# BEGIN ' . $quoted . '\b.*?# END ' . $quoted . '[^\n]*\n?/is';

        return (string) (preg_replace($pattern, "\n", $content) ?? $content);
    }

    /**
     * Content types for the pre-compressed extensions, by extension. Any
     * compressible extension WITHOUT a type here would be served as an
     * undecodable .gz blob, so every Compressor extension must be covered.
     *
     * @return array<string,string>
     */
    private static function content_types(): array {
        return [
            'html' => 'text/html; charset=UTF-8',
            'htm'  => 'text/html; charset=UTF-8',
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'mjs'  => 'application/javascript',
            'svg'  => 'image/svg+xml',
            'json' => 'application/json',
            'map'  => 'application/json',
            'xml'  => 'application/xml',
            'txt'  => 'text/plain; charset=UTF-8',
        ];
    }

    /**
     * FilesMatch blocks that give every pre-compressed sibling the right
     * Content-Encoding + Content-Type. Built from the same extension list
     * Compressor gzips, so the two can't drift (a gzipped type with no header
     * here would serve as a broken binary blob — the sitemap/robots case).
     */
    private static function precompressed_headers(): string {
        $types  = self::content_types();
        $blocks = [];
        foreach (Compressor::extensions() as $ext) {
            if (!isset($types[$ext])) {
                continue;
            }
            $blocks[] = "    <FilesMatch \"\\.{$ext}\\.gz\$\">\n"
                . "        Header set Content-Encoding gzip\n"
                . "        Header set Content-Type \"{$types[$ext]}\"\n"
                . "        Header append Vary Accept-Encoding\n"
                . '    </FilesMatch>';
        }

        return implode("\n", $blocks);
    }

    /**
     * The rule body (without markers). The pre-compressed gzip rules are emitted
     * only when the host can actually produce .gz siblings.
     *
     * @param array<int,string> $wp_dirs See merge().
     */
    private static function rules(array $wp_dirs = []): string {
        $assets = self::ASSET_EXT;

        $rewrite = '';
        if (Compressor::available()) {
            $rewrite = self::template('htaccess-gzip.txt');
        }

        // Pre-compressed Content-Encoding/Type headers. Without them a .gz
        // sibling would be served as an undecodable binary blob.
        $precompressed = Compressor::available() ? "\n" . self::precompressed_headers() . "\n" : '';

        // `index.php` is listed AFTER index.html purely as a safety net: Apache
        // inherits .htaccess into every subdirectory, so a destination root that
        // happens to sit above a PHP app (a WordPress install in a subfolder or
        // subdomain docroot is a common layout) would otherwise lose its own
        // DirectoryIndex and 403. Static output never contains an index.php, so
        // index.html still wins wherever we actually deploy.
        // A folder destination above WordPress (one domain, WordPress in
        // /wp/): .htaccess applies to every folder below, so WordPress's own
        // CSS/JS would get a year-long immutable cache. Mark its requests and
        // keep the cache headers off them. (Our rewrite rules don't reach it:
        // WordPress's own .htaccess turns its rewrite engine on.)
        $skip = '';
        $env  = '';
        $dirs = array_values(array_filter($wp_dirs, static fn(string $d): bool => (bool) preg_match('/^[A-Za-z0-9._-]+$/', $d)));
        if ($dirs !== []) {
            $skip = "<IfModule mod_setenvif.c>\n    # WordPress lives below this folder: keep the cache rules off it.\n"
                . '    SetEnvIf Request_URI "^/(' . implode('|', array_map(static fn(string $d): string => preg_quote($d, '/'), $dirs)) . ')(/|$)" SHIPSTATIC_SKIP' . "\n</IfModule>\n";
            $env  = ' env=!SHIPSTATIC_SKIP';
        }

        return strtr(self::template('htaccess.txt'), [
            '__BS_SKIP__'          => $skip,
            '__BS_ENV__'           => $env,
            '__BS_REWRITE__'       => $rewrite,
            '__BS_PRECOMPRESSED__' => $precompressed,
            '__BS_ASSETS__'        => $assets,
        ]);
    }

    /**
     * Build an equivalent nginx server-block snippet (for manual paste).
     */
    public static function nginx(): string {
        $assets = self::ASSET_EXT;

        // Omit the gzip_static directive when the host can't produce .gz siblings.
        $gzip = Compressor::available()
            ? "\n# Serve pre-compressed .gz when available.\ngzip_static on;\n"
            : '';

        return strtr(self::template('nginx.conf.txt'), [
            '__BS_GZIP__'   => $gzip,
            '__BS_ASSETS__' => $assets,
        ]);
    }

    /**
     * A config template from `templates/` (plain text with `__BS_*__`
     * placeholders — kept out of PHP heredocs so it stays readable and lintable).
     *
     * @param string $name Template basename.
     */
    private static function template(string $name): string {
        return (string) file_get_contents(__DIR__ . '/templates/' . $name);
    }
}
