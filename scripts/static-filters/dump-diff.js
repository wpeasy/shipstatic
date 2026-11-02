// Write the engine's markup and Bricks' markup for one combination/filter as one-tag-per-line files, to `diff`.
// Usage: TOOLS=<dir with node_modules/linkedom> node dump-diff.js <dataset> <combos> <oracle-out> <combo> <filter-id>
import fs from 'node:fs';
import { createRequire } from 'node:module';
import { prepare } from '../../src-svelte/static-filters/engine.ts';
import { buildReply } from '../../src-svelte/static-filters/patch.ts';
const { parseHTML } = createRequire(process.env.TOOLS + '/')('linkedom');
const [dsF, cF, oF, name, fid] = process.argv.slice(2);
const ds = JSON.parse(fs.readFileSync(dsF)), sel = JSON.parse(fs.readFileSync(cF))[name], want = JSON.parse(fs.readFileSync(oF))[name].raw[fid];
const { document } = parseHTML('<html><body></body></html>');
const got = buildReply(ds, prepare(ds), document, { selectedFilters: { ...sel }, filters: [fid], infinitePage: sel._infinite }).updated_filters[fid];
const canon = (n) => {
	if (n.nodeType === 3) { const t = n.textContent.replace(/\s+/g, ' '); return t === ' ' ? '' : t; }
	if (n.nodeType !== 1) return '';
	const attrs = [...n.attributes].filter((a) => !(a.name === 'class' && a.value.trim() === '') && a.name !== 'data-brx-filter').map((a) => [a.name, a.name === 'class' ? a.value.split(/\s+/).filter(Boolean).sort().join(' ') : a.value]).sort((a, b) => (a[0] < b[0] ? -1 : 1));
	return `<${n.tagName.toLowerCase()} ${attrs.map(([k, v]) => `${k}=${JSON.stringify(v)}`).join(' ')}>${[...n.childNodes].map(canon).join('')}</>`;
};
const lines = (h) => { const t = document.createElement('template'); t.innerHTML = h.trim(); return canon(t.content.firstElementChild).replace(/></g, '>\n<'); };
fs.writeFileSync('/tmp/spike/diff-got.txt', lines(got)); fs.writeFileSync('/tmp/spike/diff-want.txt', lines(want));
