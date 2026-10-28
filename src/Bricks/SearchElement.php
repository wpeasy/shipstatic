<?php
/**
 * ShipStatic Search — a Bricks element.
 *
 * Loaded only through \Bricks\Elements::register_element() (see
 * Frontend\Search::register_bricks_element()), so \Bricks\Element exists.
 *
 * @package WPEasy\ShipStatic
 * @since   2.2.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Bricks;

use WPEasy\ShipStatic\Frontend\Search;
use WPEasy\ShipStatic\Search\IndexBuilder;
use WPEasy\ShipStatic\Search\SearchForm;

defined('ABSPATH') || exit;

/**
 * A search box for the static site, styleable part by part: label, field,
 * leading icon, input (+ focus), button (+ hover), and the results dropdown —
 * box, items (+ hover/active), title, snippet, highlight and empty state. The
 * builder shows a sample dropdown so it can be styled in place. Markup and
 * behaviour options come from Search\SearchForm; defaults from search.css.
 */
class SearchElement extends \Bricks\Element {

    /** @var string */
    public $category = 'shipstatic';
    /** @var string */
    public $name = 'shipstatic-search';
    /** @var string */
    public $icon = 'ti-search';
    /** @var string */
    public $css_selector = '.bs-ss';

    public function get_label() {
        return esc_html__('ShipStatic Search', 'shipstatic');
    }

    public function get_keywords() {
        return ['search', 'static', 'shipstatic', 'find'];
    }

    public function enqueue_scripts() {
        SearchForm::enqueue_style();
        Search::enqueue_live();
    }

    public function set_control_groups() {
        $groups = [
            'field'    => __('Field', 'shipstatic'),
            'input'    => __('Input', 'shipstatic'),
            'icon'     => __('Leading icon', 'shipstatic'),
            'button'   => __('Button', 'shipstatic'),
            'label'    => __('Label', 'shipstatic'),
            'dropdown' => __('Results box', 'shipstatic'),
            'results'  => __('Results', 'shipstatic'),
            'empty'    => __('No results', 'shipstatic'),
        ];
        foreach ($groups as $key => $title) {
            $this->control_groups[$key] = ['title' => esc_html($title), 'tab' => 'content'];
        }
    }

    /**
     * A CSS control entry list for one property on one or more selectors.
     *
     * @param string               $property CSS property.
     * @param array<int,string>    $selectors Selectors under the element root.
     * @return array<int,array<string,string>>
     */
    private static function css(string $property, array $selectors): array {
        return array_map(static fn(string $s): array => ['property' => $property, 'selector' => $s], $selectors);
    }

    public function set_controls() {
        // ── Behaviour (ungrouped, top) ────────────────────────────────
        $this->controls['info'] = [
            'type'    => 'info',
            'content' => esc_html__('On your static site this searches as you type. Turn on Site search in ShipStatic → Settings, then Process and Sync. On the live WordPress site it is a normal search form, unless “Use ShipStatic search on the WordPress site too” is on.', 'shipstatic'),
        ];
        if (!IndexBuilder::enabled()) {
            $this->controls['infoOff'] = [
                'type'    => 'info',
                'content' => esc_html__('Site search is currently off in ShipStatic → Settings.', 'shipstatic'),
            ];
        }
        $this->controls['placeholder'] = [
            'label'       => esc_html__('Placeholder', 'shipstatic'),
            'type'        => 'text',
            'inline'      => true,
            'placeholder' => esc_html__('Search…', 'shipstatic'),
        ];
        $this->controls['maxResults'] = [
            'label'       => esc_html__('Max results', 'shipstatic'),
            'type'        => 'number',
            'min'         => 1,
            'max'         => 20,
            'inline'      => true,
            'placeholder' => '8',
        ];
        $this->controls['minChars'] = [
            'label'       => esc_html__('Search after (characters)', 'shipstatic'),
            'type'        => 'number',
            'min'         => 1,
            'max'         => 5,
            'inline'      => true,
            'placeholder' => '1',
        ];
        $this->controls['hideSnippets'] = [
            'label' => esc_html__('Hide text snippets', 'shipstatic'),
            'type'  => 'checkbox',
        ];
        $this->controls['snippetLength'] = [
            'label'       => esc_html__('Snippet length (characters)', 'shipstatic'),
            'type'        => 'number',
            'min'         => 40,
            'max'         => 400,
            'inline'      => true,
            'placeholder' => '160',
            'required'    => ['hideSnippets', '!=', true],
        ];
        $this->controls['emptyText'] = [
            'label'       => esc_html__('No results text', 'shipstatic'),
            'type'        => 'text',
            /* translators: %s: what the visitor searched for. */
            'placeholder' => esc_html__('No results for “%s”', 'shipstatic'),
            /* translators: %s: the literal placeholder "%s", shown as typed. */
            'description' => esc_html__('%s is replaced by what was typed.', 'shipstatic'),
        ];
        $this->controls['hidePreview'] = [
            'label'       => esc_html__('Hide results preview in builder', 'shipstatic'),
            'type'        => 'checkbox',
            'description' => esc_html__('While this element is selected, the builder shows sample results so you can style them.', 'shipstatic'),
        ];

        // ── Field (box around icon, input and button) ─────────────────
        $field = ['.bs-ss__field'];
        $this->controls['fieldHeight'] = ['group' => 'field', 'label' => esc_html__('Min height', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('min-height', $field)];
        $this->controls['fieldGap'] = ['group' => 'field', 'label' => esc_html__('Gap', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('gap', $field)];
        $this->controls['fieldPadding'] = ['group' => 'field', 'label' => esc_html__('Padding', 'shipstatic'), 'type' => 'spacing', 'css' => self::css('padding', $field)];
        $this->controls['fieldBackground'] = ['group' => 'field', 'label' => esc_html__('Background', 'shipstatic'), 'type' => 'color', 'css' => self::css('background-color', $field)];
        $this->controls['fieldColor'] = ['group' => 'field', 'label' => esc_html__('Text / icon colour', 'shipstatic'), 'type' => 'color', 'css' => self::css('color', $field)];
        $this->controls['fieldBorder'] = ['group' => 'field', 'label' => esc_html__('Border', 'shipstatic'), 'type' => 'border', 'css' => self::css('border', $field)];
        $this->controls['fieldShadow'] = ['group' => 'field', 'label' => esc_html__('Box shadow', 'shipstatic'), 'type' => 'box-shadow', 'css' => self::css('box-shadow', $field)];
        $this->controls['fieldFocusSep'] = ['group' => 'field', 'label' => esc_html__('While typing (focus)', 'shipstatic'), 'type' => 'separator'];
        $focus = ['.bs-ss__field:focus-within'];
        $this->controls['fieldFocusBackground'] = ['group' => 'field', 'label' => esc_html__('Background', 'shipstatic'), 'type' => 'color', 'css' => self::css('background-color', $focus)];
        $this->controls['fieldFocusBorder'] = ['group' => 'field', 'label' => esc_html__('Border', 'shipstatic'), 'type' => 'border', 'css' => self::css('border', $focus)];
        $this->controls['fieldFocusShadow'] = ['group' => 'field', 'label' => esc_html__('Box shadow', 'shipstatic'), 'type' => 'box-shadow', 'css' => self::css('box-shadow', $focus)];

        // ── Input ─────────────────────────────────────────────────────
        $input = ['.bs-ss__input'];
        $this->controls['inputHeight'] = ['group' => 'input', 'label' => esc_html__('Height', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('height', $input)];
        $this->controls['inputPadding'] = ['group' => 'input', 'label' => esc_html__('Padding', 'shipstatic'), 'type' => 'spacing', 'css' => self::css('padding', $input)];
        $this->controls['inputTypography'] = ['group' => 'input', 'label' => esc_html__('Typography', 'shipstatic'), 'type' => 'typography', 'css' => self::css('font', $input)];
        $this->controls['placeholderColor'] = ['group' => 'input', 'label' => esc_html__('Placeholder colour', 'shipstatic'), 'type' => 'color', 'css' => self::css('color', ['.bs-ss__input::placeholder'])];
        $this->controls['inputBackground'] = ['group' => 'input', 'label' => esc_html__('Background', 'shipstatic'), 'type' => 'color', 'css' => self::css('background-color', $input)];
        $this->controls['inputBorder'] = ['group' => 'input', 'label' => esc_html__('Border', 'shipstatic'), 'type' => 'border', 'css' => self::css('border', $input)];

        // ── Leading icon ──────────────────────────────────────────────
        $this->controls['leadingIcon'] = ['group' => 'icon', 'label' => esc_html__('Icon', 'shipstatic'), 'type' => 'icon'];
        $icon = ['.bs-ss__icon'];
        $this->controls['leadingIconSize'] = ['group' => 'icon', 'label' => esc_html__('Size', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('font-size', $icon), 'required' => ['leadingIcon', '!=', '']];
        $this->controls['leadingIconColor'] = ['group' => 'icon', 'label' => esc_html__('Colour', 'shipstatic'), 'type' => 'color', 'css' => self::css('color', $icon), 'required' => ['leadingIcon', '!=', '']];

        // ── Button ────────────────────────────────────────────────────
        $this->controls['hideButton'] = ['group' => 'button', 'label' => esc_html__('Hide button', 'shipstatic'), 'type' => 'checkbox'];
        $show = ['hideButton', '!=', true];
        $this->controls['buttonText'] = ['group' => 'button', 'label' => esc_html__('Text', 'shipstatic'), 'type' => 'text', 'inline' => true, 'required' => $show];
        $this->controls['buttonIcon'] = ['group' => 'button', 'label' => esc_html__('Icon', 'shipstatic'), 'type' => 'icon', 'description' => esc_html__('A magnifying glass is used when there is no text or icon.', 'shipstatic'), 'required' => $show];
        $btn = ['.bs-ss__button'];
        $this->controls['buttonIconSize'] = ['group' => 'button', 'label' => esc_html__('Icon size', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('font-size', ['.bs-ss__button-icon']), 'required' => $show];
        $this->controls['buttonWidth'] = ['group' => 'button', 'label' => esc_html__('Min width', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('min-width', $btn), 'required' => $show];
        $this->controls['buttonHeight'] = ['group' => 'button', 'label' => esc_html__('Height', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('height', $btn), 'required' => $show];
        $this->controls['buttonPadding'] = ['group' => 'button', 'label' => esc_html__('Padding', 'shipstatic'), 'type' => 'spacing', 'css' => self::css('padding', $btn), 'required' => $show];
        $this->controls['buttonTypography'] = ['group' => 'button', 'label' => esc_html__('Typography', 'shipstatic'), 'type' => 'typography', 'css' => self::css('font', $btn), 'required' => $show];
        $this->controls['buttonBackground'] = ['group' => 'button', 'label' => esc_html__('Background', 'shipstatic'), 'type' => 'color', 'css' => self::css('background-color', $btn), 'required' => $show];
        $this->controls['buttonBorder'] = ['group' => 'button', 'label' => esc_html__('Border', 'shipstatic'), 'type' => 'border', 'css' => self::css('border', $btn), 'required' => $show];
        $this->controls['buttonShadow'] = ['group' => 'button', 'label' => esc_html__('Box shadow', 'shipstatic'), 'type' => 'box-shadow', 'css' => self::css('box-shadow', $btn), 'required' => $show];
        $this->controls['buttonHoverSep'] = ['group' => 'button', 'label' => esc_html__('Hover', 'shipstatic'), 'type' => 'separator', 'required' => $show];
        $hover = ['.bs-ss__button:hover', '.bs-ss__button:focus-visible'];
        $this->controls['buttonHoverBackground'] = ['group' => 'button', 'label' => esc_html__('Background', 'shipstatic'), 'type' => 'color', 'css' => self::css('background-color', $hover), 'required' => $show];
        $this->controls['buttonHoverColor'] = ['group' => 'button', 'label' => esc_html__('Text / icon colour', 'shipstatic'), 'type' => 'color', 'css' => self::css('color', $hover), 'required' => $show];

        // ── Label ─────────────────────────────────────────────────────
        $this->controls['labelText'] = ['group' => 'label', 'label' => esc_html__('Text', 'shipstatic'), 'type' => 'text', 'inline' => true, 'placeholder' => esc_html__('Search', 'shipstatic'), 'description' => esc_html__('Read by screen readers, and used as the button’s name.', 'shipstatic')];
        $this->controls['showLabel'] = ['group' => 'label', 'label' => esc_html__('Show label', 'shipstatic'), 'type' => 'checkbox'];
        $this->controls['labelTypography'] = ['group' => 'label', 'label' => esc_html__('Typography', 'shipstatic'), 'type' => 'typography', 'css' => self::css('font', ['.bs-ss__label']), 'required' => ['showLabel', '=', true]];
        $this->controls['labelGap'] = ['group' => 'label', 'label' => esc_html__('Gap to field', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('gap', ['.bs-ss']), 'required' => ['showLabel', '=', true]];

        // ── Results box ───────────────────────────────────────────────
        $box = ['.bs-search'];
        $this->controls['dropdownAlign'] = [
            'group'       => 'dropdown',
            'label'       => esc_html__('Align', 'shipstatic'),
            'type'        => 'select',
            'inline'      => true,
            'options'     => ['left' => esc_html__('Left', 'shipstatic'), 'right' => esc_html__('Right', 'shipstatic'), 'stretch' => esc_html__('Field width', 'shipstatic')],
            'placeholder' => esc_html__('Left', 'shipstatic'),
        ];
        $this->controls['dropdownWidth'] = ['group' => 'dropdown', 'label' => esc_html__('Width', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('width', $box)];
        $this->controls['dropdownMaxHeight'] = ['group' => 'dropdown', 'label' => esc_html__('Max height', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('max-height', $box)];
        $this->controls['dropdownOffset'] = ['group' => 'dropdown', 'label' => esc_html__('Distance from field', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('margin-top', $box)];
        $this->controls['dropdownPadding'] = ['group' => 'dropdown', 'label' => esc_html__('Padding', 'shipstatic'), 'type' => 'spacing', 'css' => self::css('padding', $box)];
        $this->controls['dropdownBackground'] = ['group' => 'dropdown', 'label' => esc_html__('Background', 'shipstatic'), 'type' => 'color', 'css' => self::css('background-color', $box)];
        $this->controls['dropdownColor'] = ['group' => 'dropdown', 'label' => esc_html__('Text colour', 'shipstatic'), 'type' => 'color', 'css' => self::css('color', $box)];
        $this->controls['dropdownBorder'] = ['group' => 'dropdown', 'label' => esc_html__('Border', 'shipstatic'), 'type' => 'border', 'css' => self::css('border', $box)];
        $this->controls['dropdownScrollbar'] = ['group' => 'dropdown', 'label' => esc_html__('Scrollbar colour', 'shipstatic'), 'type' => 'color', 'css' => self::css('--bs-search-scrollbar', $box)];
        $this->controls['dropdownShadow'] = ['group' => 'dropdown', 'label' => esc_html__('Box shadow', 'shipstatic'), 'type' => 'box-shadow', 'css' => self::css('box-shadow', $box)];
        $this->controls['dropdownZ'] = ['group' => 'dropdown', 'label' => esc_html__('Z-index', 'shipstatic'), 'type' => 'number', 'css' => self::css('z-index', $box), 'placeholder' => '9999'];

        // ── Results ───────────────────────────────────────────────────
        $item = ['.bs-search__item a'];
        $this->controls['itemGap'] = ['group' => 'results', 'label' => esc_html__('Space between results', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('margin-bottom', ['.bs-search__item:not(:last-child)'])];
        $this->controls['itemPadding'] = ['group' => 'results', 'label' => esc_html__('Result padding', 'shipstatic'), 'type' => 'spacing', 'css' => self::css('padding', $item)];
        $this->controls['itemBorder'] = ['group' => 'results', 'label' => esc_html__('Result border', 'shipstatic'), 'type' => 'border', 'css' => self::css('border', $item)];
        $this->controls['itemBackground'] = ['group' => 'results', 'label' => esc_html__('Result background', 'shipstatic'), 'type' => 'color', 'css' => self::css('background-color', $item)];
        $this->controls['itemActiveSep'] = ['group' => 'results', 'label' => esc_html__('Hover / selected with the keyboard', 'shipstatic'), 'type' => 'separator'];
        $active = ['.bs-search__item a:hover', '.bs-search__item--active a'];
        $this->controls['itemActiveBackground'] = ['group' => 'results', 'label' => esc_html__('Background', 'shipstatic'), 'type' => 'color', 'css' => self::css('background-color', $active)];
        $this->controls['itemActiveColor'] = ['group' => 'results', 'label' => esc_html__('Text colour', 'shipstatic'), 'type' => 'color', 'css' => self::css('color', $active)];
        $this->controls['itemActiveBorder'] = ['group' => 'results', 'label' => esc_html__('Border', 'shipstatic'), 'type' => 'border', 'css' => self::css('border', $active)];
        $this->controls['titleSep'] = ['group' => 'results', 'label' => esc_html__('Title', 'shipstatic'), 'type' => 'separator'];
        $this->controls['titleTypography'] = ['group' => 'results', 'label' => esc_html__('Typography', 'shipstatic'), 'type' => 'typography', 'css' => self::css('font', ['.bs-search__title'])];
        $this->controls['snippetSep'] = ['group' => 'results', 'label' => esc_html__('Snippet', 'shipstatic'), 'type' => 'separator'];
        $this->controls['snippetTypography'] = ['group' => 'results', 'label' => esc_html__('Typography', 'shipstatic'), 'type' => 'typography', 'css' => self::css('font', ['.bs-search__snippet'])];
        $this->controls['snippetGap'] = ['group' => 'results', 'label' => esc_html__('Gap below title', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('margin-top', ['.bs-search__snippet'])];
        $this->controls['markSep'] = ['group' => 'results', 'label' => esc_html__('Highlighted match', 'shipstatic'), 'type' => 'separator'];
        $this->controls['markBackground'] = ['group' => 'results', 'label' => esc_html__('Background', 'shipstatic'), 'type' => 'color', 'css' => self::css('background-color', ['.bs-search mark'])];
        $this->controls['markTypography'] = ['group' => 'results', 'label' => esc_html__('Typography', 'shipstatic'), 'type' => 'typography', 'css' => self::css('font', ['.bs-search mark'])];
        $this->controls['markPadding'] = ['group' => 'results', 'label' => esc_html__('Padding', 'shipstatic'), 'type' => 'spacing', 'css' => self::css('padding', ['.bs-search mark'])];
        $this->controls['markRadius'] = ['group' => 'results', 'label' => esc_html__('Border radius', 'shipstatic'), 'type' => 'number', 'units' => true, 'css' => self::css('border-radius', ['.bs-search mark'])];

        // ── No results ────────────────────────────────────────────────
        $empty = ['.bs-search__empty'];
        $this->controls['emptyTypography'] = ['group' => 'empty', 'label' => esc_html__('Typography', 'shipstatic'), 'type' => 'typography', 'css' => self::css('font', $empty)];
        $this->controls['emptyPadding'] = ['group' => 'empty', 'label' => esc_html__('Padding', 'shipstatic'), 'type' => 'spacing', 'css' => self::css('padding', $empty)];
    }

    public function render() {
        $s = $this->settings;

        $icon = static function ($value): string {
            if (empty($value)) {
                return '';
            }
            $html = self::render_icon($value);
            return is_string($html) ? $html : '';
        };

        $in_builder = (function_exists('bricks_is_builder') && bricks_is_builder())
            || (function_exists('bricks_is_builder_call') && bricks_is_builder_call());

        $options = [
            'id'          => 'bs-ss-' . $this->id,
            'placeholder' => isset($s['placeholder']) && $s['placeholder'] !== '' ? $this->render_dynamic_data($s['placeholder']) : __('Search…', 'shipstatic'),
            'label'       => isset($s['labelText']) && $s['labelText'] !== '' ? $this->render_dynamic_data($s['labelText']) : __('Search', 'shipstatic'),
            'showLabel'   => !empty($s['showLabel']),
            'button'      => empty($s['hideButton']),
            'buttonText'  => isset($s['buttonText']) ? $this->render_dynamic_data($s['buttonText']) : '',
            'buttonIcon'  => $icon($s['buttonIcon'] ?? null),
            'leadingIcon' => $icon($s['leadingIcon'] ?? null),
            'max'         => (int) ($s['maxResults'] ?? 8),
            'min'         => (int) ($s['minChars'] ?? 1),
            'snippets'    => empty($s['hideSnippets']),
            'snippetLen'  => (int) ($s['snippetLength'] ?? 160),
            'empty'       => (string) ($s['emptyText'] ?? ''),
            'align'       => (string) ($s['dropdownAlign'] ?? 'left'),
            'preview'     => $in_builder && empty($s['hidePreview']),
        ];

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() escapes; SearchForm::render() escapes every value.
        echo '<div ' . $this->render_attributes('_root') . '>' . SearchForm::render($options) . '</div>';
    }
}
