// Spike: answer Bricks' query_result XHR locally from the exported datasets (no WordPress round trip).
// `datasets` maps a query element id to the URL of its JSON; each is fetched the first time that loop is used.
import { prepare } from './engine.js';
import { buildReply } from './patch.js';

export function install(datasets) {
	const loaded = {};
	const get = (qid) => (loaded[qid] ||= fetch(datasets[qid]).then((r) => r.json()).then((ds) => ({ ds, idx: prepare(ds) })));
	const open = XMLHttpRequest.prototype.open, send = XMLHttpRequest.prototype.send;
	XMLHttpRequest.prototype.open = function (method, url) { this.__ssLocal = /\/query_result(\?|$)/.test(String(url)); return open.apply(this, arguments); };
	XMLHttpRequest.prototype.send = function (body) {
		let req; try { req = this.__ssLocal ? JSON.parse(body) : null; } catch (e) { req = null; }
		if (!req || !datasets[req.queryElementId]) return send.apply(this, arguments); // not one of ours: let Bricks ask WordPress
		const xhr = this; const t0 = performance.now();
		get(req.queryElementId).then(({ ds, idx }) => {
			let reply; try { reply = JSON.stringify(buildReply(ds, idx, document, req)); } catch (e) { console.error('[static filters]', e); reply = JSON.stringify({ html: '', styles: '', error: String(e) }); }
			for (const [k, v] of Object.entries({ readyState: 4, status: 200, response: reply, responseText: reply })) Object.defineProperty(xhr, k, { value: v, configurable: true });
			(window.__ssTimings ||= []).push(performance.now() - t0);
			if (typeof xhr.onreadystatechange === 'function') xhr.onreadystatechange();
			xhr.dispatchEvent(new Event('load')); xhr.dispatchEvent(new Event('loadend'));
		});
	};
	return loaded;
}
