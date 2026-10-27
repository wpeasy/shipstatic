<?php
/**
 * Link deploy replacer — rewrites <a>/<button> href targets at deploy time.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.4
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Render;

use WPEasy\ShipStatic\Settings\Destination;
use WPEasy\ShipStatic\Sync\DeployReplacer;
use WPEasy\ShipStatic\Sync\Runner;

defined('ABSPATH') || exit;

/**
 * Applies a destination's link swaps (fromHref => toHref), and points links to
 * site URLs that redirect to another site (link-tracking / short links, which
 * the static site can't serve) straight at their target. A swap set by hand
 * wins over a found redirect.
 */
final class LinkDeployReplacer implements DeployReplacer {

    /**
     * {@inheritDoc}
     */
    public function key(): string {
        return 'link';
    }

    /**
     * {@inheritDoc}
     */
    public function prepare(Destination $dest, string $dest_base): array {
        $links = $dest->link_replacements();
        $swaps = Runner::external_redirects(); // fromHref => toHref
        if (empty($links) && empty($swaps)) {
            return ['ctx' => null, 'files' => []];
        }

        foreach ($links as $l) {
            $swaps[$l['from']] = $l['to'];
        }

        return ['ctx' => $swaps, 'files' => []];
    }

    /**
     * {@inheritDoc}
     */
    public function apply(string $html, $ctx, string $relative): string {
        // Link replacements are global (whole-export), so the page path is ignored.
        return LinkReplacer::apply($html, $ctx);
    }

    /**
     * {@inheritDoc}
     */
    public function signature(Destination $dest) {
        // Same shape as before when nothing redirects, so updating doesn't
        // flip every destination out of sync.
        $redirects = Runner::external_redirects();

        return $redirects === [] ? $dest->link_replacements() : [$dest->link_replacements(), $redirects];
    }
}
