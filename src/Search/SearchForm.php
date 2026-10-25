<?php
/**
 * Markup for the ShipStatic search box (Bricks element + shortcode).
 *
 * @package WPEasy\ShipStatic
 * @since   2.2.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Search;

use WPEasy\ShipStatic\Frontend\Search;
use WPEasy\ShipStatic\Support\Assets;

defined('ABSPATH') || exit;

/**
 * Renders the search box. On the live WordPress site it is an ordinary search
 * form (submits to /?s=) unless LiveIndex is on. In the static export, src-svelte/search/main.ts
 * upgrades it to search the destination's index, reading its options from the
 * form's data-bs-* attributes.
 *
 * Class map (the Bricks element's style controls target these):
 *   .bs-ss                    the form
 *   .bs-ss__label             label (screen-reader only unless shown)
 *   .bs-ss__field             the box around icon + input + button
 *   .bs-ss__icon              leading icon
 *   .bs-ss__input             the input
 *   .bs-ss__button            submit button (.bs-ss__button-text / -icon)
 *   .bs-search                results dropdown (added by the script)
 *   .bs-search__item          one result (--active while highlighted)
 *   .bs-search__title / __snippet / mark / .bs-search__empty
 */
final class SearchForm {

    /** Stylesheet entry (Vite) shared by the element, shortcode and render. */
    public const CSS_ENTRY = 'src-svelte/search/search.css';

    /** Magnifying-glass icon used when no icon is chosen. */
    private const ICON_SEARCH = '<svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>';

    /**
     * Enqueue the search box / results stylesheet.
     */
    public static function enqueue_style(): void {
        $entry = Assets::entry(self::CSS_ENTRY);
        if ($entry !== null) {
            wp_enqueue_style('shipstatic-search', SHIPSTATIC_PLUGIN_URL . 'assets/dist/' . $entry['file'], [], SHIPSTATIC_VERSION);
        }
    }

    /**
     * Normalised options with defaults.
     *
     * @param array<string,mixed> $o Raw options.
     * @return array<string,mixed>
     */
    public static function options(array $o): array {
        return [
            'id'           => sanitize_html_class((string) ($o['id'] ?? '')) ?: 'bs-ss-' . wp_rand(1000, 999999),
            'placeholder'  => (string) ($o['placeholder'] ?? __('Search…', 'shipstatic')),
            'label'        => (string) ($o['label'] ?? __('Search', 'shipstatic')),
            'showLabel'    => !empty($o['showLabel']),
            'button'       => !array_key_exists('button', $o) || !empty($o['button']),
            'buttonText'   => (string) ($o['buttonText'] ?? ''),
            'buttonIcon'   => (string) ($o['buttonIcon'] ?? ''),
            'leadingIcon'  => (string) ($o['leadingIcon'] ?? ''),
            'max'          => max(1, min(20, (int) ($o['max'] ?? 8))),
            'min'          => max(1, min(5, (int) ($o['min'] ?? 1))),
            'snippets'     => !array_key_exists('snippets', $o) || !empty($o['snippets']),
            'snippetLen'   => max(40, min(400, (int) ($o['snippetLen'] ?? 160))),
            'empty'        => (string) ($o['empty'] ?? ''),
            'align'        => in_array($o['align'] ?? '', ['left', 'right', 'stretch'], true) ? (string) $o['align'] : 'left',
            'preview'      => !empty($o['preview']),
        ];
    }

    /**
     * The form markup.
     *
     * @param array<string,mixed> $o Options (see options()). Icon values are
     *                               trusted HTML from Bricks' render_icon().
     */
    public static function render(array $o): string {
        $o  = self::options($o);
        $id = $o['id'] . '-input';

        $data = [
            'data-bs-max'         => (string) $o['max'],
            'data-bs-min'         => (string) $o['min'],
            'data-bs-snippets'    => $o['snippets'] ? '1' : '0',
            'data-bs-snippet-len' => (string) $o['snippetLen'],
        ];
        if ($o['empty'] !== '') {
            $data['data-bs-empty'] = $o['empty'];
        }
        $attrs = '';
        foreach ($data as $k => $v) {
            $attrs .= ' ' . $k . '="' . esc_attr($v) . '"';
        }

        $html  = '<form class="bs-ss bs-ss--align-' . esc_attr($o['align']) . '" role="search" method="get" action="' . esc_url(home_url('/')) . '"' . $attrs . '>';
        $html .= '<label class="bs-ss__label' . ($o['showLabel'] ? '' : ' bs-ss__label--sr') . '" for="' . esc_attr($id) . '">' . esc_html($o['label']) . '</label>';
        $html .= '<div class="bs-ss__field">';
        if ($o['leadingIcon'] !== '') {
            $html .= '<span class="bs-ss__icon" aria-hidden="true">' . $o['leadingIcon'] . '</span>';
        }
        $html .= '<input class="bs-ss__input" type="search" name="s" id="' . esc_attr($id) . '" placeholder="' . esc_attr($o['placeholder']) . '" autocomplete="off" value="' . esc_attr(get_search_query()) . '">';
        if ($o['button']) {
            $html .= '<button class="bs-ss__button" type="submit"' . ($o['buttonText'] === '' ? ' aria-label="' . esc_attr($o['label']) . '"' : '') . '>';
            if ($o['buttonIcon'] !== '' || $o['buttonText'] === '') {
                $html .= '<span class="bs-ss__button-icon" aria-hidden="true">' . ($o['buttonIcon'] !== '' ? $o['buttonIcon'] : self::ICON_SEARCH) . '</span>';
            }
            if ($o['buttonText'] !== '') {
                $html .= '<span class="bs-ss__button-text">' . esc_html($o['buttonText']) . '</span>';
            }
            $html .= '</button>';
        }
        $html .= '</div>';
        if ($o['preview']) {
            $html .= self::preview_markup($o);
        }
        $html .= '</form>';

        return $html;
    }

    /**
     * A static sample of the results dropdown, shown in the builder so it can
     * be styled. Same classes the script uses on the live export.
     *
     * @param array<string,mixed> $o Normalised options.
     */
    private static function preview_markup(array $o): string {
        $word  = esc_html__('search', 'shipstatic');
        $items = [
            /* translators: %s: the highlighted search word. */
            [__('Getting started', 'shipstatic'), __('Everything you need to know to get your first %s results on the page.', 'shipstatic')],
            /* translators: %s: the highlighted search word. */
            [__('Pricing', 'shipstatic'), __('Plans for every size of site, with fast %s included.', 'shipstatic')],
            /* translators: %s: the highlighted search word. */
            [__('Contact us', 'shipstatic'), __('Can’t find what you’re looking for? Try a %s, or get in touch.', 'shipstatic')],
        ];

        $html = '<ul class="bs-search bs-search--preview" role="listbox" aria-label="' . esc_attr__('Search results (preview)', 'shipstatic') . '">';
        foreach (array_slice($items, 0, $o['max']) as $i => [$title, $snippet]) {
            $html .= '<li class="bs-search__item' . ($i === 1 ? ' bs-search__item--active' : '') . '" role="option" aria-selected="' . ($i === 1 ? 'true' : 'false') . '"><a href="#">';
            $html .= '<span class="bs-search__title">' . esc_html($title) . '</span>';
            if ($o['snippets']) {
                $html .= '<span class="bs-search__snippet">' . sprintf(esc_html($snippet), '<mark>' . $word . '</mark>') . '</span>';
            }
            $html .= '</a></li>';
        }
        /* translators: %s: what the visitor searched for. */
        $empty = $o['empty'] !== '' ? $o['empty'] : __('No results for “%s”', 'shipstatic');
        $html .= '<li class="bs-search__empty">' . esc_html(str_replace('%s', 'xyz', $empty)) . '</li>';
        $html .= '</ul>';

        return $html;
    }

    /**
     * [shipstatic_search placeholder="…" label="…" button_text="…" max="8"]
     *
     * @param array<string,string>|string $atts Shortcode attributes.
     */
    public static function shortcode($atts): string {
        $atts = shortcode_atts(
            ['placeholder' => __('Search…', 'shipstatic'), 'label' => __('Search', 'shipstatic'), 'button_text' => '', 'max' => '8', 'snippets' => '1'],
            is_array($atts) ? $atts : [],
            'shipstatic_search'
        );
        self::enqueue_style();
        Search::enqueue_live();

        return self::render([
            'placeholder' => $atts['placeholder'],
            'label'       => $atts['label'],
            'buttonText'  => $atts['button_text'],
            'max'         => (int) $atts['max'],
            'snippets'    => $atts['snippets'] !== '0',
        ]);
    }
}
