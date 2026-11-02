// Exact-markup test: rebuild updated_filters from the engine and compare, as DOM, with what Bricks returned.
import fs from 'node:fs';
import { createRequire } from 'node:module';
import { prepare } from './engine.js';
import { buildReply } from './patch.js';
const require = createRequire(process.env.TOOLS + '/');
const { parseHTML } = require('linkedom');
const [ds, combos, oracle] = process.argv.slice(2).map((p) => JSON.parse(fs.readFileSync(p, 'utf8')));
const idx = prepare(ds);
const { document } = parseHTML('<html><body></body></html>');
const canon = (n) => {
	if (n.nodeType === 3) { const t = n.textContent.replace(/\s+/g, ' '); return t === ' ' ? '' : t; }
	if (n.nodeType !== 1) return '';
	const attrs = [...n.attributes].map((a) => [a.name, a.name === 'class' ? a.value.split(/\s+/).filter(Boolean).sort().join(' ') : a.value]).sort((a, b) => (a[0] < b[0] ? -1 : 1));
	return `<${n.tagName.toLowerCase()} ${attrs.map(([k, v]) => `${k}=${JSON.stringify(v)}`).join(' ')}>${[...n.childNodes].map(canon).join('')}</>`;
};
const toNode = (h) => { const t = document.createElement('template'); t.innerHTML = h.trim(); return t.content.firstElementChild; };
let bad = 0, n = 0; const kinds = {};
for (const [name, sel] of Object.entries(combos)) {
	const want = oracle[name]?.raw; if (!want) continue; n++;
	const filters = Object.keys(want);
	const got = buildReply(ds, idx, document, { selectedFilters: sel, filters }).updated_filters;
	const diffs = filters.filter((id) => canon(toNode(got[id] ?? '<i/>')) !== canon(toNode(want[id])));
	// also the item html (ids order)
	const wantItems = (oracle[name].html.match(/data-pid="\d+"/g) || []).join(',');
	const gotItems = (buildReply(ds, idx, document, { selectedFilters: sel, filters: [] }).html.match(/data-pid="\d+"/g) || []).join(',');
	if (wantItems !== gotItems) diffs.push('items');
	if (process.env.SHOW && diffs.length) for (const id of diffs.filter((d) => d !== 'items').filter((d) => d === process.env.SHOW)) { console.log('== ' + name + ' ' + id); console.log('GOT ', canon(toNode(got[id] ?? '<i/>')).replace(/data-brx-filter="[^"]*"/, '').slice(0, 900)); console.log('WANT', canon(toNode(want[id])).replace(/data-brx-filter="[^"]*"/, '').slice(0, 900)); }
	if (diffs.length) { bad++; diffs.forEach((d) => (kinds[d] = (kinds[d] || 0) + 1)); if (process.env.V) console.log(name, JSON.stringify(sel), '->', diffs.join(',')); }
}
console.log(`${n - bad}/${n} markup identical`, bad ? JSON.stringify(kinds) : '');
