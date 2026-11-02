// The Bricks guide's answers → settings mapping (src-svelte/dashboard/bricksGuide.ts). Run: node guide.test.js
import assert from 'node:assert/strict';
import { recommend } from '../../src-svelte/dashboard/bricksGuide.ts';

const info = (over = {}) => ({ seen: false, found: { rendered: true, formPages: 0, filterPages: 0 }, destinations: [{ transport: 'folder', crossDomain: false }], woo: false, ...over });
const found = (formPages, filterPages, rendered = true) => ({ found: { rendered, formPages, filterPages } });
const ans = (over = {}) => ({ mode: 'advanced', forms: 'no', filters: 'no', where: 'static', wpImages: false, ...over });
const r = (a, i, loops) => { const x = recommend(ans(a), info(i), loops); return [x.relay, x.staticFilters]; };
const allStatic = { known: true, fallback: 0 };

// ---- Advanced
// Nothing needs WordPress: both off (and the Lockdown gap closes).
assert.deepEqual(r({}), [false, false]);
// Forms only: connection on, no static filters.
assert.deepEqual(r({ forms: 'yes' }), [true, false]);
// Filters through WordPress: connection on, no static filters.
assert.deepEqual(r({ filters: 'yes', where: 'wordpress' }), [true, false]);
// Filters in the browser, every loop known to run there: no connection needed.
assert.deepEqual(r({ filters: 'yes', where: 'static' }, {}, allStatic), [false, true]);
// ...but it stays on while loops are unknown (no Process with the option yet) or one is refused.
assert.deepEqual(r({ filters: 'yes', where: 'static' }), [true, true]);
assert.deepEqual(r({ filters: 'yes', where: 'static' }, {}, { known: true, fallback: 1 }), [true, true]);
// Forms need WordPress regardless.
assert.deepEqual(r({ filters: 'yes', forms: 'yes', where: 'static' }, {}, allStatic), [true, true]);
// "Not sure": the last Process decides; with none, nothing is assumed.
assert.deepEqual(r({ forms: 'unsure', filters: 'unsure' }, found(2, 0)), [true, false]);
assert.deepEqual(r({ forms: 'unsure', filters: 'unsure' }, found(0, 0)), [false, false]);
assert.deepEqual(r({ forms: 'unsure', filters: 'unsure' }, found(0, 0, false)), [false, false]);

// Images from WordPress: only Advanced, only with filters through WordPress; never with filters in the browser.
const wi = (a, mode = 'advanced') => recommend(ans({ mode, filters: 'yes', wpImages: true, ...a }), info(found(0, 1))).wpImages;
assert.equal(wi({ where: 'wordpress' }), true);
assert.equal(wi({ where: 'static' }), false);
assert.equal(wi({ where: 'wordpress' }, 'simple'), false);
assert.equal(recommend(ans({ filters: 'no', where: 'wordpress', wpImages: true }), info(found(0, 0))).wpImages, false);
assert.equal(recommend(ans({ filters: 'yes', where: 'wordpress', wpImages: false }), info(found(0, 1))).wpImages, false);

// ---- Simple (ignores the answers; reads the last Process)
const s = (i, loops) => { const x = recommend(ans({ mode: 'simple', forms: 'no', filters: 'no', where: 'wordpress' }), info(i), loops); return [x.relay, x.staticFilters]; };
// Nothing found: both off.
assert.deepEqual(s(found(0, 0)), [false, false]);
// Forms only: connection on, static filters on (a filter added later just works).
assert.deepEqual(s(found(3, 0)), [true, true]);
// Filters only, every loop known to run in the browser: no WordPress needed after the Sync.
assert.deepEqual(s(found(0, 2), allStatic), [false, true]);
// Filters, loops not known yet or one refused: connection stays on as the fallback.
assert.deepEqual(s(found(0, 2)), [true, true]);
assert.deepEqual(s(found(0, 2), { known: true, fallback: 1 }), [true, true]);
// Filters plus a form.
assert.deepEqual(s(found(1, 2), allStatic), [true, true]);
// Nothing processed yet: assume both, both on.
assert.deepEqual(s(found(0, 0, false)), [true, true]);
// Destinations never change the result (Simple assumes a locked-down site).
assert.deepEqual(s({ ...found(0, 2), destinations: [{ transport: 'cloudflare', crossDomain: true }] }, allStatic), [false, true]);
// WooCommerce is not a reason for anything: it never changes the recommendation (the guide warns about it separately).
assert.deepEqual(recommend(ans({ filters: 'yes' }), info({ woo: true })), recommend(ans({ filters: 'yes' }), info({ woo: false })));
assert.ok(!recommend(ans({ filters: 'yes' }), info({ woo: true })).reasons.includes('bricksGuideWoo'));
console.log('guide.test.js: all mappings hold');
