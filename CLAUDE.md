# CLAUDE.md 

## Project Properties
- **Plugin Name:** ShipStatic (slug `shipstatic`; formerly "Bricks Static", renamed in 2.1.3 because another plugin took that name — never reintroduce `bricks-static`/`bricks_static_`/`BRICKS_STATIC_`). The GitHub repository is `wpeasy/shipstatic` (renamed from `bricks-static`, which redirects); the local dev folder is still named `bricks-static`; the release zip's folder is `shipstatic/`.
- **Description:** Generate and serve static HTML versions of Bricks-built pages for performance.
- **Minimum WordPress:** 6.5 (required for `wp_enqueue_script_module()` ESM support)
- **Minimum PHP:** 8.0
- **PHP Namespace:** `WPEasy\ShipStatic` (vendor-prefixed to avoid colliding with the Bricks theme's own `\Bricks\` namespace + autoloader, and to sidestep `Static` being a reserved PHP keyword)
- **Constants Prefix:** `SHIPSTATIC_`
- **Textdomain:** `shipstatic`
- **REST API Namespace:** `shipstatic/v1`
- **Options / hooks / transients / meta prefix:** `shipstatic_` (post meta `_shipstatic_…`). No custom tables.
- **Script/style handles:** `shipstatic-…`
- **JS globals:** `shipStaticData`, `shipStaticEditorData`, `shipStaticFabData`, `shipStaticListData` (via `wp_localize_script`)
- **Prefix rule:** never use the old 2-character `bs_`/`BS_` for anything global in WordPress — wordpress.org requires unique prefixes of 4+ characters. `.bs-` CSS classes and DOM ids (`bs-dashboard` mount points) are fine. The Connector receiver template (`connector.php.txt`) keeps its own `bs_*` function names: it runs on the destination server, outside WordPress.
- **CSS Prefix:** `.bs-`

## Single Plugin, All Features Free

**ShipStatic** (`shipstatic.php`, `WPEasy\ShipStatic`, `SHIPSTATIC_*`) ships as one free plugin on wp.org with every feature unlocked — unlimited pages/destinations, all four replacement types (Text, Media, Links, Videos), gzip pre-compression, remote pruning, and sitemap/robots.txt generation. Changelog: `CHANGELOG.md` — starts at 2.3.0, the first public (wordpress.org) release; earlier development history (Bricks Static, the former Pro add-on) lives only in git.

There is no license, no edition/capability gating, and no second plugin — `Support\Edition`, `Support\License`, and the `pro/` add-on have all been removed. Never reintroduce a tier check; if a feature needs to be conditionally available, gate it on an actual runtime/server capability (e.g. `Sync\Compressor::available()` for gzip, which checks for `gzencode()`), not a plan/edition concept.

## Required Reading

**IMPORTANT:** You MUST read the following documentation files BEFORE writing any code for this project:

| File | Purpose |
|------|---------|
| **CODE_STANDARDS.md** | Naming conventions, security, PHP/JS/CSS standards |
| **SECURITY_PATTERNS.md** | Non-negotiable security rules (admin-only REST model, `Support\UrlSafety` SSRF guard, TLS/`sslverify` rule, `UnzipScript` hardening, SFTP host-key, `{@html}`/`wp_kses_post`). Read before touching any REST controller, the deploy/transport layer, URL fetching, or stored-HTML rendering. |
| **WORDPRESS.md** | Plugin header template and WordPress configuration |
| **SVELTE5_IMPLEMENTATION.md** | Svelte 5 runes and patterns (avoid Svelte 4 syntax) |
| **src-svelte/shared/app.css** | The only project stylesheet: heading sizes + `.bs-stack`/`.bs-row` flex helpers, scoped under `.ab-ui` and powered by **ab-ui `--ab-*` tokens**. (Replaced the old `bs-framework.css`, which is deleted — there are no `--bs-*` tokens left.) |

> These files are **references**, not includes — read the relevant one before working in its area. Do not inline or duplicate their content into this file.

---

## UI Component Library — ab-ui (`import { … } from '@wpeasy/ab-ui'`)

The UI is built **entirely** on **[ab-ui](https://github.com/wpeasy/ab-ui)** (npm package **`@wpeasy/ab-ui`** — it was the unscoped `ab-ui` before v0.2.0; import from the scoped name), a Svelte 5 + native-CSS component lib, consumed as a **pinned git dependency** (`package.json` → `"@wpeasy/ab-ui": "github:wpeasy/ab-ui#<sha>"`, currently the **v0.18.0** commit; its `prepare` builds `dist/` on `npm install`). There is **one** design system — ab-ui. **Upgrade gotcha:** npm caches git tag→commit resolutions, so `npm install github:wpeasy/ab-ui#vX` after a bump often keeps the old version — pin the new tag's **commit SHA** in `package.json`, `rm -rf node_modules/@wpeasy/ab-ui`, then `npm install`.

> **v0.2.0 API note — `Switch`/`Checkbox`/`ToggleButton` use `1`/`0` numbers, not booleans** (`checked`/`pressed` props + `onchange(n)` take/give a number, so state round-trips through WP options). Our app state is boolean, so convert at the boundary: `checked={x ? 1 : 0}` + `onchange={(n) => set(n === 1)}` (one-way `checked`/`pressed`, no `bind:`). `TriStateButton` stays string-valued.

The old `bs-framework.css` + `--bs-*` token system is **deleted**; all component CSS uses ab-ui `--ab-*` tokens, and the only project CSS is `shared/app.css` (headings + `.bs-stack`/`.bs-row` helpers, `--ab-*`-powered). Use ab-ui for every control — `Button`, `Input`, `Select`, `Switch`, `Checkbox`, `Status`, `Alert`, `Badge`, `Tag`, `Modal`, etc. **Never edit the library** — override via tokens / the `class` prop / snippet props / a wrapper component (e.g. `lib/Select.svelte`, `lib/Modal.svelte`, `lib/IncludeToggle.svelte` wrap ab-ui while preserving our prop API).

Rules:
- **Wrap every ab-ui region in `class="ab-ui"`** — required for styling AND for `--ab-*` tokens to resolve; `--ab-rem` defaults to 16px (correct for WP admin). Components mounted into WP DOM (editor metabox, etc.) each need their own `.ab-ui` wrapper.
- **Each entry that renders UI imports, in order:** `@wpeasy/ab-ui/styles` → `@wpeasy/ab-ui/styles/wp-admin.css` → `shared/app.css`. `wp-admin.css` is ab-ui's **official WP-admin preset** (REQUIRED in wp-admin): it lifts the `--ab-z-*` tokens above WP chrome (`#wpadminbar` 99999) so overlays aren't buried. (The old hand-written `shared/ab-ui-wp.css` shim — which also re-asserted input/select colours over wp-admin's `forms.css` — is **deleted**: v0.2.0's core CSS scopes controls under `.ab-ui .ab-input__control`/`.ab-select__control` at (0,2,0), already out-specifying `forms.css` (0,1,1), so typed inputs/selects no longer render white.)
- **Transitive CSS:** when ≥2 entries import `@wpeasy/ab-ui/styles`, Vite splits it into a SHARED chunk. PHP enqueue MUST walk transitive imports — use `Support\Assets::css_files()` / `enqueue_css()`, never just `$entry['css']` (this is the documented CSS-chunk gotcha; it once broke the dashboard).
- **Library bug override:** ab-ui's `Collapsible` keeps `padding-bottom` on its content, which a closed `0fr` row can't shrink below (a sliver of text showed). `shared/app.css` zeroes it and adds an `::after` spacer inside the clipped content — drop the override once ab-ui fixes it upstream.
- **Iframe rule still applies:** ab-ui CSS is fully `.ab-ui`-scoped (sets `color-scheme` on `.ab-ui`, never `:root`), so it's iframe-safe — but only mount it in the main window, never the Bricks preview iframe.
- **Framework-free by design (do NOT convert):** `frontend/Fab.svelte` (the FAB button itself) and the server-rendered `.bs-static-cell` list cells use hardcoded colours so they look identical on any front-end/theme. **`lib/SyncPanel.svelte` (the "Sync this page" modal) IS ab-ui-themed** (2026-07-04): it's wrapped in `.ab-ui` with the dashboard theme from `uiPrefs` (`data-theme`/`data-accent`/custom seeds + `use:autoContrast`), its colours are `--ab-*` tokens **with hardcoded fallbacks** (so it still renders if ab-ui CSS is absent). The admin/editor entry already loads ab-ui; the **frontend** FAB now enqueues the shared ab-ui base CSS via `Support\Assets::enqueue_ab_ui_css()` (the `app-*.css` common to the dashboard+editor entries — Vite won't attribute it to the separate frontend entry, so we recover it by intersection). ab-ui CSS is `.ab-ui`-scoped, so loading it on the front end can't affect visitor page content.
- **Justified-bespoke (ab-ui can't model the interaction):** `DestinationTabs` — inline double-click-to-rename needs an `<input>` *inside* a tab, which ab-ui `Tabs` can't host; it stays a hand-rolled (but `--ab-*`-tokened) tab strip with the "+" right after the last tab. `MethodPanel` is a pure-data `<dl>`. Everything else is ab-ui: ProgressPanel→`Progress`, App top-tabs + pages tabs→`Tabs`, Replacements→`Accordion`, Plain/Rich→`ToggleButton`, pages list→`Table`, all confirms→`ConfirmButton`, tooltips→`Tooltip`. Settings live in a right-side `Drawer` (gear ⚙ trigger top-right of the header → `SettingsDrawer.svelte`, `portal={false}` so it inherits the root's theme; panel width doubled to 40rem via a scoped `:global(.ab-ui .ab-drawer--right .ab-drawer__panel)` override since the lib default is 20rem and the `size` prop only applies when resized). Grouped sections: **Style** (colour-scheme switcher = three small `Button`s Auto/Light/Dark — active `secondary` / others `ghost`, Light/Dark carry `Sun`/`Moon` icons from `@wpeasy/ab-ui/icons`, matching the ab-ui showcase — + `Select` accent, driving `data-theme`/`data-accent` via `shared/uiPrefs.svelte.ts`), **Pages to include** (`DiscoveryToggle section="mode"` — the mode select + help only), **Enable sync single button** (`Switch`), **AI tools (MCP)** (`AiToolsPanel`). `DiscoveryToggle` takes a `section` prop (`mode` | `actions` | `all`): the drawer renders `mode`, while the **toolbar** (`bs-globalbar` in `App.svelte`) renders `section="actions"` — Process / View processed list / "N not in export" chip + the pages-overview Table modal.
- **`Alert` renders `children` *instead of* `description`** — an alert with an action button must put its text inside the children (`<p>` + `.bs-row` in a `.bs-stack--sm`), or the text silently disappears.
- **Motion & loading:** ab-ui exports transitions — `import { fadeUp, scaleFade, slideScale } from '@wpeasy/ab-ui'`. `fadeUp` is the standard **content-swap** transition: `{#key state}<div in:fadeUp>…</div>{/key}`. `Tabs` only transitions its panel via the **`panel` snippet** (not external `{#if}`) — we use it for App top-tabs + the pages Included/Excluded tabs; the destination swap, Check result, and ProgressPanel use `{#key}`/`in:fadeUp` too. For **loading** placeholders use `lib/ListSkeleton.svelte` (ab-ui `Skeleton`) instead of "Loading…" text — content areas only, NOT button busy states. ab-ui's own docs live in its `README.md` + the showcase (https://marvelous-zuccutto-daea5b.netlify.app/), and its golden rule (never modify the lib — use props/tokens/snippets/wrappers) matches our override policy.

---

## Preview Iframe — Do NOT Inject Styles or Run Feature Code

**CRITICAL:** The Bricks Builder preview iframe renders the user's frontend content. Plugin code must NEVER inject styles, CSS variables, or run feature code inside this iframe — doing so breaks user content (e.g. form styling, color schemes).

- ab-ui's stylesheet sets `color-scheme` + `light-dark()` on `.ab-ui` — never mount an `.ab-ui` region (or load `@wpeasy/ab-ui/styles`) inside the preview iframe.
- Never set `color-scheme` on the iframe body or inject `:root` variables meant for our UI.

**PHP guard pattern:**
```php
$is_main = function_exists('bricks_is_builder_main') && bricks_is_builder_main();
$is_iframe = function_exists('bricks_is_builder_iframe') && bricks_is_builder_iframe();
if ($is_main || (!$is_main && !$is_iframe)) {
    // Main-window-only assets here
}
```

Code that intentionally targets the iframe document (e.g. content overlays) must use `iframeDoc` / `contentDocument` explicitly, scope all injected CSS with `.bs-` prefixed classes, and never set `color-scheme` or inject `:root` variables.

---

## Development Workflow

See **CODE_STANDARDS.md** for naming/security/style rules and **SVELTE5_IMPLEMENTATION.md** for Svelte 5 runes. The points below are build-system lessons that aren't obvious from those files.

### After Writing Code

1. **Run the type check after significant changes:** `npm run check` (after adding files, modifying interfaces, or structural changes).
2. **Verify before removing code:** search the whole file (and `src-svelte/`) for usages before deleting any function, variable, or import.
3. **Update types immediately:** when using a new property on an interface, add it to `src-svelte/shared/types.ts` right away.

### DOM Collections — NEVER Use `for...of`

**CRITICAL:** Never use `for...of` on DOM host-object collections in Vite-compiled code — use index-based `for` loops. Affected: `StyleSheetList`, `CSSRuleList`, `NodeList`, `HTMLCollection`, `DOMTokenList`, `NamedNodeMap`.

```typescript
// ✗ WRONG — for...of on DOM collections silently fails on some hosting environments
for (const sheet of document.styleSheets) { ... }

// ✓ CORRECT — index-based iteration works everywhere
const sheets = document.styleSheets;
for (let s = 0; s < sheets.length; s++) { ... }
```

**Why:** DOM collections are host objects whose `Symbol.iterator` comes from the browser, not the JS engine. Vite/esbuild's `for...of` iterator helpers interact differently with the CSSOM in production HTTPS environments. Index loops bypass the iterator protocol entirely.

When checking DOM-object types across window boundaries (e.g. a detached panel reading the main window's stylesheets), **duck-type, don't `instanceof`** — constructors differ across windows:

```typescript
// ✓ CORRECT — duck-type by checking for the .style property
const style = (rule as CSSStyleRule).style;
if (style) { ... }
```

### Icons — Use a Centralized Icon System

Never inline raw SVGs. Keep all icons in one module (`src-svelte/lib/icons/`, re-exported from `lib/icons/index.ts`; the brand mark is `IconShip`, whose geometry is mirrored by `Admin\Menu::MENU_ICON_SVG` — the admin menu icon, masked so WordPress's svg-painter, which only rewrites `fill`, can recolour line art) — Svelte components import named `Icon*` components, TS files import `ICON_*` HTML strings for `innerHTML`. Add new icons there, don't paste SVG markup into components.

### wordpress.org Plugin Check — keep it at 0 errors

The plugin is headed for wordpress.org, whose reviewers run **Plugin Check** (`wp plugin check <path>`; the `plugin-check` plugin is installed on the playground site). Run it against the *release tree* (the unzipped `dist/` zip), not the dev folder. Rules it enforces that bite this codebase:

- **No heredoc/nowdoc (`<<<`)** — generated files (`UnzipScript`, `.htaccess`, nginx snippet, Connector receiver) live as `*.txt` templates in `src/*/templates/` with `__BS_*__` placeholders filled by `strtr()`.
- **`wp_delete_file()`, never `unlink()`.** Other direct file calls that WP_Filesystem can't do (streamed `fopen`/`fread`, `rmdir` in the cache dir, atomic `rename`, `readfile` download, `proc_open` pipes) carry a `phpcs:ignore`/`disable` **with a reason**.
- **`// phpcs:enable` must name the sniffs it re-enables** — a bare one re-enables everything, silently cancelling any file-level `phpcs:disable` above it.
- `ExceptionNotEscaped` is disabled per file (with the reason): exception messages are plain text for JSON/WP-CLI, rendered as text by Svelte — `esc_html()` at the `throw` would show `&#039;` to users. Never echo a `getMessage()` into HTML.
- No `<script>` literals in PHP — use `wp_get_script_tag()`. No HTML inside translatable strings — wrap outside: `'<em>' . esc_html__('…') . '</em>'`.
- Remaining **warning**: `load_plugin_textdomain()` (kept for the bundled `languages/`).
- **wordpress.org's upload scanner is stricter than the released Plugin Check** (2.1.0 passed both of these locally). Rules learned from rejected uploads: **`Tested up to` only in `readme.txt`, never in the PHP header** (`plugin_header_tested_up_to_not_allowed`); **no scripts/executables anywhere in the zip** (e.g. a vendor `build-phar.sh`) — `scripts/make-zips.mjs` strips vendor dev files and refuses forbidden types. **Before a wordpress.org upload, run trunk Plugin Check too** (the scanner runs unreleased rules — #1465 was trunk-only): `git clone --depth 1 https://github.com/WordPress/plugin-check` → `composer install --no-dev` → copy into `wp-content/plugins/plugin-check-trunk`, deactivate `plugin-check`, activate the trunk copy, `wp plugin check <unzipped release>`, then swap back and delete the copy.

### Build Commands

| Command | Purpose |
|---------|---------|
| `npm run check` | TypeScript/Svelte type check (run often!) |
| `npm run build` | Production build |
| `npm run dev`   | Development build with watch |
| `npm run zip`   | Build + write the release zip `dist/shipstatic-<ver>.zip` |
| `npm run assets` | Render `.wordpress-org/` icon + banners from their SVG sources |
| `npm run svn`   | Stage a wordpress.org SVN release (`trunk/`, `tags/<ver>/`, `assets/`) from the zip — `dist/svn/` preview, or `npm run svn -- <checkout>` to sync into a real checkout (never commits; prints the svn commands) |

### `shared.js` — IIFE Wrapper & No ES Imports

If you ship a `shared.js` loaded as a **non-module** script via `wp_enqueue_script()`:

1. **No ES imports** and **no dynamic `import()`** in its entry — Vite emits top-level `import`/`__vitePreload` that breaks in non-module context. Import statically so Vite inlines, or expose work via a global from a real ES-module bundle.
2. **IIFE-wrap the output** (`vite.config.ts`) so minified variable names (like `_`) don't leak to global scope and clobber WordPress globals (underscore.js).

### `wp_localize_script` Booleans — Use Truthy Checks, Not `=== true`

Top-level keys passed through `wp_localize_script` arrive in JS as the **string** `'1'` / `''`, not `true` / `false`. `if (data.isMainWindow === true)` is therefore **always false at runtime**.

```ts
// ✗ WRONG — strict equality against '1'/'' is always false
if (data.isMainWindow === true) { ... }
// ✓ CORRECT — truthy/falsy accepts both true and '1'
if (data.isMainWindow) { ... }
```

### Dynamic Imports — Use `import()` for Optional Code

Use dynamic `import()` for debug tools, feature modules that can be disabled, large context-specific dependencies, and any code not hit on every load. Only skip it for always-needed core, tiny (<1 KB) modules, or `shared.js` (see above).

```typescript
let mod: typeof import('./debug/overflow-detection') | null = null;
async function start() { mod ??= await import('./debug/overflow-detection'); mod.start(); }
```

### CSS Chunk Loading

When Vite splits scoped Svelte CSS into shared chunks, browsers do **not** auto-load a chunk's CSS when its JS imports. Walk the Vite manifest and enqueue every transitive `css` entry (a single `enqueue_entry_css()`-style helper per entry point), strip the hash so handles stay stable across builds. **Symptom of a missing chunk:** components render with correct scoped class names but appear completely unstyled — often "works locally" due to stale cached CSS, only fresh installs reveal it.

---

## Deviation Reporting

When deviating from established patterns (vanilla JS instead of Svelte, new pattern instead of an existing one, anything against this file for a technical reason), tell the user **before** implementing, using:

> **Note:** [What I'm deviating from] — [Technical reason] — [Alternative if you prefer]

---

## Code Organization — DRY

Centralize and reuse — this is a blocking requirement:

- **Defaults / constants / config** live in one place (`src-svelte/shared/defaults.ts`, `shared/constants.ts`) and are imported, never duplicated.
- **State** lives in shared stores, not duplicated per component.
- **Components** live in `src-svelte/lib/` — check there before creating a new one.
- Before writing new code, search for an existing implementation; if you find duplication, refactor to centralize first.

---

## Tooltips — Use Popovers, Not `title`

Never use the `title` attribute. Use `data-balloon` popovers for consistent styling and positioning:

```typescript
button.setAttribute('data-balloon', 'Show Panel (Ctrl+Shift+E)');
button.setAttribute('data-balloon-pos', 'down-left'); // up | down | left | right | combos
```

Include keyboard shortcuts in the tooltip text when available.

---

## CSS Class Names — Avoid Bricks Toolbar Collisions

Any plugin UI mounted inside `#bricks-toolbar` MUST use **prefixed state class names** — Bricks ships ID-anchored rules (e.g. `#bricks-toolbar .active { ... }`, specificity 1,1,0) that beat most class chains.

```ts
// ✗ WRONG — overridden by `#bricks-toolbar .active`
<button class="bs-pill" class:active={isSelected} />
// ✓ CORRECT — `.is-active` is specific enough
<button class="bs-pill" class:is-active={isSelected} />
```

`!important` is a worse fix — it propagates and starts a specificity arms race. Renaming the state class (`is-`) is permanent and free. The same risk applies to `.primary`, `.danger`, `.selected`, `.open`, `.disabled`, etc.

---

## Bricks UI Navigation — Mutate Vue State, Never Match `data-balloon`

**Read live Bricks data from Vue `$_state`, not `bricksData.loadData`.** `bricksData.loadData.*` is the load-time snapshot and does NOT update when the user edits colors/variables/classes mid-session. Read `.brx-body.__vue_app__…$_state.<thing>` for current editor state.

**Any code that programmatically navigates the Bricks UI MUST go through Vue `$_state`, NOT DOM selectors matching `data-balloon`** — `data-balloon` is the localized tooltip, so `[data-balloon="Variables"]` returns null on every non-English install.

```ts
function getBricksVueProps() {
    const brxBody = document.querySelector('.brx-body');
    return brxBody?.__vue_app__?.config?.globalProperties ?? null;
}
// ✓ Vue-state mutation, i18n-safe
const props = getBricksVueProps();
if (props?.$_state) props.$_state.popupTab = 'variables';
```

Class names on Bricks UI containers (`.bricks-popup.styles.variables`) ARE i18n-safe (derived from the internal key) — safe to **read**, never **write** (Vue owns rendering). `placeholder` attributes are also localized — select inputs by DOM position, not placeholder text.

---

## Iframe-Aware Click-Outside — `mousedown` + Capture

"Click anywhere to dismiss" handlers must listen on **both** the main `document` AND the Bricks preview iframe's `contentDocument` — iframe clicks fire on the iframe's document and don't bubble to the parent.

- Use **`mousedown`** (not `click`) in **capture** phase — Bricks' Vue handles `click` and may `stopPropagation()`.
- **Cache the iframe document instance** you attached to, and detach from that same instance — `contentDocument` can change (navigation/hot reload), and re-querying on teardown leaks the original listener.

---

## Bricks Permissions Integration

Features that modify Bricks data must be gated by Bricks' `Builder_Permissions` system (admins get `full_access`).

- **PHP:** `\Bricks\Builder_Permissions::user_has_permission()`.
- **JS:** a `shared/permissions.ts` feature→permission map with `hasPermission()` / `hasFeaturePermission()` helpers.

Enforce at three layers: UI (disable the control with a reason), runtime (check before activating), and global settings (read-only mode for non-admins).

---

## REST Liveness vs Diagnostics — Two Endpoints, Two Permission Models

If you add health/diagnostics routes, keep them on separate permission models:

| Route | Auth | Purpose |
|---|---|---|
| `GET /shipstatic/v1/health` | `__return_true` (no auth) | Boot-time liveness probe. Returns `{ ok, version, ts }`. **Must stay no-auth** — a 401 here is indistinguishable from REST being broken. |
| `GET /shipstatic/v1/health/diagnostics` | `manage_options` | Full server/WP snapshot for an admin Health tab. Never leak config to lower-privilege users. |

Add new diagnostic data to `/health/diagnostics` (admin-only), never `/health`. Omit email/PII so the payload is safe to paste into public support channels.

---

## Per-Destination Replacement Subsystems

All four replacement types — **Text, Media, Links, Videos** — are unconditionally available; none are gated. All collectors/replacers/deploy-replacers/REST controllers live in `src/`.

**Per-page model (Media + Videos):** a replacement is scoped to ONE exported page — stored as `{page, from, to, toId}` where `page` is the export-relative path (e.g. `about/index.html`). The dashboard picks a page first (a `Select`), then lists that page's media/videos; nothing shows until a page is chosen, and there is no per-page swap cap. Text + Links stay global (whole-export). This is why `DeployReplacer::apply($html, $ctx, $relative)` takes the page path — page-scoped replacers key their prepared `ctx` by page and apply only `$ctx[$relative]`; global ones ignore `$relative`.

Replacements (Text, Media, Links, Videos) all follow ONE pattern — copy it for any new one:

1. **Collector** (`src/Media/*Collector.php`) — `pages()` lists exported pages for the selector; `collect($page_rel)` scans that ONE page's cached HTML (`Manifest::RENDER_OPTION`) for its items.
2. **Replacer** (`src/Render/*Replacer.php`) rewrites the matched thing in the per-destination deploy copy. Two non-negotiable rules, learned from real bugs:
   - **Tag-scoped, never global** — match the specific element/attribute (or, for text, only the runs between tags). A blanket `str_replace`/regex across the whole document corrupts attributes, scripts, JSON-LD, or body text. `TextReplacer` splits out `<script>/<style>/<!-- -->` and operates on the right side only.
   - **Entity-decode before matching** — attribute/`url()` values carry `&amp;`, `&quot;`, percent-encoding, etc. Decode the captured value (`html_entity_decode(..., ENT_QUOTES | ENT_HTML5)`) before comparing to the stored key; `esc_attr()` the replacement on write.
3. **Storage** on `Destination` (`*_replacements()` + a `sanitize_*` ); included in `Destination::for_display()` and `apply()`.
4. **Deploy**: applied in `Runner::build_deploy_manifest()` (only writes a per-destination page copy when the page actually changed) and folded into `Runner::sync_signature()` so a replacement change flips "in sync".
5. **REST** `GET /shipstatic/v1/<thing>` (admin-only) + a Svelte panel in the Replacements **accordion** (one section open at a time), auto-saving and pruning stale entries.

Media/Videos that swap to a library attachment must also add the new file (and image variants) to the deploy manifest so it uploads. The Videos replacer additionally rewrites an embed's source `origin=` (incl. percent-encoded) to the destination.

The Media subsystem groups by **source attachment**, not by URL: `MediaCollector::resolve_attachment()` strips a `-WxH` suffix and resolves any variant (src, `srcset` entry, or CSS `url()` background) to its attachment, so one dashboard row = one image and one swap covers the whole responsive set. `MediaReplacer` matches `<img>` on `src` **or** `data-src` (lazy images), regenerates the entire `srcset`/`data-srcset` from the new attachment (never collapses to one URL), aligns `width`/`height`, and only then literal-swaps remaining (background) references. Non-library images fall back to single-URL swap (flagged in the panel).

---

## Render Lifecycle & Dashboard Flow

The render manifest (`Manifest::RENDER_OPTION`) is **only** rebuilt by a render run — nothing renders on post save. The dashboard flow is **Pages-to-include → Process → Check → Sync** (or **Export ZIP** in place of Sync for a manual/no-FTP deploy):

- **Discovery mode** (`UrlCollector::mode()`): `linked` (home + link crawl, default), `all` (every published page/term), `manual` (per-post `_shipstatic_manual_include`, via `Admin\Editor`'s metabox + list "Static" column + bulk + front-end panel).
- **Process** = a `check`-type run (`Runner::start('check')`): renders for the current mode, refreshes the manifest, clears the dirty flag. The UI shows **Process** when the render is stale/missing and **View list** when current (`Status.renderCurrent`). View list = `GET /pages-overview` → Included (with `CompatibilityScanner` notices) / Excluded (with reason tags).
- **Check** = `GET /sync/check?dest=…` → `Runner::preview()`: a **synchronous, no-render** diff of the current render against the destination's pushed manifest. Returns `needsProcess` when the render is stale/missing (it never renders — that's Process's job). Shares `compute_check_preview()` with the in-job `finalize_check_preview()`.
- **Sync** = `Runner::start('sync')`: deploy + upload (see the deploy section above). **Holding page** (per-file `tick_upload` only — package/snapshot paths never use it): the home page (`Runner::home_key()` = `deploy_key('index.html')`, inside the sub-path folder when the upload root isn't the served path) always goes up last, but the "Currently synchronising" page replaces it **only when the destination has no home page yet** (`remote_has()`; unknown counts as present). Before 2.4.4 it went up on every per-file sync — visible on live sites ("This server" is always per-file) — and a sub-path destination's holding page landed on the domain root's `index.html` and was never replaced. **When `render_is_current()` it reuses the render** (`Runner::start_from_render()`: no render, straight to `start_target()` / the worker pool, like a retry; snapshot `reusedRender`/`reusedRenderAt`, from option `shipstatic_render_at` set at every finalize). **Render currency is one test** — `Runner::render_is_current()` (render format, WordPress's address at render time `shipstatic_render_home`, dirty flag, mode); `StatusController`'s `renderCurrent` and `in_sync()` call it rather than repeating parts of it (before 2.4.6 they skipped the format and address, so the dashboard said "In sync" while Sync would re-render, and a render from before an Undo published a sitemap with `/wp/`). `rerender` (REST param, `wp shipstatic sync --rerender`, the "Re-render before syncing" checkbox in All destinations) forces the full render; **scheduled syncs always pass it** (the safety net for changes the tracker can't see). A full multi-destination sync renders ONCE then deploys per destination; when eligible (>1 target, `shipstatic_concurrent_syncs`>1, WP-CLI spawn available) the deploy phase **fans out across N `deploy-worker` processes** (`Sync\DeployPool` + `Runner::run_deploy_worker`/`tick_deploy_monitor`) — see the [[concurrent-deploy-pool]] memory. **The spawned WP-CLI must not rely on the caller's PATH** (`System\WpCli`): the detection status is cached for every context, and PHP-FPM usually runs with an empty environment, so a bare `wp` that works from WP-CLI (where `/usr/local/bin` is on PATH) silently fails from the dashboard — Process then waits at 0/N for a runner that never started (first nginx VPS test, 2.4.5). A system runtime records the absolute `bin` (`command -v wp`, then `SYSTEM_CANDIDATES`) and every command is `env PATH=<bin dir>:/usr/local/bin:/usr/bin:/bin <bin> …`, which also lets the phar's `#!/usr/bin/env php` find php. Bump `CACHE_SCHEMA` when the cached shape changes. The sequential per-target path is the untouched fallback. Destinations are unlimited.
- **Deploy method** (`DestinationPanel.svelte`): a `Segmented` choice **FTP / SFTP** vs **Connector (HTTPS)** vs **Cloudflare** vs **GitHub Pages** vs **This server** — it IS the destination's saved `transport` (`connector`, `cloudflare`, `github`, `folder` are the 4th–7th `Schema` enum values), so exactly one applies per destination and Sync/single-page sync use whichever is saved. Destination URL + sub-path sit above the choice (every method uses them). **Manual ZIP is not a transport** — it's an always-available "Manual deploy" section under the form (Export ZIP button + collapsible how-to); it moved off the toolbar and no longer requires a tested connection, only an enabled destination.
- **Connector transport** (`docs/CONNECTOR_PLAN.md`, SECURITY_PATTERNS.md §3b): `Deploy\ConnectorScript` renders `src/Deploy/templates/connector.php.txt` (a lintable template with five placeholders, deliberately not a heredoc) into `bs-connector-<id>.php`, which the user uploads once to the destination web root. `Deploy\ConnectorClient` speaks signed HTTPS to it; `Transport\ConnectorTransport` implements `TransportInterface` (so every Runner path works unchanged) **and** `Transport\PackageCapableTransport`, which `PackageDeployer::deploy()` branches on to send the zip in chunks + `extract` instead of uploading the one-shot `UnzipScript` helper. Pairing lives in `internal` Schema fields written only by `Destinations::pair_connector()` / `revoke_connector()`; REST: `POST /destinations/{id}/connector/pair|revoke|rebuild`, `GET /connector/download?token=` (single-use). Test = the receiver's `ping`. **Rebuild push record** (`Runner::rebuild_pushed_record()`, from the receiver's `manifest` op) re-establishes the pushed manifest after a reset/re-pair without re-uploading identical files — it only records paths in the current deploy manifest (a remote-only file is never recorded, so prune can't touch it), and matches by md5 of the local deploy copy, mapping to the stable hash. `ServerProbe` prefers the receiver's `SERVER_SOFTWARE` over the HTTP `Server` header for connector destinations. WP-CLI: `wp shipstatic connector pair|test|rebuild|revoke|status` (`--dest-url`, not `--url`, which WP-CLI reserves). The receiver's `$safe`/gzip-sibling logic mirrors `UnzipScript` — keep the two in sync (both are commented to say so). **nginx:** its `client_max_body_size` (1 MB default) is invisible to PHP, so `ping`'s `uploadMax` can't report it — chunks default to `ConnectorClient::SAFE_CHUNK` (900 KiB, filter `shipstatic_connector_chunk`), `upload()` halves the chunk and resends the same offset on an HTTP 413 (`ConnectorTooLargeException`), and `ConnectorTransport` remembers the size that worked per destination URL (transient `shipstatic_conn_chunk_<md5(url)>`). 413/405/502–504 get their own messages; the panel's "Using nginx?" notice and the nginx snippet (`templates/nginx.conf.txt`) carry the minimum setup.
- **Cloudflare transport** (`docs/CLOUDFLARE_PLAN.md`, SECURITY_PATTERNS.md §3c) publishes to an **assets-only Cloudflare Worker** (Workers static assets — chosen over Cloudflare Pages: documented upload API, SHA-256 keys PHP has natively, and where Cloudflare's investment goes; the UI just says "Cloudflare"). It is the first **snapshot** transport: `Transport\CloudflareTransport` implements `Transport\SnapshotCapableTransport::deploy_snapshot($files)` and `Runner::start_target()` routes any `Destination::is_snapshot()` destination to the **`snapshot` phase** (`tick_snapshot()`), which hands over the FULL deploy manifest — Cloudflare dedupes by content hash (`sha256(base64(bytes)+ext)[:32]`) and asks only for missing files (`Deploy\CloudflareClient`: upload session → per-bucket multipart upload → `PUT` Worker with metadata only, no script), then the version goes live atomically. So the snapshot path **skips** `.htaccess`, holding page/home-last, `.gz` siblings, `PackageDeployer`/`UnzipScript` and prune (a removed page is just absent). Cache policy = `Deploy\CloudflareHeaders` (`_headers` sent in `assets.config`, filters `shipstatic_cloudflare_headers`/`shipstatic_cloudflare_redirects`); `html_handling: auto-trailing-slash`, `404.html` → `not_found_handling: 404-page`; files > 25 MiB are left out and reported (recorded as failed, kept out of the pushed manifest). Fields `cfAccountId`/`cfWorker`/`cfApiToken` (secret) with strict `Schema` validators (they go into API paths). Test = `GET …/workers/subdomain` and returns `suggestedUrl` (the workers.dev address), which the panel puts in an empty Destination URL; workers.dev is enabled only when the Destination URL is empty or a workers.dev host. WP-CLI: `wp shipstatic cloudflare set|test|status` (`--token-from-env`). Don't special-case `'connector'` / `'cloudflare'` / `'github'` for "has no FTP host" — use `TransportFactory::needs_host()` / `is_snapshot()`.
- **This server (folder) transport** (`docs/SERVER_FOLDER_PLAN.md`, SECURITY_PATTERNS.md §3e): `Transport\FolderTransport` writes the export straight into a folder on the WordPress server — the 7th `Schema` enum value `folder`. An ordinary per-file transport (holding page, `.htaccess` merge, `.gz`, prune unchanged); `root_is_served_path('folder')` (the folder IS the served root); never package mode. `folderPath` is an `internal` field written only by `Destinations::set_folder()` after `Deploy\ServerFolder::prepare()` validated it; `ServerFolder::inspect()` re-runs on every connect. Two layouts: **domainRoot** (the folder WordPress is directly in — one domain, WordPress at `/wp`; its folder protected, and `HtaccessBuilder::merge($existing, $wp_dirs)` keeps the static cache headers off it) and **separate** (a folder beside WordPress that another domain serves). Ownership marker `.shipstatic-folder`. Setup: `FolderWizard.svelte` (from the destination's **This server** method) → REST `POST /server-folder/check` (suggestions + inspect, no side effects) and `POST /destinations/{id}/folder` (`ServerFolderController::run()`: create/adopt via `Runner::rebuild_pushed_record()` + probe); WP-CLI `wp shipstatic folder suggest|check|setup|test` (`--folder`, `--dest-url`, `--adopt`, `--replace-index`). Test probes the Destination URL with a random file in a new sub-folder to prove it serves the folder. **Never `wp_mkdir_p()` for published folders**: it copies the parent's permissions, and cPanel's `public_html` is 0750 (group `nobody`), so every folder below would be unreadable to the web server — all pages but the home page 404'd on the first live test. `FolderTransport::ensure_dir()` creates each level with `ServerFolder::dir_mode()` (0755). **Move WordPress into /wp** (`Deploy\WordPressMove`, SECURITY_PATTERNS.md §3f, plan §8): when WordPress sits at its domain's document root, `FolderWizard` shows the move flow instead of its steps (REST `POST /wordpress-move` `{id, confirm}`, `/undo`, `/forget`; WP-CLI `wp shipstatic move-wordpress check|run|undo`). Database first (`Support\SerializedReplace::url_subpath()` + path rewrite into `wp/`), then the files are renamed into `wp/` in the request's last shutdown function after the response is flushed — nothing may load a moved file — then the calling destination becomes "This server" at the root. `Status.wpMove` drives `WpMoveBanner.svelte` (Keep it / Undo the move). Record option `shipstatic_wp_move`. **nginx:** there is no `.htaccess`, so before the move the site's server block needs `location /wp/ { try_files $uri $uri/ /wp/index.php?$args; }` — without it every non-file address under `/wp/` falls to the root's (moved) `index.php` and 404s, the REST API included, so the dashboard and its Undo button die. The pre-flight (`check()['nginx']`) shows `nginx_rule()` and blocks until `probe_nginx()` (temporary `/wp/index.php` echoing a token, a request to a made-up `/wp/` address, both removed) proves it's live; `ok: null` = not probed because another problem comes first. A successful move writes the destination's conn-state option (precomputed as `connOption` in the record, since `DestinationsController` moves with wp-content) so Sync is ready without a Test. **Undo** removes the static site's published files before moving WordPress back; its conflict check uses `only_published()`, because the export publishes its own `wp-content/`/`wp-includes/` asset copies at the root (before 2.4.5 Undo always refused with "wp-includes already exists"). Test host for all of this: `docker/nginx-wp/` (README there). With WordPress at its domain root the wizard opens on the move flow, but it can switch to its ordinary steps ("Publish to another domain"), and does so by default when the Destination URL is on another host; `ServerFolder::suggestions($doc_root, $dest_url)` offers the sibling folder named after that host (`/var/www/static.example.com`). Folder setup switches the destination on once its probe passes (Undo of a move leaves it off and clears the connected state). The wizard's reset `$effect` runs `untrack(reset)` — it read `destUrl`, which setup changes, and jumped back to step 1.
- **GitHub Pages transport** (`docs/GITHUB_PAGES_PLAN.md`, SECURITY_PATTERNS.md §3d) commits the export to a branch via the Git Data API (`Deploy\GitHubClient`, `Transport\GitHubTransport`) — the second snapshot transport and the first **resumable** one (`Transport\ResumableSnapshotTransport::deploy_snapshot_step($files, &$state)`): GitHub allows ~500 writes/hour, so `Runner::tick_snapshot()` runs one ~20 s step per tick with progress in `job->data['snapshotState']`, and on a rate limit stores `snapshotWait` and only beats until then. Diff = git blob SHA (`sha1("blob <len>\0" . bytes)`) computed locally vs the branch's recursive tree; text inline in create-tree, binaries as blobs; ONE commit; branch moved `force: false` (conflict → rebuild once). Folder-scoped ownership (`/` or `/docs`), keep list (`CNAME`, README, LICENSE, `.github/`), always `.nojekyll`. **The export's sub-path folder is published at the repo root** (a project site's root is `/<repo>/`). Test = repo read + empty-blob write probe (`permissions` on the repo is the account's role, not the token's) + Pages status; no "turn Pages on" (needs Administration: write — `gh-pages` auto-enables anyway). Fields `ghOwner`/`ghRepo`/`ghBranch`/`ghFolder`/`ghToken`. WP-CLI `wp shipstatic github set|test|status`.
- **The sub-path comes from the Destination URL** (`Destination::served_base_path()`): its path when a URL is set (a `SHIPSTATIC_BASE_PATH` constant still wins), else the "Served from sub-path" field — the panel shows the derived value read-only. It is **per destination**: the render is destination-neutral (render format 2, `Runner::RENDER_FORMAT`) — files at plain paths (`Url::remap_site_path()` only strips the home sub-path) and links carrying `Url::BASE_TOKEN` (`/__shipstatic_base__`), inserted by `UrlRewriter::rewrite()` exactly where the source origin / home sub-path was. **Only whole references to this site are rewritten** (`UrlRewriter::replace_origins()`): the host must end there (not `host.evil.com`, `host:8443`, `host@…`), nothing may run into it from the left (`ftp://host`), and it must start its own URL — one inside another URL's query (a share link's `?u=`, Wayback, an embed's `origin=`) is left alone. A bare origin (`https://host`, `https://host?x` — Bricks' logo link) keeps a `/` after the placeholder: the placeholder alone becomes `""` for a root destination, and an empty href is the current page (2.4.5, `RENDER_FORMAT` 3). `Url::rebase()` swaps the JSON-escaped placeholder before the plain one (the plain one matches inside it). `Url::is_internal()` treats every non-http(s) scheme as external and requires the home port; the crawler (`Job::enqueue_page()`) also requires `Url::in_home()` — with WordPress on a sub-path, the rest of the host isn't WordPress (with "This server" at the root it's the published site), and a `/` link there maps to the same `index.html` as WordPress's home: before 2.4.7 it rendered the holding page over the real home; `PageRenderer` follows redirects only while they stay on the site. `Runner::build_deploy_manifest()` gives each destination its own: `Url::rebase()` fills its served path into every `.html` and `.css` (after the replacers, whose keys match the render), `to_absolute($c, $dest_base, $served)` does sitemap/robots, and `file_prefix()`/`relocate()` move keys into the sub-path folder — except for transports whose upload root IS the served path (`TransportFactory::root_is_served_path()`: GitHub) and except `robots.txt`/`favicon.ico`, which stay at the domain root. Use `Runner::deploy_key($rel, $dest)` to look a render path up in a pushed manifest. **Redirects to another site** (link-tracking / short links: `/go/partner` → 301 → partner.com): `PageRenderer::get()` stops at an off-site redirect and reports it as `location`; the page is skipped with a reason naming the target (`http_skip_reason($code, $location)`), `Runner::note_redirect()` records link path (`strip_base_token(UrlRewriter::rewrite($url))`, both slash forms) => target in option `shipstatic_render_redirects` (replaced by a full render, merged by partial ones), and `LinkDeployReplacer` folds them under the destination's own link swaps, so links to them point straight at the target instead of 404ing on the static site; the pages overview tags such posts "Redirects to another site". Code reading the cached render maps placeholder paths back with `Url::to_source_path()` (Images/Videos panels) / `Url::strip_base_token()` (Links panel + `LinkReplacer` keys; a root-relative swap target gets the token re-added). Never upload straight from `Manifest::RENDER_OPTION` — it still has the placeholder.
- **Export ZIP** (`Export\ExportRunner`) = a fully separate, lightweight job/option (`shipstatic_export_job`) from Sync's `Job`/`Runner` — packages the destination-scoped deploy manifest (`Runner::deploy_manifest_for()`, the same replacements a real Sync would apply) into a downloadable zip, batched across `preparing → gzip → packaging → saving → done` ticks. Uses the CURRENT render only (`Runner::render_is_current()`, same `needsProcess` contract as Check — never renders itself), and never touches a destination's push-manifest/stats since nothing is uploaded. Mutually exclusive with Sync/Check in both directions. Download is a single-use transient-token `GET /export/download` REST route (deliberately not `admin_post_*`, to keep SECURITY_PATTERNS.md's "REST-only" invariant accurate). Zips ship `.gz` siblings (when the host supports gzip) alongside `sitemap.xml`/`robots.txt` (`Pipeline::extra_file_emitters()`).

**Scheduled sync** (`Sync\Scheduler`, REST `GET/POST /schedule`, dashboard **Schedule** tab = `SchedulePanel.svelte`): option `shipstatic_schedule` {enabled (off by default), period hourly|daily|weekly|monthly (`shipstatic_monthly` = 30 days via `cron_schedules`), all, destinations}. The recurring WP-Cron event `shipstatic_scheduled_sync` starts an ordinary `Runner::start('sync', targets)` — never over a running job — handed to WP-CLI via `Background::spawn_run()` when possible; otherwise `drive()` ticks for ~25 s (`shipstatic_schedule_slice_seconds`), queues `shipstatic_scheduled_continue` and fires its own non-blocking wp-cron.php loopback on shutdown (core's `spawn_cron()` refuses inside a cron request). The job is tagged `scheduled`, which stretches `Runner::status()`'s stale reaper to 15 min. "All" resolves enabled ids at run time (new destinations included). Deactivation clears the events, activation restores them, uninstall clears them.

**Content-change tracking** (`Sync\ChangeTracker`, init on `init`): hooks `save_post`/`transition_post_status`/`deleted|trashed|untrashed_post`/`updated|added_post_meta` (only `_bricks*` keys) **and `wp_update_nav_menu`** (a menu edit changes the link graph + every page's chrome). It sets a dirty flag (`mark_dirty`) that `Runner::in_sync()` treats as out-of-sync and that flips `renderCurrent`; `mark_rendered($mode)` clears it at the end of a full render and records the mode. `UrlCollector::published_excluded_count()` powers the "*N not in export*" hint (published pages absent from the render). **Never auto-render on save** — flag-and-prompt only. It also records **which** public posts changed (`CHANGED_OPTION`, capped at 500) and **site-wide** changes separately (`SITEWIDE_OPTION`: menus, `bricks_template` posts, an explicit list of Bricks design options, widgets, site title/tagline/front page, permalinks, theme). **Process changes** (`Runner::start('check', ['changes' => true])`, REST `changes: true`, `wp shipstatic sync --check --changes`, toolbar button beside **Process all**) renders only `Sync\ChangedContent::plan()`: the changed posts, the listing pages already in the export that show them (blog/posts home, post-type and term archives), new pages they link to (the single-page `known` crawl), drops pages whose post left the export (via `LiveIndex::OBJECTS_OPTION`), rebuilds sitemap/robots for the merged page set, then `ChangeTracker::mark_partial()`. It refuses without a current full render or after a discovery-mode change, never clears a pending site-wide flag, and is documented as limited to known changes (Docs → `#process-changes`; the docs page scrolls to its hash on mount). In `App.svelte`, `processPages()` (Process all) takes no arguments on purpose — it's wired to onclick handlers whose event must not turn it into a partial run.

**Site search** (`docs/SEARCH_PLAN.md`, setting `shipstatic_search_enabled`, off by default): `Search\IndexBuilder` writes `search-index.json` per destination INSIDE `Runner::build_deploy_manifest()`, from each page AFTER its replacers + `rebase` (never from the render — the index must carry that destination's wording and URLs); folded into `sync_signature()`. `Frontend\Search` enqueues the standalone `src-svelte/search/main.ts` (no imports — it must ship as one file in the export) only on render requests, so the live site is untouched; it upgrades any form with an `s` field. Toggling it marks content dirty (pages gain/lose the script). The **ShipStatic Search** Bricks element (`src/Bricks/SearchElement.php`, registered in `Frontend\Search::register_bricks_element()`) and `[shipstatic_search]` share `Search\SearchForm` (markup + the `.bs-ss*`/`.bs-search*` class map its controls target; options reach the script as `data-bs-*`). Defaults live in `src-svelte/search/search.css`, every rule in `:where()` so Bricks controls always win; px not rem (Bricks sets `html{font-size:62.5%}`); colour fades via `color-mix()`, not `opacity`, so a set colour fully replaces them. **Live-site search** (opt-in `shipstatic_search_live`, `Search\LiveIndex`): the element/shortcode call `Frontend\Search::enqueue_live()`, which loads the script with `selector: 'form.bs-ss'` + `live: true` and the index at `GET /shipstatic/v1/search-index` — the plugin's ONLY public REST route (SECURITY_PATTERNS.md §0; keep its invariants). Built from the render cache (not WP_Query — Bricks content lives in post meta), cached in a transient keyed by the render's hashes, filtered per request by current post status. What each page *is* comes from the render itself (`PageRenderer::OBJECT_HEADER` on trusted render requests → `LiveIndex::OBJECTS_OPTION`), never from `url_to_postid()` in the visitor's request (which can't see private posts); unknown pages are dropped. Plain search forms on the live site are never upgraded. Writing Bricks element data from WP-CLI needs `--user=<admin>` — Bricks silently drops `_bricks_page_*` updates without a capable user.

**Lockdown** (`src/Lockdown/`, docs/LOCKDOWN_PLAN.md, SECURITY_PATTERNS.md §11, dashboard **Lockdown** tab = `LockdownPanel.svelte`, REST `GET/POST /lockdown`, `POST /lockdown/key|probe`, WP-CLI `wp shipstatic lockdown`): locks the WordPress site (which in the intended setup has no public purpose) to an IP allow-list and 302s everyone else to the same path on a destination. Layers, all generated from option `shipstatic_lockdown` by `Lockdown\Writer` from `templates/*.txt`: the **gate** `wp-content/shipstatic-lockdown.php` (always), an **mu-plugin** that requires it (always; fails open when ShipStatic is inactive), a block at the **top of `.htaccess`** (Apache/LiteSpeed — IPs as `Cidr::regex()` REMOTE_ADDR regexes, since LiteSpeed's `RewriteCond expr` support is unknown), and **`.user.ini` → `auto_prepend_file`** whenever PHP runs as FPM/CGI — on nginx, and next to `.htaccess` on Apache + PHP-FPM (chains any existing prepend, e.g. Local's `local-bootstrap.php`). The `.htaccess` redirect is built from `%{REQUEST_URI}` (a condition that always matches), and `RewriteOptions InheritDownBefore` is added only after `Writer::inherit_supported()` proves this Apache accepts it with a throwaway uploads folder (option `shipstatic_lockdown_inherit`) — so a sub-folder with its own `RewriteEngine On` can't escape the block. The optional nginx snippet redirects with `$request_uri`, never the decoded `$uri`. It goes in the whole `server { }` block, so with WordPress in a sub-path it locks **only that path** (`$uri !~ "^/wp(/|$)"` unlocks the rest) and drops the sub-path from the redirect — after Move WordPress into /wp the static site shares the block, and a server-wide lock redirected strangers to the same URL forever. The prefix match must also stop at `?` (`$request_uri` keeps the query), or `/wp?x` redirects to itself. On nginx without that snippet only PHP is gated: nginx serves uploads, CSS and JS itself, so they stay reachable by direct URL — the panel's snippet help and Docs → Lockdown say so; keep that warning if the wording changes. Server facts come from a web request (`Writer::remember_environment()`, option `shipstatic_lockdown_env`) — WP-CLI can't see them. Passes: allowed IP, `?unlock=<key>` (adds the visitor's one address — /32 or /128, never a range; turning Lockdown on does the same — sets a 12 h cookie, emails the admin; `Guard::handle_unlock()`), the unlock cookie, `Authorization: Bearer <unlock key>` (passes that request only — no address, cookie or email; the gate strips the header so auth plugins never see it), the render token (added to the site's own requests by `Guard::sign_own_requests()`, which is how WP-Cron/Site Health get through), `/.well-known/`. `Lockdown\Probe` makes an unsigned loopback request; the `X-ShipStatic-Lockdown` header (`gate` = prepended, `wordpress` = mu-plugin only, absent = `.htaccess`) says which layer stopped it. Deactivation removes every file and leaves Lockdown off. **Never delete the gate while `.user.ini` may point at it** (missing prepend = fatal on every request for up to `user_ini.cache_ttl`): `remove_all()` leaves a stub and deletes it 10 min later. **Post-enable checks retry** (`LockdownPanel.svelte` `settle()`: up to 5 tries, 2.5 s apart): LiteSpeed can take seconds to re-read `.htaccess`, so a first probe of `wordpress` or a failed browser check is not yet a failure. The browser check's 204 carries `X-ShipStatic-Address`; when it still fails and the browser has been seen on more than one address (mobile networks), the panel shows *Your address keeps changing* (unlock link / the /16 or /64) instead of *Your address isn’t getting in*, which is kept for a steady address that really fails. **Cron:** WP-Cron's own loopback is signed and CLI PHP is never gated, but a server cron job that fetches `wp-cron.php` over HTTP (`wget`/`curl`, an online cron service) is turned away — the panel's *Using a server cron job?* section gives `Settings::cron_command()` (command-line PHP) and warns when `DISABLE_WP_CRON` is set (also in `wp shipstatic lockdown status`). Don't auto-allow the server's own address: on shared hosting every account shares it.

**Head cleaning** (`Render\HeadCleaner::clean()`, gated by `shipstatic_clean_head`) runs in `Runner::tick_render` on the rendered HTML: strips WordPress-only `<head>` links (feeds, oEmbed, RSD, WLW, shortlink, `wp-json`) and all generator metas, then injects one `ShipStatic <ver> by BRXProd` generator. SEO/social meta is preserved. Operate on the HTML string at this choke point — render-time `remove_action`/output-buffering is unreliable.

**Timeouts** are filterable so a bump can't trip the watchdog: `shipstatic_render_timeout` (page, 60s), `shipstatic_asset_timeout` (dynamic asset fetch, 60s), `shipstatic_package_timeout` (remote extract, 120s), `shipstatic_stale_seconds` (`Runner` no-progress reaper, 90s). These bound *fetch/render during caching*, not upload (package mode bundles to one zip).

---

## First-Run Setup Wizard (`SetupWizard.svelte`, Free)

A 3-step onboarding flow (theme colour, pages-to-include, enable single-page sync) built on ab-ui's `Wizard` component (`orientation="vertical"` — horizontal wraps the longer step labels badly), mounted globally in `App.svelte` alongside `SettingsDrawer`, not per-panel. Finish calls the same `processPages()` used everywhere else (Process), then closes.

- **First-run detection**: option `shipstatic_wizard_seen` ('1'/'0' string, same convention as `shipstatic_fab_enabled`) — absence/`'0'` means unseen. No polling; computed once into `shipStaticData.isFirstRun` (`Admin\Menu::enqueue_app()`'s new `$extra` param, dashboard entry only — never leaks onto the docs page's `shipStaticData`).
- **Marked seen at open time, not at Finish** — so closing early or reloading mid-wizard never re-triggers it. `POST /settings {wizardSeen: true}` mirrors the `fabEnabled` handler in `StatusController::save_settings()` exactly.
- **`Plugin::activate()` (`register_activation_hook` in `shipstatic.php`) resets `shipstatic_wizard_seen`** — deactivating/reactivating the plugin (re-testing, or a cloned site that already has the option set) always shows the wizard again. This is the *only* activation hook in the plugin.
- **Manual re-run**: a small "Wizard" button lives in `SettingsDrawer`'s Drawer `title` (which accepts a `Snippet`, not just a string — the same trick works for any ab-ui `Drawer`/`Modal` header that needs an extra action button beside the title text).
- `lib/Modal.svelte` (the project's thin ab-ui Modal wrapper) gained an optional `size` prop (`'sm'|'md'|'lg'|'near'|'full'`) that overrides the older boolean `wide` prop when set — added because the wizard needs `'lg'`, not `wide`'s `'near'` (near-fullscreen) or the default `'md'`. Fully backward compatible; every existing `wide` caller is untouched.

---

## AI / MCP — Abilities API (`src/Abilities/`)

When the host exposes the WordPress Abilities API (`function_exists('wp_register_ability')`, WP 6.9+), `Abilities\AbilityRegistry::init()` registers `bs/*` abilities on `wp_abilities_api_init`. Three tiers, gated by two opt-in options (both default off, surfaced as `aiAllowChanges`/`aiAllowSync` and saved via `POST /settings`):

- **Read-only** (always on): sync status, sync method, list pages, page sync status, link integrity, list destinations, sync progress.
- **`OPT_CHANGES` (`shipstatic_ai_allow_changes`)**: set discovery mode, include/exclude page(s).
- **`OPT_SYNC` (`shipstatic_ai_allow_sync`)**: scan (dry run), sync, single-page sync, cancel, reset.

Abilities are thin wrappers over the same `Runner`/`UrlCollector`/`Destinations` seams the REST controllers use — never duplicate logic; add an ability by wrapping an existing seam and gating it on the right tier.

---

## Internationalization (i18n) — PHP dictionary, NOT `@wordpress/i18n`

UI strings are localized with a **PHP-dictionary** pattern, deliberately not `@wordpress/i18n` + `wp_set_script_translations()` (whose md5-of-source-path `.json` matching is brittle with Vite's hashed/code-split bundles — translations silently vanish). This mirrors the sibling `ab-bricks-productivity` plugin.

- **Strings live in PHP:** `src/Support/I18n.php` (`I18n::all()`, domain `shipstatic`), `'jsKey' => __('English', 'domain')`. One `.mo` translates both PHP and JS.
- **Bridge:** `Admin\Menu` localizes `I18n::all()` → `window.shipStaticData.i18n`. The Svelte helper `src-svelte/shared/i18n.ts` exposes `__()` / `__f()` (sprintf `%s`/`%d` + positional `%1$s`) reading the dict; components call `__('jsKey')`. Strings with inline markup use `{@html __(...)}` (trusted dictionary only).
- **Loading:** `load_plugin_textdomain` on `init`; `.mo` in `languages/`. Every JS-consuming bundle is `type="module"`, so the helper's ES `import` is fine (no IIFE concern — that rule is only for a non-module `shared.js`, which this plugin doesn't ship).
- **Regenerate:** `wp i18n make-pot . languages/shipstatic.pot --domain=shipstatic --slug=shipstatic --exclude=node_modules,vendor,dist,assets/dist,src-svelte,scripts,docker` (`--slug` is required: the dev folder is still `bricks-static`, and make-pot builds the bug-report URL from the folder name) → `python scripts/i18n_build.py extract` → translate into `scripts/i18n/<locale>.json` → `python scripts/i18n_build.py assemble` (writes `.po` + `.mo`). Shipped: fr_FR, de_DE, it_IT, es_ES, nl_NL. Scope so far: dashboard (Docs prose left English). The 2.0.0 merge added the former Pro dictionary's `links()`/`videos()` sections under the `shipstatic` domain — the shipped `.mo` files need regenerating via the pipeline above to translate them.

## Description

The **ShipStatic** plugin generates and serves static HTML versions of pages built with the Bricks builder. By rendering Bricks pages to static HTML and serving them directly, it reduces server load and improves front-end performance.


