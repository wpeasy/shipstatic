<?php
// Oracle: ask real Bricks (query_result) for filter combinations on one page/query.
// Usage: wp eval-file oracle.php <combos.json> <out.json> <page-slug> <query-id>
require_once __DIR__ . '/common.php';
function spike_oracle($args) {
	[$cf, $out, $slug, $qid] = $args;
	$combos = json_decode(file_get_contents($cf), true); $c = spike_context($slug, $qid); $res_all = [];
	wp_set_current_user(0);
	foreach ($combos as $name => $sel) {
		$infinite = (int) ($sel['_infinite'] ?? 0); unset($sel['_infinite']);
		$res = spike_query($c, $qid, $sel, $infinite);
		preg_match_all('/data-pid="(\d+)"/', $res['html'] ?? '', $ids);
		$uq = $res['updated_query'] ?? [];
		$res_all[$name] = ['ids' => array_map('intval', $ids[1]), 'count' => $uq['count'] ?? null, 'pages' => $uq['max_num_pages'] ?? null, 'start' => $uq['start'] ?? null, 'end' => $uq['end'] ?? null,
			'perPage' => $uq['query_vars']['posts_per_page'] ?? null, 'err' => $res['error'] ?? null, 'raw' => $res['updated_filters'] ?? null, 'html' => $res['html'] ?? ''];
	}
	file_put_contents($out, wp_json_encode($res_all, JSON_PRETTY_PRINT));
	return count($res_all);
}
try { echo 'ok ', spike_oracle($args), "\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), ' @ ', $e->getFile(), ':', $e->getLine(), "\n"; }
