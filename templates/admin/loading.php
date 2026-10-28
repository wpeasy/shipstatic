<?php
/**
 * Shared "Loading…" placeholder + startup watchdog for the Svelte admin apps.
 *
 * The placeholder is replaced when the app mounts (main.ts clears the mount
 * node). If it is still there after a grace period, the bundle failed to load
 * or crashed before mounting, so the watchdog swaps "Loading…" for the reason
 * (script blocked/404/wrong MIME type, runtime error, old browser) instead of
 * leaving the user staring at a spinner-less message.
 *
 * When the PHP-side pre-check ({@see \WPEasy\ShipStatic\Admin\Menu::assets_problem()})
 * already knows a built file is missing, the cause is printed straight away and
 * the watchdog is skipped.
 *
 * Expects $shipstatic_noscript (string) and $shipstatic_entry ('dashboard'|'docs')
 * from the including template.
 *
 * @package WPEasy\ShipStatic
 * @since   2.4.11
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<noscript>
	<?php echo esc_html($shipstatic_noscript); ?>
</noscript>
<?php
$shipstatic_problem = \WPEasy\ShipStatic\Admin\Menu::assets_problem(
	'src-svelte/' . $shipstatic_entry . '/main.ts'
);
if ($shipstatic_problem !== null) :
	?>
<div class="notice notice-error inline">
	<p><strong><?php echo esc_html__('ShipStatic could not start.', 'shipstatic'); ?></strong></p>
	<p><?php echo esc_html($shipstatic_problem); ?></p>
	<p><?php echo esc_html__('If this keeps happening, send this message to ShipStatic support:', 'shipstatic'); ?>
		<a href="https://wordpress.org/support/plugin/shipstatic/" target="_blank" rel="noopener">https://wordpress.org/support/plugin/shipstatic/</a></p>
</div>
	<?php
	return;
endif;
?>
<div id="bs-loading" style="color: #646970;">
	<p><?php echo esc_html__('Loading…', 'shipstatic'); ?></p>
</div>
<?php
global $wp_version;
$shipstatic_plugins = array_map(
	static fn ($file): string => (string) strtok((string) $file, '/'),
	(array) get_option('active_plugins', [])
);
wp_print_inline_script_tag(
	'(function(){'
	. 'var C=' . wp_json_encode([
		'title'    => __('ShipStatic could not start.', 'shipstatic'),
		'intro'    => __('The dashboard script did not load or stopped with an error. Details:', 'shipstatic'),
		'noModule' => __('This browser does not support JavaScript modules. Please update your browser.', 'shipstatic'),
		'noData'   => __('The dashboard data was not printed by WordPress (the script was not enqueued — another plugin or a caching/optimisation layer may be removing it).', 'shipstatic'),
		'noEntry'  => __('The dashboard script tag is missing from the page.', 'shipstatic'),
		'http'     => __('The script file returned HTTP %s.', 'shipstatic'),
		'mime'     => __('The script file is served as "%s" instead of JavaScript — the server or a security/optimisation plugin is blocking or rewriting it.', 'shipstatic'),
		'network'  => __('The script file could not be fetched (network error or blocked by a firewall/content-security-policy).', 'shipstatic'),
		'loadErr'  => __('The browser reported an error loading the script file.', 'shipstatic'),
		'jsErr'    => __('JavaScript error:', 'shipstatic'),
		'hint'     => __('First, try deactivating your other plugins (especially caching, minification, optimisation and security plugins), then reload this page.', 'shipstatic'),
		'console'  => __('If it still does not load, open your browser console (F12, then the Console tab), copy everything shown there, and send it to ShipStatic support so we can diagnose it:', 'shipstatic'),
		'support'  => 'https://wordpress.org/support/plugin/shipstatic/',
		'version'  => __('Version', 'shipstatic'),
		'copy'     => __('Copy diagnostics', 'shipstatic'),
		'copied'   => __('Copied — paste it into your support request.', 'shipstatic'),
		'env'      => [
			'ShipStatic' => SHIPSTATIC_VERSION,
			'WordPress'  => (string) $wp_version,
			'PHP'        => PHP_VERSION,
			'Server'     => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash((string) $_SERVER['SERVER_SOFTWARE'])) : '',
			'Plugins'    => implode(', ', $shipstatic_plugins),
		],
	]) . ';'
	. 'var errs=[];'
	. 'window.addEventListener("error",function(e){'
	. 'var t=e.target;'
	. 'if(t&&t.tagName==="SCRIPT"&&t.src){errs.push(C.loadErr+" "+t.src);return;}'
	. 'errs.push(C.jsErr+" "+(e.message||"")+(e.filename?" ("+e.filename+":"+e.lineno+")":""));'
	. '},true);'
	. 'window.addEventListener("unhandledrejection",function(e){'
	. 'var r=e.reason;errs.push(C.jsErr+" "+(r&&r.message?r.message:String(r)));'
	. '});'
	. 'function show(items){'
	. 'var box=document.getElementById("bs-loading");if(!box)return;'
	. 'box.textContent="";box.className="notice notice-error inline";box.style.color="";'
	. 'var h=document.createElement("p"),s=document.createElement("strong");'
	. 's.textContent=C.title;h.appendChild(s);box.appendChild(h);'
	. 'var p=document.createElement("p");p.textContent=C.intro;box.appendChild(p);'
	. 'var ul=document.createElement("ul");ul.style.listStyle="disc";ul.style.marginLeft="2em";'
	. 'for(var i=0;i<items.length;i++){var li=document.createElement("li");li.textContent=items[i];ul.appendChild(li);}'
	. 'box.appendChild(ul);'
	. 'var n=document.createElement("p");n.textContent=C.hint;box.appendChild(n);'
	. 'var c=document.createElement("p");c.appendChild(document.createTextNode(C.console+" "));'
	. 'var a=document.createElement("a");a.href=C.support;a.target="_blank";a.rel="noopener";a.textContent=C.support;c.appendChild(a);box.appendChild(c);'
	. 'var b=document.createElement("button");b.type="button";b.className="button";b.textContent=C.copy;'
	. 'b.addEventListener("click",function(){copy(report(items),b);});'
	. 'var bp=document.createElement("p");bp.appendChild(b);box.appendChild(bp);}'
	. 'function report(items){'
	. 'var t="ShipStatic diagnostics\n";'
	. 'for(var k in C.env){if(C.env[k])t+=k+": "+C.env[k]+"\n";}'
	. 't+="Browser: "+navigator.userAgent+"\nPage: "+location.pathname+location.search+"\n\nProblems:\n";'
	. 'for(var i=0;i<items.length;i++)t+="- "+items[i]+"\n";'
	. 'return t;}'
	. 'function copy(text,btn){'
	. 'function done(){btn.textContent=C.copied;}'
	. 'function fallback(){var a=document.createElement("textarea");a.value=text;a.style.position="fixed";a.style.opacity="0";'
	. 'document.body.appendChild(a);a.select();try{document.execCommand("copy");done();}catch(e){}document.body.removeChild(a);}'
	. 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(text).then(done,fallback);}else{fallback();}}'
	. 'function check(){'
	. 'if(!document.getElementById("bs-loading"))return;'
	. 'var items=errs.slice();'
	. 'if(!("noModule" in HTMLScriptElement.prototype))items.push(C.noModule);'
	. 'if(typeof window.shipStaticData==="undefined")items.push(C.noData);'
	. 'else if(window.shipStaticData.version)items.push(C.version+": "+window.shipStaticData.version);'
	. 'var tag=document.querySelector("script[type=module][src*=\"assets/dist/\"]");'
	. 'if(!tag){items.push(C.noEntry);show(items);return;}'
	. 'if(!window.fetch){show(items);return;}'
	. 'fetch(tag.src,{credentials:"same-origin"}).then(function(r){'
	. 'var ct=r.headers.get("content-type")||"";'
	. 'if(!r.ok)items.push(C.http.replace("%s",r.status));'
	. 'else if(!/javascript|ecmascript/i.test(ct))items.push(C.mime.replace("%s",ct||"?"));'
	. 'show(items);'
	. '})["catch"](function(){items.push(C.network);show(items);});}'
	. 'window.addEventListener("load",function(){setTimeout(check,4000);});'
	. '})();'
);
