// Differential test: engine vs the real Bricks answers captured by oracle.php.
import fs from 'node:fs';
import { prepare, run } from './engine.js';
const [ds, combos, oracle] = process.argv.slice(2).map((p) => JSON.parse(fs.readFileSync(p, 'utf8')));
const idx = prepare(ds);
let bad = 0, n = 0, tied = 0; const kinds = {};
for (const [name, sel] of Object.entries(combos)) {
	const want = oracle[name]; const got = run(ds, idx, sel); n++;
	const issues = [];
	if (JSON.stringify(got.ids) !== JSON.stringify(want.ids)) {
		// Equal sort keys have no defined order in MySQL: accept the same sort-key sequence as a (counted) tie difference.
		const sf = Object.values(ds.filters).find((f) => f.action === 'sort'); const s = sf?.sorts?.[sel[sf.id]];
		const key = (id) => (s?.src === 'meta_value_num' ? ds.posts[id].meta[s.key] : s?.src === 'title' ? ds.posts[id].title : ds.posts[id].date);
		const same = s && got.ids.map(key).join() === want.ids.map(key).join();
		if (same) tied++; else issues.push('ids');
	}
	if (got.count !== want.count) issues.push(`count ${got.count}/${want.count}`);
	if (got.pages !== want.pages) issues.push(`pages ${got.pages}/${want.pages}`);
	const wo = Array.isArray(want.opts) ? {} : want.opts;
	for (const [fid, o] of Object.entries(wo)) {
		if (fid.endsWith('_range')) { if (JSON.stringify(got.opts[fid]) !== JSON.stringify(o)) issues.push(`range ${JSON.stringify(got.opts[fid])}/${JSON.stringify(o)}`); continue; }
		const diffs = Object.keys(o).filter((v) => (got.opts[fid] || {})[v] !== o[v]);
		if (diffs.length) issues.push(`${fid}:${diffs.map((v) => `${v}=${(got.opts[fid] || {})[v]}/${o[v]}`).join(',')}`);
	}
	if (issues.length) { bad++; for (const i of issues) { const k = i.split(/[ :]/)[0]; kinds[k] = (kinds[k] || 0) + 1; } if (process.env.V) console.log(name, JSON.stringify(sel), '->', issues.join(' | ')); }
}
console.log(`${n - bad}/${n} identical (+${tied} differ only in the order of equal sort keys)`, bad ? JSON.stringify(kinds) : '');
