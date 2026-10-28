<?php
/**
 * Flags page content that won't work on a static copy.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Render;

use WPEasy\ShipStatic\Bricks\Relay;
use WPEasy\ShipStatic\Search\IndexBuilder;

defined('ABSPATH') || exit;

/**
 * Scans rendered HTML for things that depend on PHP/WordPress at request time —
 * forms and dynamic links — which silently break once served as flat files.
 * Each finding carries the actual link so the user can see what was flagged.
 * Only same-origin targets are flagged: external links/actions (e.g. a
 * facebook.com/sharer.php share button, or a Mailchimp form) keep working on
 * the static copy because they hit a third-party server, not ours. WordPress-core
 * boilerplate (login, xmlrpc, RSD, feeds in <head>) is also ignored.
 */
final class CompatibilityScanner {

    /**
     * WP-core scripts that aren't user-facing functionality (ignored).
     */
    private const BOILERPLATE = '#/(wp-login|xmlrpc|wp-signup|wp-register|wp-comments-post|wp-cron|wp-trackback)\.php#i';

    /**
     * Return the distinct compatibility findings in a page's HTML.
     *
     * @param string $html Rendered HTML.
     * @return array<int,array{type:string,link:string}>
     */
    public static function scan(string $html): array {
        $found = [];
        $seen  = [];
        $hosts = UrlRewriter::source_hosts();

        // Forms (real <form> elements) posting back to our own site — those
        // submissions won't work statically. An external action keeps working.
        if (preg_match_all('#<form\b[^>]*>#i', $html, $forms)) {
            foreach ($forms[0] as $tag) {
                $action = preg_match('#\saction\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $tag, $m)
                    ? ($m[2] !== '' ? $m[2] : ($m[3] ?? ''))
                    : '';
                if (!self::is_internal($action, $hosts)) {
                    continue;
                }
                $type = preg_match('#\b(role="search"|class="[^"]*\bsearch)#i', $tag) ? 'search-form' : 'form';
                // With site search on, the export's search script handles it.
                if ($type === 'search-form' && IndexBuilder::enabled()) {
                    continue;
                }
                // A Bricks form posts through Bricks' script, which the export
                // points at WordPress (Bricks\Relay). Its password form has
                // an action (wp-login.php) and is still listed.
                if ($type === 'form' && $action === '' && Relay::enabled() && preg_match('#\bclass\s*=\s*"[^"]*\bbrxe-form\b#i', $tag)) {
                    continue;
                }
                self::add($found, $seen, $type, $action);
            }
        }

        // Anchor links to a .php script on our own site (external share links
        // like facebook.com/sharer.php still work; WP-core boilerplate ignored).
        if (preg_match_all('#<a\b[^>]*\shref\s*=\s*("([^"]*\.php\b[^"]*)"|\'([^\']*\.php\b[^\']*)\')#i', $html, $links, PREG_SET_ORDER)) {
            foreach ($links as $m) {
                $href = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
                if ($href !== '' && self::is_internal($href, $hosts) && !preg_match(self::BOILERPLATE, $href)) {
                    self::add($found, $seen, 'php-link', $href);
                }
            }
        }

        return $found;
    }

    /**
     * Whether a URL targets our own site (relative, root-relative, or a host in
     * the source-host list). Empty = self-submitting form, i.e. internal.
     *
     * @param string             $url   Href or form action.
     * @param array<int,string>  $hosts Source hostnames.
     */
    private static function is_internal(string $url, array $hosts): bool {
        $url = trim($url);
        if ($url === '' || $url[0] === '/' || $url[0] === '#' || $url[0] === '?') {
            return true;
        }
        $host = \wp_parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return true; // Relative path with no host (e.g. "submit.php").
        }
        return in_array(strtolower($host), array_map('strtolower', $hosts), true);
    }

    /**
     * Append a deduped finding.
     *
     * @param array<int,array{type:string,link:string}> $found Findings (by ref).
     * @param array<string,bool>                         $seen  Dedupe set (by ref).
     * @param string                                     $type  Finding type.
     * @param string                                     $link  Associated link.
     */
    private static function add(array &$found, array &$seen, string $type, string $link): void {
        $key = $type . '|' . $link;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key]    = true;
        $found[]       = ['type' => $type, 'link' => $link];
    }
}
