<?php
// Spike: export the data a static filter engine needs for ONE Bricks query on ONE page.
// Usage: wp eval-file export.php <page-slug> <query-id> <out.json>
require_once __DIR__ . '/common.php';
function spike_export($args) {
	[$slug, $qid, $out] = $args;
	$c = spike_context($slug, $qid); $vars = $c['vars'];
	$per_page = (int) ($vars['posts_per_page'] ?? 10);
	$pop = array_merge($vars, ['paged' => 1, 'posts_per_page' => -1, 'nopaging' => true, 'fields' => 'ids', 'no_found_rows' => true]);
	unset($pop['_bricks_query_signature']);
	$order = array_map('intval', (new WP_Query($pop))->posts);
	$in_pop = array_flip($order);
	$posts = [];
	foreach ($order as $id) {
		$p = get_post($id);
		$posts[$id] = ['title' => $p->post_title, 'date' => $p->post_date, 'content' => $p->post_content, 'excerpt' => $p->post_excerpt, 'author' => (int) $p->post_author, 'menu' => (int) $p->menu_order];
	}
	global $wpdb; $t = $wpdb->prefix . 'bricks_filters_index';
	$filters = []; $rows = [];
	foreach ($c['filters'] as $fid => $e) {
		$s = $e['settings'];
		$f = ['id' => $fid, 'element' => $e['name'], 'action' => $s['filterAction'] ?? 'filter', 'source' => $s['filterSource'] ?? '', 'nice' => $s['filterNiceName'] ?? '',
			'logic' => strtoupper($s['filterMultiLogic'] ?? 'OR'), 'hierarchy' => !empty($s['filterHierarchical']), 'tax' => $s['filterTaxonomy'] ?? '', 'metaKey' => $s['customFieldKey'] ?? '',
			'wpField' => $s['wpPostField'] ?? '', 'range' => !empty($s['isDateRange']), 'operator' => $s['fieldCompareOperator'] ?? 'is', 'perPageOptions' => $s['perPageOptions'] ?? null,
			'labelAll' => $s['filterLabelAll'] ?? null];
		if ($f['action'] === 'sort') {
			$f['sorts'] = [];
			foreach ($s['sortOptions'] as $o) {
				$src = $o['optionSource']; $key = in_array($src, ['meta_value', 'meta_value_num'], true) ? $o['optionMetaKey'] : $src;
				$f['sorts'][$key . '_' . $o['optionOrder']] = ['label' => $o['optionLabel'], 'src' => $src, 'key' => $o['optionMetaKey'] ?? '', 'dir' => $o['optionOrder']];
			}
		}
		$filters[$fid] = $f;
		$all = $wpdb->get_results($wpdb->prepare("SELECT object_id, filter_value, filter_value_display, filter_value_id, filter_value_parent FROM $t WHERE filter_id=%s", $fid), ARRAY_A);
		$rows[$fid] = array_values(array_map(fn($r) => [(int) $r['object_id'], $r['filter_value'], $r['filter_value_display'], (int) $r['filter_value_id'], (int) $r['filter_value_parent']], array_filter($all, fn($r) => isset($in_pop[(int) $r['object_id']]))));
		$keys = array_filter([$f['metaKey'], ...array_map(fn($x) => $x['key'], $f['sorts'] ?? [])]);
		foreach ($keys as $k) foreach ($order as $id) $posts[$id]['meta'][$k] = get_post_meta($id, $k, true);
	}
	$items = []; $pages = (int) ceil(count($order) / max(1, $per_page));
	for ($pg = 1; $pg <= $pages; $pg++) {
		$sel = ($pg > 1 && $c['pag']) ? [$c['pag'] => $pg] : [];
		$r = spike_query($c, $qid, $sel, ($pg > 1 && !$c['pag']) ? $pg : 0);
		if ($pg === 1) { $initial_filters = $r['updated_filters']; $updated_query = $r['updated_query']; }
		foreach (preg_split('/(?=<li[^>]*data-pid="\d+")/', $r['html'], -1, PREG_SPLIT_NO_EMPTY) as $part) if (preg_match('/^<li[^>]*data-pid="(\d+)"/', $part, $mm)) $items[(int) $mm[1]] = $part;
	}
	file_put_contents($out, wp_json_encode(['queryId' => $qid, 'perPage' => $per_page, 'order' => $order, 'posts' => $posts, 'filters' => $filters, 'rows' => $rows, 'items' => $items,
		'filterHtml' => $initial_filters, 'paginationId' => $c['pag'], 'updatedQuery' => $updated_query, 'baseUrl' => $c['url'], 'postId' => $c['page']->ID, 'queryVars' => $vars], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	return count($items) . ' items, ' . count($order) . ' posts, filters: ' . implode(',', array_keys($filters)) . ', pagination: ' . ($c['pag'] ?: 'none');
}
try { echo spike_export($args), "\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), ' @ ', $e->getFile(), ':', $e->getLine(), "\n"; }
