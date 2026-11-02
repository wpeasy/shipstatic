<?php
// Shared by export.php and oracle.php: everything about ONE Bricks query on ONE page, read from the page itself.
function spike_context(string $slug, string $qid): array {
	$page = get_posts(['post_type' => 'page', 'name' => $slug, 'numberposts' => 1])[0];
	$page_url = get_permalink($page);
	$els = get_post_meta($page->ID, '_bricks_page_content_2', true);
	$filters = []; $pag = null; $by = [];
	foreach ($els as $e) {
		$by[$e['id']] = $e;
		if (strpos($e['name'], 'filter-') === 0 && ($e['settings']['filterQueryId'] ?? '') === $qid) $filters[$e['id']] = $e;
		if ($e['name'] === 'pagination' && ($e['settings']['queryId'] ?? '') === $qid) $pag = $e['id'];
	}
	$html = wp_remote_retrieve_body(wp_remote_get($page_url, ['sslverify' => false, 'timeout' => 30]));
	preg_match_all('/<li[^>]*data-query-element-id="' . preg_quote($qid, '/') . '"[^>]*data-query-vars="([^"]+)"/', $html, $m);
	if (!$m[1]) preg_match_all('/data-query-element-id="' . preg_quote($qid, '/') . '"[^>]*data-query-vars="([^"]+)"/', $html, $m);
	$oqv = html_entity_decode($m[1][0] ?? '', ENT_QUOTES);
	return ['page' => $page, 'url' => $page_url, 'els' => $els, 'filters' => $filters, 'pag' => $pag, 'oqv' => $oqv, 'vars' => json_decode($oqv, true), 'qel' => $by[$qid]];
}

// POST to Bricks' query_result exactly like the browser. $sel = selectedFilters; $infinite = the infinitePage parameter.
function spike_query(array $c, string $qid, array $sel, int $infinite = 0): array {
	$ids = array_keys($c['filters']); if ($c['pag']) $ids[] = $c['pag'];
	$body = ['postId' => (string) $c['page']->ID, 'queryContextType' => 'post', 'queryElementId' => $qid, 'previewTemplateId' => '0', 'originalQueryVars' => $c['oqv'], 'pageFilters' => [],
		'filters' => $ids, 'selectedFilters' => (object) $sel, 'afTags' => [], 'nonce' => wp_create_nonce('bricks-nonce'), 'baseUrl' => $c['url'], 'lang' => false, 'mainQueryId' => false, 'activeSearchTemplate' => '0', 'urlParams' => (object) []];
	if ($infinite > 1) $body['infinitePage'] = $infinite;
	$r = wp_remote_post(home_url('/wp-json/bricks/v1/query_result'), ['sslverify' => false, 'timeout' => 60, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($body)]);
	return json_decode(wp_remote_retrieve_body($r), true) ?: ['error' => 'bad response ' . wp_remote_retrieve_response_code($r)];
}
