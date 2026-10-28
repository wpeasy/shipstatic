<?php
/**
 * What "Process changes" renders.
 *
 * @package WPEasy\ShipStatic
 * @since   2.3.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Sync;

use WPEasy\ShipStatic\Discovery\UrlCollector;
use WPEasy\ShipStatic\Search\LiveIndex;
use WPEasy\ShipStatic\Support\Text;
use WPEasy\ShipStatic\Support\Url;

defined('ABSPATH') || exit;

/**
 * Turns the posts ChangeTracker recorded into a partial render: the changed
 * posts' own pages, the listing pages that show them (blog/home, their term
 * archives, their post-type archive) and — found by the crawl — any new pages
 * those link to; plus the pages to drop because their post was unpublished,
 * deleted, excluded or moved to a new URL.
 *
 * Deliberately limited to KNOWN changes (Docs: "Process changes"): anything
 * that shows on every page — menus, Bricks templates and settings, widgets —
 * or a page that merely lists posts elsewhere (a "related posts" block) is not
 * picked up. Process all is the safe option.
 */
final class ChangedContent {

    /**
     * The partial render plan.
     *
     * `unlinked`: new published pages it can't add on their own — under "Only
     * linked pages" a page joins the export only when an exported page links
     * to it, so the dashboard asks for a link first instead of offering a run
     * that does nothing.
     *
     * @return array{urls:array<int,string>,remove:array<int,string>,ids:array<int,int>,unlinked:array<int,string>}
     */
    public static function plan(): array {
        $ids    = ChangeTracker::changed_ids();
        $render = Manifest::load(Manifest::RENDER_OPTION);
        $mode   = UrlCollector::mode();
        $types  = UrlCollector::public_post_types();

        // Which rendered pages belong to which post (recorded at render time).
        $pages_of = [];
        $objects  = get_option(LiveIndex::OBJECTS_OPTION, []);
        foreach (is_array($objects) ? $objects : [] as $relative => $object) {
            if (preg_match('/^post:(\d+)$/', (string) $object, $m)) {
                $pages_of[(int) $m[1]][] = (string) $relative;
            }
        }

        $urls     = [];
        $remove   = [];
        $unlinked = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            $old  = $pages_of[$id] ?? [];

            $public = $post instanceof \WP_Post
                && $post->post_status === 'publish'
                && in_array($post->post_type, $types, true)
                && ($mode !== 'manual' || UrlCollector::is_included($id));
            if (!$public) {
                // Unpublished, deleted, made private or excluded: drop its pages,
                // and refresh the lists it appeared in.
                $remove = array_merge($remove, $old);
                if ($post instanceof \WP_Post) {
                    $urls = array_merge($urls, self::listings($post, $render));
                }
                continue;
            }

            $link     = (string) get_permalink($id);
            $relative = Url::to_relative_path($link);
            if ($relative === null) {
                continue;
            }
            // A new URL (slug change): the old page goes.
            $remove = array_merge($remove, array_diff($old, [$relative]));

            // In the export already, or belongs there under this mode. (Linked
            // mode: a new page joins only when a re-rendered page links to it —
            // the crawl follows links to pages not yet rendered.)
            $lists = self::listings($post, $render);
            if (isset($render[$relative]) || $mode === 'all' || $mode === 'manual') {
                $urls[] = $link;
            } elseif ($old === [] && $lists === []) {
                $unlinked[] = Text::title($post);
            }
            $urls = array_merge($urls, $lists);
        }

        return [
            'urls'     => array_values(array_unique($urls)),
            'remove'   => array_values(array_unique($remove)),
            'ids'      => $ids,
            'unlinked' => $unlinked,
        ];
    }

    /**
     * How many recorded changes Process changes would actually act on (0 when
     * none would render or remove anything — e.g. only an unlinked new page).
     *
     * @param array{urls:array<int,string>,remove:array<int,string>,ids:array<int,int>,unlinked:array<int,string>} $plan From plan().
     */
    public static function actionable(array $plan): int {
        return ($plan['urls'] === [] && $plan['remove'] === []) ? 0 : max(1, count($plan['ids']) - count($plan['unlinked']));
    }

    /**
     * Listing pages already in the export that show this post: the blog page
     * (or a posts front page), its post-type archive and its term archives.
     *
     * @param \WP_Post                      $post   Post.
     * @param array<string,array<string,mixed>> $render Render manifest.
     * @return array<int,string> URLs.
     */
    private static function listings(\WP_Post $post, array $render): array {
        $candidates = [];
        if ($post->post_type === 'post') {
            $blog         = (int) get_option('page_for_posts');
            $candidates[] = $blog > 0 ? (string) get_permalink($blog) : (get_option('show_on_front') === 'posts' ? home_url('/') : '');
        }
        $archive = get_post_type_archive_link($post->post_type);
        if (is_string($archive)) {
            $candidates[] = $archive;
        }
        foreach (get_object_taxonomies($post->post_type, 'objects') as $taxonomy) {
            if (empty($taxonomy->public)) {
                continue;
            }
            $terms = get_the_terms($post, $taxonomy->name);
            foreach (is_array($terms) ? $terms : [] as $term) {
                $link = get_term_link($term);
                if (is_string($link)) {
                    $candidates[] = $link;
                }
            }
        }

        $out = [];
        foreach ($candidates as $url) {
            $relative = $url !== '' ? Url::to_relative_path($url) : null;
            if ($relative !== null && isset($render[$relative])) {
                $out[] = $url;
            }
        }

        return $out;
    }
}
