// Random filter combinations from a small spec (seeded, reproducible).
// Usage: node gen-combos.js <spec.json> <count> <seed> <out.json>
// Spec: { "<filterId>": { "type": "multi|single|range|date|daterange|text|int|page|infinite", "values": [...], "p": 0.4 } }
import fs from 'node:fs';
const [specFile, count, seed, outFile] = process.argv.slice(2);
const spec = JSON.parse(fs.readFileSync(specFile, 'utf8'));
let s = +seed || 7; const rnd = () => (s = (s * 1664525 + 1013904223) % 4294967296) / 4294967296;
const pick = (a) => a[Math.floor(rnd() * a.length)];
const many = (a, max) => [...new Set(Array.from({ length: 1 + Math.floor(rnd() * max) }, () => pick(a)))];
const date = () => new Date(Date.UTC(2026, 0, 1) + Math.floor(rnd() * 270) * 864e5).toISOString().slice(0, 10);
const out = {};
for (let i = 0; i < +count; i++) {
	const c = {};
	for (const [id, d] of Object.entries(spec)) {
		if (rnd() >= (d.p ?? 0.4)) continue;
		const v = { multi: () => many(d.values, d.max || 3), single: () => pick(d.values), text: () => pick(d.values), int: () => pick(d.values),
			range: () => { const a = d.min + Math.floor(rnd() * (d.max - d.min)); return [a, a + Math.floor(rnd() * (d.max - d.min))]; },
			date: () => date(), daterange: () => { const a = date(), b = date(); return (a < b ? [a, b] : [b, a]).join(','); },
			page: () => 1 + Math.floor(rnd() * (d.max || 4)), infinite: () => 1 + Math.floor(rnd() * (d.max || 4)) }[d.type]();
		if (d.type === 'infinite') c._infinite = v; else c[id] = v;
	}
	out['c' + i] = c;
}
fs.writeFileSync(outFile, JSON.stringify(out));
