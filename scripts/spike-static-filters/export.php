<?php
// Spike: export the data a static filter engine needs for ONE Bricks query on ONE page.
// Usage: wp eval-file export.php <page-slug> <query-element-id> <out.json>
function spike_export($args) {
	[$slug, $qid, $out] = $args;
	$page = get_posts(['post_type' => 'page', 'name' => $slug, 'numberposts' => 1])[0];
	$page_url = get_permalink($page);
	$els = get_post_meta($page->ID, '_bricks_page_content_2', true);
	$by = []; foreach ($els as $e) $by[$e['id']] = $e;
	$html = wp_remote_retrieve_body(wp_remote_get($page_url, ['sslverify' => false, 'timeout' => 30]));
	preg_match('/data-query-vars="([^"]+)"/', $html, $m);
	$oqv = html_entity_decode($m[1], ENT_QUOTES);
	$vars = json_decode($oqv, true);
	$per_page = (int) ($vars['posts_per_page'] ?? 10);
	// Population in the loop's own default order.
	$pop_args = array_merge($vars, ['paged' => 1, 'posts_per_page' => -1, 'nopaging' => true, 'fields' => 'ids', 'no_found_rows' => true]);
	unset($pop_args['_bricks_query_signature']);
	$order = (new WP_Query($pop_args))->posts;
	$posts = [];
	foreach ($order as $id) {
		$p = get_post($id);
		$posts[$id] = ['title' => $p->post_title, 'date' => $p->post_date, 'content' => $p->post_content, 'excerpt' => $p->post_excerpt, 'menu' => (int) $p->menu_order];
	}
	global $wpdb; $t = $wpdb->prefix . 'bricks_filters_index';
	$filters = []; $rows = [];
	foreach ($els as $e) {
		if (strpos($e['name'], 'filter-') !== 0 || ($e['settings']['filterQueryId'] ?? '') !== $qid) continue;
		$s = $e['settings'];
		$f = ['id' => $e['id'], 'element' => $e['name'], 'action' => $s['filterAction'] ?? 'filter', 'source' => $s['filterSource'] ?? '', 'nice' => $s['filterNiceName'] ?? '',
			'logic' => strtoupper($s['filterMultiLogic'] ?? 'OR'), 'hierarchy' => !empty($s['filterHierarchical']), 'tax' => $s['filterTaxonomy'] ?? '', 'metaKey' => $s['customFieldKey'] ?? ''];
		if ($f['action'] === 'sort') {
			$f['sorts'] = [];
			foreach ($s['sortOptions'] as $o) {
				$src = $o['optionSource']; $key = in_array($src, ['meta_value', 'meta_value_num'], true) ? $o['optionMetaKey'] : $src;
				$f['sorts'][$key . '_' . $o['optionOrder']] = ['label' => $o['optionLabel'], 'src' => $src, 'key' => $o['optionMetaKey'] ?? '', 'dir' => $o['optionOrder']];
			}
		}
		$filters[$e['id']] = $f;
		$rows[$e['id']] = array_map(fn($r) => [(int) $r['object_id'], $r['filter_value'], $r['filter_value_display'], (int) $r['filter_value_id'], (int) $r['filter_value_parent']],
			$wpdb->get_results($wpdb->prepare("SELECT object_id, filter_value, filter_value_display, filter_value_id, filter_value_parent FROM $t WHERE filter_id=%s", $e['id']), ARRAY_A));
		if (($f['source'] === 'customField') && $f['metaKey']) { foreach ($order as $id) $posts[$id]['meta'][$f['metaKey']] = get_post_meta($id, $f['metaKey'], true); }
	}
	foreach ($filters as $fid => $f) if ($f['action'] === 'sort') foreach ($f['sorts'] as $k => $sd) if ($sd['key']) foreach ($order as $id) $posts[$id]['meta'][$sd['key']] = get_post_meta($id, $sd['key'], true);
	// Item markup: ask Bricks for every page of the unfiltered loop and split on the per-item id attribute.
	$req_filters = array_keys($filters);
	foreach ($els as $e) if ($e['name'] === 'pagination' && ($e['settings']['queryId'] ?? '') === $qid) $req_filters[] = $e['id'];
	$items = []; $pages = (int) ceil(count($order) / max(1, $per_page));
	for ($pg = 1; $pg <= $pages; $pg++) {
		$body = ['postId' => (string) $page->ID, 'queryContextType' => 'post', 'queryElementId' => $qid, 'previewTemplateId' => '0', 'originalQueryVars' => $oqv, 'pageFilters' => [], 'filters' => $req_filters,
			'selectedFilters' => (object) ($pg > 1 ? ['pag001' => $pg] : []), 'afTags' => [], 'nonce' => wp_create_nonce('bricks-nonce'), 'baseUrl' => $page_url, 'lang' => false, 'mainQueryId' => false, 'activeSearchTemplate' => '0', 'urlParams' => (object) []];
		$r = json_decode(wp_remote_retrieve_body(wp_remote_post(home_url('/wp-json/bricks/v1/query_result'), ['sslverify' => false, 'timeout' => 60, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($body)])), true);
		if ($pg === 1) $initial_filters = $r['updated_filters'];
		$parts = preg_split('/(?=<li[^>]*data-pid="\d+")/', $r['html'], -1, PREG_SPLIT_NO_EMPTY);
		foreach ($parts as $part) if (preg_match('/^<li[^>]*data-pid="(\d+)"/', $part, $mm)) {
			if (preg_match('/<li class="brx-query-trail".*$/s', $part, $tm)) { $trail = $tm[0]; $part = substr($part, 0, -strlen($tm[0])); }
			$items[(int) $mm[1]] = $part;
		}
		if ($pg === 1) $updated_query = $r['updated_query'];
	}
	file_put_contents($out, wp_json_encode(['queryId' => $qid, 'perPage' => $per_page, 'order' => array_map('intval', $order), 'posts' => $posts, 'filters' => $filters, 'rows' => $rows, 'items' => $items, 'filterHtml' => $initial_filters, 'trail' => $trail ?? '', 'updatedQuery' => $updated_query ?? null, 'baseUrl' => $page_url, 'postId' => $page->ID], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	return count($items) . ' items, ' . count($order) . ' posts';
}
try { echo spike_export($args), "\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), ' @ ', $e->getFile(), ':', $e->getLine(), "\n"; }
