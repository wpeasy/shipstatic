// The Bricks guide's answers → settings mapping (src-svelte/dashboard/bricksGuide.ts). Run: node guide.test.js
import assert from 'node:assert/strict';
import { recommend } from '../../src-svelte/dashboard/bricksGuide.ts';

const info = (over = {}) => ({ seen: false, found: { rendered: true, formPages: 0, filterPages: 0 }, destinations: [{ transport: 'folder', crossDomain: false }], woo: false, ...over });
const ans = (over = {}) => ({ forms: 'no', filters: 'no', independence: 'unsure', freshness: 'live', ...over });
const r = (a, i) => { const x = recommend(ans(a), info(i)); return [x.relay, x.staticFilters]; };

// Nothing needs WordPress: both off (and the Lockdown gap closes).
assert.deepEqual(r({}), [false, false]);
// Forms only: connection on, no static filters.
assert.deepEqual(r({ forms: 'yes' }), [true, false]);
// Filters, live results, WordPress stays reachable: through WordPress.
assert.deepEqual(r({ filters: 'yes', independence: 'notNeeded', freshness: 'live' }), [true, false]);
// Filters, a snapshot is fine: static, connection kept as the fallback.
assert.deepEqual(r({ filters: 'yes', independence: 'notNeeded', freshness: 'snapshot' }), [true, true]);
// Filters that must work without WordPress: static, and no connection (it could not answer anyway)…
assert.deepEqual(r({ filters: 'yes', independence: 'needed' }), [false, true]);
// …unless there are forms, which need WordPress regardless.
assert.deepEqual(r({ filters: 'yes', forms: 'yes', independence: 'needed' }), [true, true]);
// "Not sure" about independence on a Cloudflare / other-domain destination: static.
assert.deepEqual(r({ filters: 'yes' }, { destinations: [{ transport: 'cloudflare', crossDomain: false }] }), [true, true]);
assert.deepEqual(r({ filters: 'yes' }, { destinations: [{ transport: 'folder', crossDomain: true }] }), [true, true]);
assert.deepEqual(r({ filters: 'yes' }), [true, false]);
// "Not sure": the last Process decides.
assert.deepEqual(r({ forms: 'unsure', filters: 'unsure' }, { found: { rendered: true, formPages: 2, filterPages: 0 } }), [true, false]);
assert.deepEqual(r({ forms: 'unsure', filters: 'unsure' }, { found: { rendered: true, formPages: 0, filterPages: 0 } }), [false, false]);
assert.deepEqual(r({ forms: 'unsure', filters: 'unsure' }, { found: { rendered: false, formPages: 0, filterPages: 0 } }), [false, false]);
// WooCommerce is mentioned, never decides.
assert.ok(recommend(ans({ filters: 'yes' }), info({ woo: true })).reasons.includes('bricksGuideWoo'));
console.log('guide.test.js: all mappings hold');
