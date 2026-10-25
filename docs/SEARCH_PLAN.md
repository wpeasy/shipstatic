# Site search for static exports — implementation plan

> Status: **phases 1 and 3 implemented** (2026-10-24), verified on the two
> demo GitHub Pages sites (phase 2 polish still open). Phase 3 = the
> **ShipStatic Search** Bricks element (`src/Bricks/SearchElement.php`: style
> controls for every part, builder results preview) + `[shipstatic_search]`,
> both rendered by `Search\SearchForm`; default look in
> `src-svelte/search/search.css`, all `:where()` (zero specificity). The
> `/search/` results page is not built. Differences from the text below:
> - The script goes into the render (`PageRenderer::is_render_request()`).
>   On the live WordPress site it loads **only** when the opt-in "Use
>   ShipStatic search on the WordPress site too" is on, and then only for
>   the ShipStatic Search element (§13). Otherwise the live site keeps its own
>   search untouched. §6 step 3's fallback still applies if the index can't
>   load: the form submits as normal.
> - No on-disk record cache yet: a per-request memo keyed by `md5(page)`
>   suffices (8 pages → 0.07 s per destination). Add the disk cache in phase 2
>   if large sites need it.
>
> Gives every exported site a working search box with no server, no third-party
> service and no build tools. During each sync the plugin writes a small search
> index for each destination. A small script then searches it in the visitor's
> browser.

## 1. Why

WordPress search is dynamic: `/?s=term` is built by PHP on request. On a static
copy that URL just serves the home page, so every search box on an exported
site is broken today. The existing workarounds:

- a hosted search service (paid, third party, re-crawl delay), or
- running Pagefind by hand on an Export ZIP (needs Node, and the one-click
  Sync is lost).

Both are worse than doing it inside the export. ShipStatic already generates
extra files during an export (`sitemap.xml` and `robots.txt` through
`Pipeline::register_extra_files()`). A search index is the same kind of file.

## 2. Goals and non-goals

**Goals**
- Zero-config: turn on **Site search** in Settings, and existing search boxes
  work on every destination after the next Sync.
- Works with all deploy methods (FTP/SFTP, Connector, Cloudflare, GitHub
  Pages) and with Export ZIP.
- Each destination's index matches **its** content: its text replacements and
  its sub-path.
- On the live WordPress site, nothing changes by default: the box falls back
  to normal WordPress search. An opt-in setting makes the ShipStatic Search
  element search as you type there too (§13).
- No new runtime dependencies on the host; PHP only.

**Non-goals (v1)**
- Very large sites (thousands of pages). The index is one file; §8 sets a
  ceiling and a warning.
- Faceted or filtered search (by category, date, product attributes).
- Searching content that isn't in the export (drafts, private posts, PDFs).
- A server-side search results page. Results appear under the box as you type.
  §10 lists an optional results page.

## 3. How it works (overview)

```
Process (render)            Sync / Export, per destination
────────────────            ───────────────────────────────────────────────
pages → render cache   →    build_deploy_manifest():
                              each page: replacers → rebase   (exists today)
                              each page: extract title/headings/text  (new)
                            → write search-index.json for this destination
                            → add it to the deploy manifest (like sitemap.xml)

Visitor's browser
─────────────────
search box (any form[role=search] or input[name=s])
  → shipstatic-search.js fetches search-index.json (once, cached)
  → matches as you type → results list under the box
  → index missing (live WP site)? → do nothing; the form submits to /?s= as normal
```

## 4. Key design decision: build the index at deploy time, not at render

The render is destination-neutral (render format 2). Text replacements and the
sub-path are applied per destination in `Runner::build_deploy_manifest()`. An
index built from the render would contain destination 1's wording on
destination 2's site and would carry the `/__shipstatic_base__` placeholder in
its URLs.

So the index is built **inside `build_deploy_manifest()`**, from each page's
`$transformed` HTML, after the replacers and `Url::rebase()`. This is the same
point where each destination's own copy is written. The URLs in the index are
then this destination's real paths (`/shipstatic-demo-2/about/`), with no
placeholder left.

This means it is **not** a `Pipeline` extra-file emitter: emitters run once per
render, with no destination. Instead, `build_deploy_manifest()` calls a new
`Search\IndexBuilder` and adds `search-index.json` the way it adds replacement
files.

**Cost:** one DOM parse per page per destination on each deploy. Hundreds of
pages take well under a second. To avoid the work when nothing changed, cache
each page's extracted record keyed by its deploy hash (`page_deploy_hash()`
already computes it) in `cache/search/<dest>/`.

## 5. The index

**File:** `search-index.json` at the destination's site root. It goes inside
the sub-path folder via `relocate()`, so it sits next to the home page. It is
not a domain-root file, so it is not on the `robots.txt`/`favicon.ico` list.

**Shape** (compact; field names short on purpose):
```json
{
  "v": 1,
  "built": "2026-10-24T03:00:00Z",
  "pages": [
    { "u": "/shipstatic-demo-2/about/", "t": "About us", "h": ["Our story", "The team"], "x": "We build fast websites…" }
  ]
}
```
- `u`: the page URL at this destination (after `rebase`).
- `t`: the `<title>`, with the site-name suffix stripped where it is the
  WordPress default pattern, otherwise kept.
- `h`: `h1`–`h3` texts, max 20.
- `x`: the main text, whitespace-collapsed, capped at
  `shipstatic_search_text_limit` (default 3,000 chars). The first ~200 chars
  double as the result snippet.

**What is extracted** (`Search\TextExtractor`, `DOMDocument` +
`libxml_use_internal_errors`):
- Content root, first match wins: `#brx-content`, then `main`, then
  `[role=main]`, then `body`.
- Removed before reading text: `script`, `style`, `noscript`, `template`,
  `svg`, `nav`, `header`, `footer`, `form`, `[aria-hidden=true]`,
  `.screen-reader-text`, and anything matching
  `shipstatic_search_exclude_selectors`. Bricks headers and footers are
  templates outside `#brx-content`, so they drop out naturally.
- Decode entities. Collapse whitespace. Use mb-safe truncation.

**Pages left out**
- `404.html`, and pages with `<meta name="robots" content="noindex">`.
- Per-post opt-out: a "Hide from site search" checkbox in the existing
  ShipStatic metabox (`_shipstatic_search_exclude`).
- Filter: `shipstatic_search_include_page` (`bool`, `$rel`, `$dest`).

## 6. The search box (front end)

**Progressive enhancement of existing forms.** No new element is required. A
small script, `shipstatic-search.js`, is enqueued on the WordPress front end
when Site search is on. The crawl already uploads scripts that pages reference,
so it ships with every export automatically. On load it finds:

- `form[role=search]`, `form.search-form` and Bricks' search element
  (`.brxe-search form`), and
- any `form` containing `input[name=s]`.

For each match it:

1. Reads the index URL from a `data-shipstatic-search` attribute on the
   script tag. The value is `home_url('/search-index.json')`, so the
   UrlRewriter + `rebase` step gives it each destination's path, like any
   other link.
2. On first focus, fetches the index with `cache: 'no-cache'`. That way a new
   sync is picked up despite GitHub Pages' 10-minute `max-age`: the browser
   revalidates, and an unchanged file costs a 304.
3. **If the fetch fails (the live WordPress site has no index), it does
   nothing.** The form keeps submitting to `/?s=` and WordPress search works
   as before.
4. Otherwise it intercepts `submit` and shows results as you type, debounced
   about 120 ms.

**Matching** (our own ~3 KB, no library):
- Lower-case, accent-fold (`normalize('NFD')` with combining marks removed),
  split into words. Every query word must match (AND), and a prefix match
  counts for the last word, so results appear while typing.
- Score: title hit 10, heading hit 5, text hit 1, plus a bonus for an
  exact-phrase match. Show the top 8.
- Snippet: around the first text hit, with matches wrapped in `<mark>`. Built
  with DOM APIs, never `innerHTML` with index text. The index is our own
  output, but treat it as data.

**Results UI**
- A listbox under the input, with ARIA combobox semantics:
  - `aria-expanded`, `aria-activedescendant`;
  - ↑/↓ to move, Enter to open, Esc to close;
  - a polite live region announcing the result count.
- "No results for …" state.
- Minimal, theme-neutral CSS, scoped under `.bs-search-*`. It inherits font
  and colour from the site and uses CSS custom properties so Bricks users can
  restyle it: `--bs-search-bg`, `--bs-search-accent`, … This front-end CSS
  stands alone, like the FAB, and is not ab-ui.

**Optional dedicated element (phase 2).** A **ShipStatic Search** Bricks element
and a `[shipstatic_search]` shortcode that output a plain accessible search
form. These are for sites that have no search box yet. The script enhances it
like any other form.

## 7. Plumbing

| Area | Change |
|---|---|
| Setting | `shipstatic_search_enabled` ('1'/'0', default '0'), a **Site search** switch in the Settings drawer with a one-line explainer. |
| `Runner::build_deploy_manifest()` | After the page loop: when enabled, call `IndexBuilder::build($records, $dest)`, write the file to the deploy dir, add `search-index.json` (relocated). |
| `Runner::sync_signature()` | Fold in `search` (enabled flag + `IndexBuilder::VERSION` + exclusions), so turning it on or off flips "in sync". |
| Single-page sync | The page upload must also upload the destination's fresh `search-index.json`, so the edited page's text is searchable straight away. Check the single-page path only uploads the page today and add the index to it. |
| Snapshot transports | Nothing to do: it's one more file in the manifest. |
| FTP / Connector | A `.gz` sibling via the existing gzip step, plus the `.htaccess` JSON MIME/encoding rule if missing. |
| Export ZIP | Uses `deploy_manifest_for()`, so the index is included automatically. |
| Prune | Turning search off drops the file from the manifest, so prune removes it (and snapshot transports drop it anyway). |
| `Admin\Editor` metabox | The "Hide from site search" checkbox. |
| Dashboard | After Check/Sync, show "Search index: N pages, 84 KB" in the Check result; warn past the limit (§8). |
| WP-CLI | `wp shipstatic search status [--dest=<id>]` prints pages and size. |
| Abilities | Read-only `bs/search-status`, following the existing read-only tier. |
| i18n | New dictionary strings in `I18n.php`, translated in the 5 locales. The front-end script's three strings ("No results for %s", "%d results", "Search") come from a `data-` attribute, translated server-side. |

## 8. Size limits

Roughly 1.5 KB per page raw (title, headings, 3,000-char text), so 300 pages
come to about 450 KB, or ~120 KB gzipped. That is fine on any connection, and
it is downloaded only when someone focuses the search box.

- **Warn** in the Check result above 2 MB raw: "Search index is large — lower
  the text limit or exclude pages."
- **Hard cap** via `shipstatic_search_max_bytes` (default 5 MB). Past it, text
  is trimmed proportionally and the report says so. The sync never fails
  because of the index.
- A sharded or chunked index (Pagefind-style) is the upgrade path if real
  sites hit this. It is out of scope until then.

## 9. Security and correctness checks

- The index exposes only what the exported pages already show publicly.
  Excluded, noindex, password-protected and non-published content never
  reaches the render in the first place.
- Results are rendered with `textContent` / `createElement`, never
  `innerHTML` (CODE_STANDARDS / SECURITY_PATTERNS).
- The script never sends the query anywhere. All search is local, so there is
  nothing to disclose in readme.txt's external-services section.
- No `for...of` over DOM collections in the script (CLAUDE.md rule).
- The front-end script must not load inside the Bricks builder iframe. Use the
  same main-window guard as the FAB.

## 10. Phases

1. **Core**
   - `Search\TextExtractor`, `Search\IndexBuilder`.
   - Build in `build_deploy_manifest()`, signature, setting switch,
     single-page index upload.
   - `shipstatic-search.js` enhancing existing forms (dropdown results).
   - Verify on the two demo GitHub sites: a text replacement on destination 2
     shows only in destination 2's results, and URLs carry each sub-path.
2. **Polish**
   - Per-page exclude checkbox, Check-result size line + warnings, WP-CLI,
     ability, translations, Docs page section, readme FAQ entry.
3. **Optional**
   - ShipStatic Search Bricks element + shortcode.
   - A `/search/` results page (a static page whose script reads `?q=` and
     lists all matches) for themes whose search box can't host a dropdown.

## 11. Testing

- Extraction fixtures:
  - a Bricks page (header/footer templates stripped);
  - a classic theme page (`main`);
  - a page with inline JSON-LD and scripts (none of it in `x`);
  - entities and non-Latin text (é, ß, 日本語).
- Two destinations with different sub-paths and a text replacement:
  - each index has its own URLs and wording;
  - the signature flips when search is toggled.
- Live WordPress: the box still submits to `/?s=`, and the script makes one
  failed fetch only.
- GitHub Pages: after a sync, a new page appears in results without a hard
  refresh (the `no-cache` revalidation).
- Keyboard and screen-reader pass on the results list (VoiceOver + Safari,
  NVDA + Firefox).
- Plugin Check (release + trunk) stays at 0 errors.

## 12. Open questions

1. **On by default for new installs?** Proposed: off. It changes front-end
   behaviour of existing search boxes, so it should be an explicit choice.
2. **Index text limit:** 3,000 chars per page balances relevance and size. Is
   that right for long-form blogs? It can be raised through the filter.
3. **WooCommerce product pages:** include as normal pages in v1? Price and
   stock would be a snapshot from sync time, the same as the rest of the
   static site.
4. **Multilingual sites** (WPML/Polylang): one index per language folder
   would be better than one mixed index. Defer until someone asks.

## 13. Live-site search for the ShipStatic Search element (implemented 2026-10-25)

**Problem.** On the live site the element was a plain `/?s=` form; on exports it
searches as you type. Users want both to behave the same.

**Setting.** `shipstatic_search_live` ('1'/'0', default '0'): "Use ShipStatic
search on the WordPress site too", a second switch under Site search in the
Settings drawer, disabled while Site search is off. It takes effect only while
Site search is on (`LiveIndex::enabled()`). It doesn't touch the export, so
toggling it needs no Process. Off = exactly the old behaviour.

**Index source: the render cache, not WP_Query.** Considered building from
WP_Query (always current) and rejected:
- Bricks stores page content in `_bricks_page_content_2` post meta, so
  `post_content` is nearly empty for Bricks pages. Getting their text means
  rendering each page (templates, dynamic data, query loops), which the
  render already does.
- The render is exactly the export's page set and text, so live and static
  results match. WP_Query would also need its own rules for which pages count
  (discovery mode, manual include, archives).
- Cost: the render is parsed once and cached; WP_Query + rendering would be
  per request or need its own cache anyway.

Trade-off: live search is as fresh as the last **Process**. Softened by a
per-request status check: each record keeps its post id (`url_to_postid` at
build time), and pages whose post is no longer `publish`, is
password-protected or isn't publicly viewable are dropped on every request
(one primed query). So unpublishing takes effect at once; new pages and edited
text appear after the next Process.

**Endpoint.** `GET /wp-json/shipstatic/v1/search-index`
(`REST\SearchIndexController`), the plugin's only public route (SECURITY_PATTERNS.md
§0). It returns `{v, pages: [{u, t, h, x}]}`, the same shape as
`search-index.json`, built by `Search\LiveIndex` with
`IndexBuilder::record()` from each rendered page after `Url::rebase()` with the
site's own home path. So URLs are the live site's, with no destination text
replacements and no sub-path. It returns 404 when off or when nothing has been
rendered. Records are cached in the transient `shipstatic_live_search_index`,
keyed by the render's page hashes + `IndexBuilder::VERSION` + home path, so a
new Process rebuilds it and nothing else does. The response carries an `ETag`
(the script's `cache: 'no-cache'` fetch revalidates, so an unchanged index costs
a 304) and `Cache-Control: public, max-age=300`.

**Loading.** `Frontend\Search::enqueue_live()` is called from
`SearchElement::enqueue_scripts()` and the `[shipstatic_search]` shortcode, so
the script loads only on pages that have the box. It is skipped during a render
(the export gets `enqueue()`'s settings) and in both Bricks builder windows.
Settings: `index` = the REST URL, `selector` = `form.bs-ss`, `live` = true.

**Scope / behaviour differences** (`src-svelte/search/main.ts`):
- Live: only `form.bs-ss` is upgraded. Plain WordPress/Bricks search forms keep
  normal WordPress search. Exports still upgrade every form with an `s` field.
- Enter opens the highlighted (or best) result, as on exports. With nothing to
  open, live falls through to normal WordPress search (`/?s=`). An export has
  no server to answer that, so it stays put.
- Both: if the index can't be loaded (REST blocked by a security plugin,
  404), the submit isn't intercepted, so the form works as a plain search form.

**Verified** (playground, element in the Site Header template, a temporary
plain `get_search_form()` in the footer):
- Live: dropdown as you type; Enter opens the best match; a no-match Enter
  lands on `/?s=…`; the plain form isn't upgraded and submits normally; no
  script in the builder (main window or iframe).
- Endpoint: 404 while off; 35 pages, live URLs, no base placeholder; ETag →
  304; a page switched to draft disappears immediately.
- Export: the render's config is unchanged (`/__shipstatic_base__/search-index.json`,
  no selector); per-destination `search-index.json` with each sub-path; a local
  copy of the export upgrades both forms and searches.
- Mobile width (500 px): the dropdown stays on screen with no horizontal
  scroll, and tapping a result opens it, on both the live site and the export.
