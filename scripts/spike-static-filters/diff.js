// Differential test: engine vs the real Bricks answers captured by oracle.php.
import fs from 'node:fs';
import { prepare, run } from './engine.js';
const [ds, combos, oracle] = process.argv.slice(2).map((p) => JSON.parse(fs.readFileSync(p, 'utf8')));
const idx = prepare(ds);
let bad = 0, n = 0; const kinds = {};
for (const [name, sel] of Object.entries(combos)) {
	const want = oracle[name]; const got = run(ds, idx, sel); n++;
	const issues = [];
	if (JSON.stringify(got.ids) !== JSON.stringify(want.ids)) issues.push('ids');
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
console.log(`${n - bad}/${n} identical`, bad ? JSON.stringify(kinds) : '');
