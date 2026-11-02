// Spike: answer Bricks' query_result XHR locally from the exported dataset (no WordPress round trip).
import { prepare } from './engine.js';
import { buildReply } from './patch.js';

export async function install(datasetUrl) {
	const ds = await (await fetch(datasetUrl)).json();
	const idx = prepare(ds);
	const open = XMLHttpRequest.prototype.open, send = XMLHttpRequest.prototype.send;
	XMLHttpRequest.prototype.open = function (method, url) { this.__ssLocal = /\/query_result(\?|$)/.test(String(url)); return open.apply(this, arguments); };
	XMLHttpRequest.prototype.send = function (body) {
		if (!this.__ssLocal) return send.apply(this, arguments);
		const xhr = this; const t0 = performance.now();
		let reply; try { reply = JSON.stringify(buildReply(ds, idx, document, JSON.parse(body))); } catch (e) { console.error('[static filters]', e); reply = JSON.stringify({ html: '', styles: '', error: String(e) }); }
		setTimeout(() => {
			for (const [k, v] of Object.entries({ readyState: 4, status: 200, response: reply, responseText: reply })) Object.defineProperty(xhr, k, { value: v, configurable: true });
			(window.__ssTimings ||= []).push(performance.now() - t0);
			if (typeof xhr.onreadystatechange === 'function') xhr.onreadystatechange();
			xhr.dispatchEvent(new Event('load')); xhr.dispatchEvent(new Event('loadend'));
		}, 0);
	};
	return { ds, idx };
}
