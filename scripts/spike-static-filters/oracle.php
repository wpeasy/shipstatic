<?php
// Oracle: ask real Bricks (query_result) for a filter combination.
function spike_oracle($args){
$combos = json_decode(file_get_contents($args[0]), true);
$page_url = home_url('/spike-filters/');
$html = wp_remote_retrieve_body(wp_remote_get($page_url, ['sslverify' => false, 'timeout' => 30]));
preg_match('/data-query-vars="([^"]+)"/', $html, $m);
$oqv = html_entity_decode($m[1], ENT_QUOTES);
$page = get_posts(['post_type'=>'page','name'=>'spike-filters','numberposts'=>1])[0];
$all = ['fsrch1','fcat01','ftag01','fprc01','fsort1','fact01','pag001'];
wp_set_current_user(0);
$out = [];
foreach ($combos as $name => $sel) {
  $body = ['postId'=>(string)$page->ID,'queryContextType'=>'post','queryElementId'=>'qloop1','previewTemplateId'=>'0','originalQueryVars'=>$oqv,
    'pageFilters'=>[],'filters'=>$all,'selectedFilters'=>(object)$sel,'afTags'=>[],'nonce'=>wp_create_nonce('bricks-nonce'),
    'baseUrl'=>$page_url,'lang'=>false,'mainQueryId'=>false,'activeSearchTemplate'=>'0','urlParams'=>(object)[]];
  $r = wp_remote_post(home_url('/wp-json/bricks/v1/query_result'), ['sslverify'=>false,'timeout'=>60,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode($body)]);
  $res = json_decode(wp_remote_retrieve_body($r), true) ?: ['error'=>'bad response: '.wp_remote_retrieve_response_code($r).' '.substr(wp_remote_retrieve_body($r),0,200)];
  preg_match_all('/data-pid="(\d+)"/', $res['html'] ?? '', $ids);
  $opts = [];
  foreach (($res['updated_filters'] ?? []) as $fid => $fh) {
    if (preg_match_all('/<input[^>]*value="([^"]*)"[^>]*>.*?brx-option-count">\((\d+)\)/s', $fh, $mm, PREG_SET_ORDER)) {
      foreach ($mm as $x) $opts[$fid][$x[1]] = (int)$x[2];
    }
  }
  if (!empty($res['updated_filters']['fprc01']) && preg_match('/class="min" name="form-field-min-fprc01" min="(\d+(?:\.\d+)?)" max="(\d+(?:\.\d+)?)" value="([^"]*)"/', $res['updated_filters']['fprc01'], $rm)) $opts['fprc01_range'] = [(float)$rm[1], (float)$rm[2], (float)$rm[3]];
  $uq = $res['updated_query'] ?? [];
  $out[$name] = ['ids'=>array_map('intval',$ids[1]), 'count'=>$uq['count']??null, 'pages'=>$uq['max_num_pages']??null, 'start'=>$uq['start']??null, 'end'=>$uq['end']??null, 'opts'=>$opts, 'err'=>$res['error']??null, 'raw'=>$res['updated_filters']??null, 'html'=>$res['html']??''];
}
file_put_contents($args[1], wp_json_encode($out, JSON_PRETTY_PRINT));
return count($out);

}
try { echo 'ok ', spike_oracle($args), "\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), ' @ ', $e->getFile(), ':', $e->getLine(), "\n"; }
