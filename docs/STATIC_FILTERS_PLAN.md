# Fully static Bricks filters — spike results and plan

Status: **phase 1 implemented** (branch `spike/static-filters`): `src/Bricks/StaticFilters*.php`, `src-svelte/static-filters/`, harness in `scripts/static-filters/`. Not released. The rest of this file is the spike's evidence and the plan it followed; CLAUDE.md has the as-built notes.

Today the filters work on a static site through the WordPress **relay** (`Bricks\Relay`, `BricksRelayController`): every filter change POSTs to WordPress. This plan is an *optional* second mode that needs no WordPress at all at visit time: the build exports the data, a small script answers Bricks' own `query_result` request in the browser.

## 1. What was tested

A test page with one Bricks query loop (custom post type, 60 items, 9 per page, featured images) and: a hierarchical category checkbox filter, a tag checkbox filter with AND logic, a price range on a custom field, a search filter, a sort select, an active-filters bar and AJAX pagination.

The oracle is **real Bricks**: `oracle.php` POSTs to `/wp-json/bricks/v1/query_result` exactly as the browser does, for a given set of selected filters, and records ids, order, counts, every option count, the range bounds and the full re-rendered markup of every filter element. `diff.js` and `markup-diff.js` compare that with the engine (`engine.js`, `patch.js`) fed only by the exported data.

| Check | Local (Local by Flywheel) | cPanel (LiteSpeed, PHP 8.3, Lockdown on) |
|---|---|---|
| 300 random combinations, used to tune the engine | 300/300 | 300/300 |
| 300 more, different seed (held out) | 300/300 | 300/300 |
| Filter markup, held-out set (as DOM) | 277/300, the other 23 = sort ties | 277/300, same 23 |
| In a real browser (Chrome) | filters, chips, URL, pagination, page 2 all update; 14–31 ms per change, no request | not run |

"300/300" = same posts, same order, same total count and page count, same count on every option of every filter, same range bounds. In ~10% of combinations the order of items **with equal sort keys** differs (see Caveats), counted separately.

Bricks 2.4.4 on both. Not tested on other Bricks versions.

## 2. How Bricks behaves (what the engine has to copy)

All read from the Bricks source and confirmed by the oracle:

- The request is an `XMLHttpRequest` POST to `bricksData.restApiUrl + 'query_result'`; Bricks reads `readyState`, `status`, `response`. A shim that answers locally is enough: Bricks' own JavaScript keeps doing URL state, chips, load-more UI.
- The reply is: `html` (loop items), `updated_query` (`count`, `max_num_pages`, `start`, `end`, `query_vars.paged`), and `updated_filters` — **the full re-rendered HTML of every filter element**, including pagination and the active-filters list. A data-only engine is not enough; the markup must be rebuilt.
- The data is Bricks' own index table (`bricks_filters_index`: object, value, display, term id, parent). Taxonomy filters match with children included (selecting a parent matches posts in its children).
- **Option counts follow a specific rule** (filter-base.php): if the filter is inactive, count over the current results; if active and other filters are active, count over the results of the *other* filters only; if it is the only active filter, count over the current results, and an option not found keeps its base count under OR logic. A query-index call with an empty id list counts *everything* (no `WHERE`) — this affects empty results. Range min/max come from the results of the other filters; handles clamp low-up/high-down and the track is hidden when high ≤ low. A page past the end reports 0 results but a selected filter's options are still counted over the real results.
- Item HTML is rendered by Bricks (loop template, dynamic data). It is exported once per post; the reply's `html` is the concatenation of the items for the page.

## 3. Verdict

**Feasible and accurate for the filter types and options tested.** It is not "all of Bricks' filters". It is a reimplementation of Bricks' filtering logic, so every filter type, source and option has to be covered and tested one by one. The differential test (§6) is what makes that safe.

### Support matrix

| Feature | Status |
|---|---|
| Checkbox, taxonomy source, hierarchical, OR/AND | **Verified** |
| Radio, select (taxonomy source), "All"/placeholder option | **Verified** |
| Checkbox on a custom field; checkbox on a WordPress field (post author) | **Verified** |
| Range on a numeric custom field | **Verified** |
| Date picker on `post_date` (single before/after/is, and range) | **Verified**; custom-field dates and time-of-day not tested |
| Search (title, excerpt, content; terms ANDed, case/accent-insensitive) | **Verified** for plain words; WordPress's stop-words, quotes and relevance order not matched |
| Sort (select or radio): title, date, numeric meta | **Verified**, equal-key order excepted |
| Results per page (select) | **Verified** (an unknown size is ignored, as in Bricks) |
| AJAX pagination, active-filters bar | **Verified** |
| Infinite scroll (`infinitePage`) | **Verified** (data in both environments; scrolling in Chrome) |
| Several filtered loops on one page | **Verified** (two loops, separate datasets by query id) |
| Load more *button* | Same request as infinite scroll — expected to work, not separately run |
| Random order, relevance search, `menu_order` ties | **Cannot match** (non-deterministic or WordPress-specific) |
| Term queries (filters on a list of terms) | **Not verified** — the test loop needs more Bricks set-up than I got right; treat as phase 3 |
| User queries | **Do not support**: would publish user data in a static file |
| WooCommerce | **Excluded** (live cart, stock and prices) |
| ACF repeaters, queries depending on the current page/user/time, multilingual | Not supported at first; detect and fall back |

## 4. Caveats and possible deal-breakers (show these to the user)

1. **Order of items with equal sort keys.** MySQL does not define it (a sorted `LIMIT` query is effectively arbitrary): on 83 runs of equal prices it came out ascending 53 / descending 20 / mixed 10 (local) and 49 / 19 / 15 (cPanel). Not reproducible. Sorting by a unique key (date, title) is exact. Affects which items land on which page, only among equal keys.
2. **Content is a snapshot.** Results show what existed at Process/Sync time. New or changed posts need another Process + Sync (already true for the whole static site; the relay is live).
3. **Size.** Whole-result data per loop: raw JSON ≈ 1.5 KB/item for this test card (more for bigger cards): ~3 MB at 2,000 items, ~7.5 MB at 5,000 (gzip a fraction of that). Engine time stays under 10 ms at 5,000 items. Recommend a threshold (e.g. 3,000 items per loop) beyond which the option refuses and the relay is used.
4. **Every query must be one the engine understands.** Unsupported feature in a loop's settings → that loop stays on the relay and the panel says why.
5. **Bricks updates.** The count/markup rules are Bricks internals. The differential test must run against each Bricks version we claim to support, and the option should record the Bricks version it was exported with; on mismatch, warn and fall back.
6. **Search is not WordPress search.** Plain-word matching only; no relevance ranking.
7. **Personalised or time-dependent card content** (current user, "now", random) is frozen at export.
8. **Forms** still need WordPress (relay) — this option only replaces filters, sort, search, pagination.

## 5. Design for the option

**Setting** (Settings drawer + wizard forms/filters step, Bricks only, off by default): *"Run Bricks filters without WordPress (experimental)"*, with a warning listing §4 and a per-page report. WP-CLI `wp shipstatic static-filters on|off|status`. Docs → `#forms` extended.

**Build** (a `DeployReplacer`, key `bricksStaticFilters`, in `sync_signature()`; same pattern as `IndexBuilder`/`RelayReplacer`):
1. At **Process** time, for each rendered page containing `data-brx-filter`, loopback-fetch (render token, like `PageRenderer`) what the exporter needs: the page's query element ids + signed `originalQueryVars` (in the HTML), then call Bricks `query_result` once per page of results with no filters. It must go through HTTP loopback: `rest_do_request()` from WP-CLI failed in the spike (`have_posts() on null`), a real request works.
2. Mark each loop item so it can be split reliably: on trusted render requests add a `data-ss-id` attribute with the loop object id to the loop root (Bricks attribute filter), the same trusted-request mechanism as `PageRenderer::OBJECT_HEADER`. The spike used a `data-pid` attribute set in the page.
3. Per (page, query) write `shipstatic-filters/<hash>.json`: `order`, `posts` (title, date, content, excerpt, sort meta), `filters` (type, source, logic, nice name, sort options), index `rows`, `items` (id → HTML), the initial `filterHtml`, `updatedQuery`, base URL. Eligibility check first (§3 matrix); anything unsupported marks the loop "relay".
4. At **deploy** time, per destination: pass the dataset through the same path as relay replies and pages — `transform_for_destination()` (rewrite → Text/Link replacers → `Url::rebase()`), so URLs, links and replaced wording are the destination's. The shim script and a manifest entry per JSON go into the deploy manifest; pages get the shim inlined/`<script type="module">` and the dataset URL (not the relay `restApiUrl`, for loops that are static).
5. **Media (required):** every URL in every item's HTML (`src`, `data-src`, `srcset`, `data-srcset`, CSS `url()`), not only items on page 1, must be added to the deploy manifest. In the spike, 84 distinct image URLs were referenced and 21 of them only by items not shown on page 1, so a page scan alone would miss them. Reuse the asset collector the render uses, fed with the item HTML; media Replacements (swap to another library image) must apply to the dataset too (page-scoped `MediaReplacer` keys are per page — the dataset needs the same treatment per page that shows the loop).
6. Lockdown: nothing new — the dataset is a static file on the static site.

**Runtime** (`src-svelte/…` or a standalone file like `search/main.ts` — no imports, one file, must ship in the export): the spike's `shim.js` + `engine.js` + `patch.js`, bundled. Loads the dataset lazily on first filter interaction, intercepts `query_result` XHRs only, never touches other requests.

**Coexistence with the relay:** per loop, `static` or `relay`. A page can have both (static loop + a form that uses the relay). The relay stays the default and the fallback.

## 6. Tests to keep

- The **differential test** becomes a dev/CI tool: for each supported filter type/option a fixed set of random combinations through real Bricks vs the engine (`gen-combos.js` → `oracle.php` → `diff.js` / `markup-diff.js`), required at 100% on data and markup (ties excepted) before a release that claims support. It needs a Bricks install; keep it in `scripts/`, not in the zip.
- Add radio, select, custom-field sources, date picker, load more/infinite scroll to the harness *before* claiming them.
- Media test: sync a destination, assert every URL referenced by every item exists in the deployed manifest.

## 7. Suggested phases

1. **Core** (what the spike proves): checkbox/range/search/sort/pagination/active-filters, one loop per page, eligibility check + panel report, media, dataset through `transform_for_destination`, size cap. Docs + warnings. ~1–2 weeks.
2. Radio, select, custom-field sources, load more, infinite scroll, several loops per page.
3. Date picker; decide on term/user queries (probably never).

## 8. Decisions (owner)

- **Item cap: 3,000 per loop** — above that the option is refused for that loop and the relay is used.
- **Equal-sort-key order may differ** — acceptable; it goes in the warning text.
- **Scope: everything except WooCommerce** (it needs live cart/stock and cannot be static). Radio, select, date picker, custom-field and WordPress-field sources, per-page, infinite scroll / load more and several loops per page are now in scope; results in §9.

## 9. Spike round 2 — the other filters

Second test page (`spike-filters-2`), two loops: **A** (6 per page, AJAX pagination) with radio and select on taxonomies, checkbox on a custom field, checkbox on a WordPress field (post author), a single-date picker, a date-range picker, a per-page select, a radio sort, search, active filters; **B** (infinite scroll, sorted by title) with its own checkbox.

- Same method (real Bricks as oracle, random combinations, different seeds). Rules found beyond §2: a WordPress-field date filter is a `date_query`, which Bricks' count code ignores (an active filter's counts become 0 when that is the only other active filter); a selected radio or select option with a 0 count is disabled, a selected checkbox is not; an unknown per-page value is ignored and gets no chip; date semantics are WordPress's `date_query` (single "before"/"after" exclude the whole day, a range includes both end days).
- **Results (all with fresh random seeds; "data" = same posts, order, totals, page count and per-page; "markup" = every filter element and the active-filters bar and pagination, compared as DOM):**

| Run | Data | Markup |
|---|---|---|
| Local, loop A, 300 combos (tuning seed) | 300/300 | 275/300 |
| Local, loop A, 300 combos (held-out seed) | 300/300 | 269/300 |
| cPanel, loop A, 300 combos (held-out seed) | 300/300 | 269/300 |
| Local, loop B (infinite scroll), 120 combos | 120/120 | 120/120 |
| cPanel, loop B (infinite scroll), 120 combos | 120/120 | 120/120 |
| cPanel, page 1 filters, 300 combos (new seed) | 300/300 | 279/300 |

  Every markup miss is a sort-tie page (equal sort keys), not a logic difference. In Chrome: radio, select, per-page, sort, rating checkbox and chips update locally in 2–21 ms; infinite scroll appended 6 → 30 items from the static data.

## 10. Open questions for the owner

- Ship phase 1 as an experimental option (relay stays default), or wait for radio/select/load-more?
- Item cap (3,000?) and whether to offer sharding later.
- Is "tie order may differ" acceptable in the warning, or should the option refuse queries sorted by a non-unique key?
