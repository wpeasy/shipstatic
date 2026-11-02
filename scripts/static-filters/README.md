# Static Bricks filters — differential tests

The engine in `src-svelte/static-filters/` is a re-implementation of Bricks' filter logic. These scripts hold it to
**real Bricks**: they ask Bricks' own `query_result` for hundreds of random filter combinations, then ask the engine
(fed only by the data the plugin built) and compare. Run them before changing the engine or the markup patcher, and
before adding a Bricks version to `StaticFilters::VERIFIED_BRICKS`.

Needs a WordPress site with Bricks, WP-CLI, Node 22.6+ (it runs the TypeScript directly), and `linkedom`
(`npm install linkedom` anywhere; point `TOOLS` at that folder) for the markup comparison.

1. Build a page with the loop and filters, run **Process** with the option on (`wp shipstatic static-filters on`);
   the data file is in `wp-content/cache/shipstatic/shipstatic-filters/<id>.json` (`wp shipstatic static-filters status`).
2. `node gen-combos.js spec.json 300 <seed> combos.json` — random `selectedFilters`. The spec lists each filter id,
   its kind (`multi|single|range|date|daterange|text|int|page|infinite`) and values; see the examples in
   docs/STATIC_FILTERS_PLAN.md.
3. `wp eval-file oracle.php combos.json oracle-out.json <page-slug> <query-id>` — what Bricks answers.
4. `node diff.js <data file> combos.json oracle-out.json` — same posts, order, totals, page count, page size.
   `TOOLS=<dir> node markup-diff.js …` — same markup for every filter element, chips, pagination (as DOM).
   `TOOLS=<dir> node dump-diff.js … <combo> <filter id>` shows one difference line by line.

Use a **new seed** for the check that counts: a seed you tuned against proves little. Equal sort values can come out
in a different order (MySQL leaves it undefined); `diff.js` counts those separately and everything else must match.
`export.php` is the older, standalone exporter used before the plugin built its own files.
