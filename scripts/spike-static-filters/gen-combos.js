// Random filter combinations for the differential test (seeded, reproducible).
import fs from 'node:fs';
let s = +process.argv[3] || 7; const rnd = () => (s = (s * 1664525 + 1013904223) % 4294967296) / 4294967296;
const pick = (a) => a[Math.floor(rnd() * a.length)];
const many = (a, max) => { const n = 1 + Math.floor(rnd() * max); return [...new Set(Array.from({ length: n }, () => pick(a)))]; };
const cats = ['brunch', 'coffee', 'espresso', 'filter', 'pastry', 'savoury', 'sweet'];
const tags = ['vegan', 'gluten-free', 'new', 'seasonal', 'popular'];
const words = ['latte', 'tart', 'morning', 'golden', 'flat white', 'Croissant', 'zzzz', 'smoked honey', 'cold brew', 'brioche 3'];
const sorts = ['post_date_DESC', 'title_ASC', 'price_ASC', 'price_DESC'];
const out = {};
for (let i = 0; i < +process.argv[2]; i++) {
	const c = {};
	if (rnd() < 0.5) c.fcat01 = many(cats, 3);
	if (rnd() < 0.4) c.ftag01 = many(tags, 3);
	if (rnd() < 0.35) { const a = 3 + Math.floor(rnd() * 30); c.fprc01 = [a, a + Math.floor(rnd() * 25)]; }
	if (rnd() < 0.25) c.fsrch1 = pick(words);
	if (rnd() < 0.4) c.fsort1 = pick(sorts);
	if (rnd() < 0.3) c.pag001 = 1 + Math.floor(rnd() * 4);
	out['c' + i] = c;
}
fs.writeFileSync(process.argv[4] || '/tmp/spike/combos.json', JSON.stringify(out));
