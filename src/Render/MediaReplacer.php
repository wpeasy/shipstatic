<?php
/**
 * Per-page media swapping in rendered HTML.
 *
 * @package WPEasy\ShipStatic
 * @since   1.1.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Render;

defined('ABSPATH') || exit;

/**
 * Swaps media in a rendered document given a prepared swap table (already filtered
 * to the page being processed). For <img> elements whose source matches a
 * replacement — including LAZY images, where Bricks keeps the real URL in
 * data-src/data-srcset and a placeholder in src — it points the image at the
 * replacement and regenerates the WHOLE srcset/data-srcset from the new image's
 * own variants, so the swap looks right at every screen width and never leaves (or
 * collapses) the original's responsive set. Intrinsic width/height are kept in
 * step too. Any remaining references (CSS backgrounds, video posters) are swapped
 * literally.
 */
final class MediaReplacer {

    /**
     * Apply media swaps to HTML.
     *
     * @param string                                                              $html  HTML.
     * @param array<string,array{to:string,srcset:string,width?:int,height?:int}> $swaps Original URL => {to, srcset, width, height}.
     * @return string
     */
    public static function apply(string $html, array $swaps): string {
        if (empty($swaps)) {
            return $html;
        }

        // Tag-aware <img> rewrite (handles lazy images). Match on src OR data-src;
        // rewrite whichever points at a swapped original, regenerate the entire
        // srcset/data-srcset from the new image (or drop it), and align width/height.
        $html = (string) preg_replace_callback(
            '#<img\b[^>]*>#i',
            static function (array $m) use ($swaps): string {
                $tag  = $m[0];
                $src  = TagAttr::get($tag, 'src');
                $data = TagAttr::get($tag, 'data-src');

                $swap = $swaps[$src] ?? $swaps[$data] ?? null;
                if ($swap === null) {
                    return $tag;
                }

                if ($src !== '' && isset($swaps[$src])) {
                    $tag = TagAttr::set($tag, 'src', $swap['to']);
                }
                if ($data !== '' && isset($swaps[$data])) {
                    $tag = TagAttr::set($tag, 'data-src', $swap['to']);
                }

                // Regenerate (or drop) every responsive set — real and lazy.
                foreach (['srcset', 'data-srcset'] as $attr) {
                    if (TagAttr::get($tag, $attr) === '') {
                        continue;
                    }
                    $tag = $swap['srcset'] !== ''
                        ? TagAttr::set($tag, $attr, $swap['srcset'])
                        : TagAttr::remove($tag, $attr);
                }

                // Keep intrinsic dimensions in step so a cross-aspect swap isn't
                // stretched by width/height attributes (only when known + present).
                if ((int) ($swap['width'] ?? 0) > 0 && TagAttr::get($tag, 'width') !== '') {
                    $tag = TagAttr::set($tag, 'width', (string) (int) $swap['width']);
                }
                if ((int) ($swap['height'] ?? 0) > 0 && TagAttr::get($tag, 'height') !== '') {
                    $tag = TagAttr::set($tag, 'height', (string) (int) $swap['height']);
                }

                return $tag;
            },
            $html
        );

        // Literal swap for any non-<img> occurrences (CSS backgrounds, posters).
        // Safe now: matched <img> sets already hold the new URLs, so the only
        // remaining originals are single-URL references that can't collapse.
        $froms = array_keys($swaps);
        $tos   = array_map(static fn(array $s): string => $s['to'], $swaps);

        return str_replace($froms, $tos, $html);
    }
}
