# Changelog

All notable changes to **ShipStatic** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.4.9] - 2026-10-28

### Added
- **Bricks forms, query filters and AJAX loops work on the static site** (experimental; setting "Bricks forms and filters use WordPress", option `shipstatic_bricks_relay`, on by default; `wp shipstatic relay on|off|status`). The render rewrote `bricksData.ajaxUrl` and `restApiUrl` onto the static site, where they don't exist, so forms silently did nothing and filters 404'd. A new deploy replacer (`Bricks\RelayReplacer`, part of `sync_signature()`) points `ajaxUrl` back at WordPress's `admin-ajax.php` (root-relative on WordPress's own origin, else the full URL) and `restApiUrl` at a per-destination relay.
  - **Forms:** submissions run on WordPress as usual. Bricks' fresh-nonce retry (`bricks_regenerate_form_nonce`) heals the stale exported nonce. A Redirect action's address is mapped to the same page on the destination the form came from (`bricks/form/response`). Destination origins are added to `allowed_http_origins` so a static site on another domain can read the reply.
  - **Filters, sorting, live search, AJAX pagination, load more, infinite scroll and AJAX popups:** `POST /shipstatic/v1/bricks/<destination id>/(query_result|load_query_page|load_popup_content)` (`REST\BricksRelayController`, the second public route) hands the request unchanged to Bricks' own route (its nonce and visibility checks), then rewrites the reply's HTML/CSS for that destination (`Runner::transform_for_destination()`: addresses, Text/Link replacements, sub-path), so results link to and show images from the static site.
  - **Filtered links** (`?brx_…`) opened directly now show filtered results: an inline script, added only to pages with Bricks filters, applies them on load.
  - **Images:** when any page has filters, load more or infinite scroll, each sync uploads every size of every published post's featured image, so results never shown on an exported page have their pictures.
  - **Lockdown** lets these requests through and nothing else (SECURITY_PATTERNS.md §11): POSTs to the exact `admin-ajax.php` path for the form/nonce actions only (no `action` from the query string or a cookie), and POST/OPTIONS to the exact relay route (no `rest_route` and no query parameter but `lang`), in the gate, `.htaccess` and the nginx snippet. Toggling the setting rewrites the Lockdown files (`Writer::VERSION` 6). Marked experimental in Settings, the Lockdown tab and the Docs.
- Documentation: "Forms & filters".

### Changed
- The pages overview no longer flags Bricks forms as "won't work statically" while the setting is on.
- Needs pretty permalinks for the filters relay (as the export does).

## [2.4.8] - 2026-10-28

### Changed
- "This server" wizard: with WordPress at its domain's root, the first step is now **Layout** — "At <domain>, with WordPress moved into /wp" (→ a Move WordPress step) or "On another domain or subdomain, with WordPress left where it is" (→ Where / Check / Create and test / Done). Before, it opened on the move flow with a "Publish to another domain" button, and the other path's "← Move WordPress into /wp instead" button sat beside a Back that was disabled on its first step — users found Back confusing. The choice is a real wizard step, so Back from either path returns to it. The other-domain option is pre-selected when the Destination URL is on another host.
- "This server" wizard: the other-domain choice and the Where step now say what the host must provide — a domain served from a folder on this server (cPanel: Domains → document root; a VPS: a second nginx server block or Apache virtual host), otherwise use Connector or FTP. Translated into fr/de/it/es/nl.

## [2.4.7] - 2026-10-27

### Fixed
- "This server" with WordPress on a sub-path (`/wp`) and the destination at the domain root: the static home page stayed "Currently synchronising". The crawler followed same-host links outside WordPress's path (a plain `href="/"`), and `/` maps to the same `index.html` as WordPress's home, so the published holding page was rendered over the real home on every sync. `Job::enqueue_page()` now also requires `Url::in_home()` (the URL's path is inside WordPress's address).

## [2.4.6] - 2026-10-27

### Fixed
- Export: link-tracking and short-link URLs on the site (`/go/partner` → 301 → partner.com, from a URL manager, Pretty Links, ThirstyAffiliates) were skipped as just "HTTP 301", and links to them stayed in the static pages, where they 404. The skip reason now names the target (`PageRenderer::get()` reports an unfollowed redirect's `location`; `Runner::http_skip_reason($code, $location)`), the render records them (`shipstatic_render_redirects`, link path => target), and the Links deploy step points links straight at the target on every destination (a hand-set link swap wins). Clicks from the static site aren't counted — the tracker runs in WordPress. A redirect loop gets its own reason. The pages overview tags such posts "Redirects to another site".
- "This server", two domains: with WordPress at its domain root the wizard only offered Move WordPress into /wp, so a static site on another domain whose folder sits beside WordPress couldn't be set up from the dashboard. It can now switch to its ordinary steps ("Publish to another domain"), does so by default when the Destination URL is on another host, and suggests the sibling folder named after that host (`/var/www/static.example.com`; also `wp shipstatic folder suggest --dest-url=`).
- "This server" wizard: after "Create and test" it jumped back to step 1 instead of showing the result whenever the saved address differed from the typed one (e.g. an added trailing slash).
- "This server" setup now switches the destination on once its test passes (undoing a WordPress move leaves it off, so Sync refused with "Enable this destination first"), and the Enabled switch follows such changes without a reload.
- Undo of Move WordPress into /wp left the destination showing "Connected".
- A render made before WordPress's address changed (moving it into /wp or back, a migration) was reused: after an Undo the sitemap listed `/wp/` pages. The render records the address (`shipstatic_render_home`) and is out of date when it changes.
- The dashboard's "renderCurrent" and "In sync" repeated part of the render-currency test and missed the render format and address — after the 2.4.5 update it showed "In sync" and no Process although Sync would re-render. Both now use `Runner::render_is_current()`.
- Lockdown, optional nginx snippet: after Move WordPress into /wp the static site shares the server block, and the snippet locked the whole block — strangers were redirected to the same URL forever (and `/wp?x` to itself). It now locks only WordPress's path and drops it from the redirect (`/wp/about/` → `<destination>/about/`), like the `.htaccess` rules.

### Added
- Documentation: "Some pages are skipped with “redirects to another site”" under Common issues.

## [2.4.5] - 2026-10-27

### Fixed
- Export: a link to the site with nothing after its address (`https://host` — how Bricks writes the logo link — or `https://host?x`, `https://host#top`) became an empty `href`, which browsers read as the current page: on `/about/` the logo linked to `/about/`. `UrlRewriter::rewrite()` now keeps a `/` after the base placeholder when no path follows (`/` or `/sub/` per destination). `Runner::RENDER_FORMAT` is 3, so the dashboard asks for Process all once after updating.
- Export: on destinations at a domain root, JSON-escaped site links (inline scripts, JSON-LD) were left with a stray backslash (`"\/about"`, or `"\"` for the home page), corrupting the JSON. `Url::rebase()` now replaces the escaped placeholder before the plain one.
- Move WordPress into /wp, nginx: nginx has no `.htaccess`, so after the move every non-file address under `/wp/` (pretty links, the REST API — so the dashboard and its Undo) fell to the root's moved `index.php` and returned the static site's page ("Unexpected token '<' … is not valid JSON"). The pre-flight now shows the `location /wp/ { … }` rule to add, with Copy and Check again, and blocks the move until a probe (a temporary `/wp/index.php`, removed afterwards) proves it's live. Also in `wp shipstatic move-wordpress check`.
- Move WordPress into /wp: a site on a non-standard port got URLs like `/wp:8090` (the port is now part of the host in `SerializedReplace::url_subpath()`).
- Move WordPress into /wp: Sync is ready straight after the move, without a connection Test.
- Undo the move refused with "wp-includes already exists": the export publishes its own copies of `wp-content/`/`wp-includes/` assets at the root. Undo now accepts folders that hold only published files.
- Process and Sync from the dashboard waited at 0/N forever on servers where PHP-FPM runs with an empty environment (common on VPSs): the background WP-CLI process was started as a bare `wp`, which only the command line could find. It now runs by absolute path with a safe `PATH`.
- Lockdown on nginx / PHP-FPM: the check right after turning it on took ~25 s, retrying the expected first answer ("stopped inside WordPress", while `.user.ini` takes up to 5 minutes to apply) as if it were a failure. It reports it straight away with the existing "check again in 5 minutes" note.
- "This server" folder wizard: its messages had no styling; they are proper notices now.

### Added
- Documentation: the nginx rule for Move WordPress into /wp, and the optional `error_page 404 /404.html;`.

## [2.4.4] - 2026-10-27

### Fixed
- Sync: the "Currently synchronising" holding page replaced the home page on **every** file-by-file sync, so visitors to a live site could see it while an update uploaded ("This server" always uploads file by file; FTP/SFTP/Connector do when package deploy isn't available). It now goes up only when the destination has no home page yet (the first Sync; `Runner::remote_has()`, where an unknown answer counts as present). The home page still goes up last, and an unchanged one is no longer re-uploaded.
- Sync: for a destination in a sub-path folder over FTP/SFTP/Connector, the holding page was written to the domain root's `index.html` and never replaced, and the sub-path home page wasn't held back until last. The home page is now the destination's own key (`Runner::home_key()` = `deploy_key('index.html')`).
- Sync: cancelling after the holding page went up restored the first destination's home page instead of the one being synced.

## [2.4.3] - 2026-10-26

### Added
- Lockdown: the unlock key also works as a request header, `Authorization: Bearer <key>`, for scripts and services (uptime monitors, deploy hooks, web cron). It passes that request only — no address added, no cookie, no email. Checked in every layer: the `.htaccess` block (which now also copies the `Authorization` header into `HTTP_AUTHORIZATION`, since PHP-FPM drops it), the gate, and the nginx snippet. "Bearer" matches in any case, the key exactly. The gate strips the header after a match, so an auth plugin (JWT) never takes the key for its own token. `Lockdown\Writer::VERSION` is 4, so existing sites rewrite their files on the next admin visit.
- Lockdown tab: the header ready to copy under the unlock link, and a web cron command (`wget --header='Authorization: Bearer <key>' …/wp-cron.php`) in "Using a server cron job?". The `DISABLE_WP_CRON` warning and `wp shipstatic lockdown status` mention it.
- Documentation: Docs → Lockdown, readme FAQ "Can a cron job or script get past Lockdown?", `docs/LOCKDOWN_PLAN.md`, SECURITY_PATTERNS.md §11.

## [2.4.2] - 2026-10-26

### Fixed
- Lockdown: the check right after turning Lockdown on could report a false failure on LiteSpeed, which takes a few seconds to re-read `.htaccess` ("Your address isn’t getting in", "strangers are only being stopped inside WordPress"). The stranger check and the browser check now retry (up to 5 tries over ~10 s, shown as "Checking…") before reporting.
- Lockdown: when the browser check still fails and this browser has reached the server from more than one address (mobile connections), the tab says "Your address keeps changing" and offers the unlock link or the provider's range (/16, or /64 for IPv6) instead of reporting you locked out. The 204 from the browser check now feeds the address it came from (`X-ShipStatic-Address`).
- Lockdown: the warnings with a button ("Your address isn’t getting in", "Files changed") showed only the button — ab-ui's `Alert` renders its children instead of `description`. Their text is back.

### Added
- Lockdown: **Using a server cron job?** section on the Lockdown tab, with this site's command-line cron command (`php …/wp-cron.php`). A server cron job that fetches `wp-cron.php` over the web (wget, curl, an online cron service) is turned away by Lockdown; WordPress's own WP-Cron and command-line PHP never are.
- Lockdown: a warning on the tab and in `wp shipstatic lockdown status` when `DISABLE_WP_CRON` is set while Lockdown is on.
- Documentation: Lockdown "Server cron jobs", the post-enable check, and changing mobile addresses.

## [2.4.1] - 2026-10-26

### Fixed
- "This server": every page except the home page returned 404 on cPanel. Folders were made with `wp_mkdir_p()`, which copies the parent's permissions — cPanel's `public_html` is 0750 (group `nobody`), so the web server couldn't enter them. `FolderTransport` now creates each folder 0755 (`ServerFolder::dir_mode()`), the moved `/wp` folder is set to 0755, and Test's probe file goes in a new sub-folder so a folder the web server can't read fails Test instead of every page.

## [2.4.0] - 2026-10-26

### Added
- **"This server" deploy method** (`Transport\FolderTransport`, transport id `folder`): writes the export straight into a folder on the WordPress server. Two layouts: the folder WordPress sits in (one domain, WordPress at `/wp`, its folder protected) or a folder beside WordPress that another domain serves. Every write is confined to the folder (no dot segments, no symlinks out, never WordPress's folder, `cgi-bin` or `.well-known`, never a PHP file or `.user.ini`); an ownership marker (`.shipstatic-folder`) is required; prune touches only files ShipStatic published. The static `.htaccess` keeps a host's other lines (cPanel's PHP handler) and keeps its cache headers off the WordPress folder below it.
- **Setup wizard** ("Set up the folder"): suggests the domain's folder or a sibling that already holds a site, checks the folder, creates it or takes over an existing static site without rewriting matching files, and tests that the Destination URL serves it (probe file). REST `POST /server-folder/check`, `POST /destinations/{id}/folder`; WP-CLI `wp shipstatic folder suggest|check|setup|test`.
- **Move WordPress into /wp**: for a site installed at the domain root, the wizard moves WordPress's own files into `/wp`, gives its address and content links `/wp` (serialized-data aware), switches the destination to "This server" at the root and shows a holding page until the first Sync. Pre-flight checks, a dashboard notice with **Undo the move**, REST `POST /wordpress-move[/undo|/forget]`, WP-CLI `wp shipstatic move-wordpress check|run|undo`.
- Documentation: "Publish on this server".

## [2.3.3] - 2026-10-26

### Fixed
- A destination on the same machine (Herd/Valet `*.test`, Local `*.local`, `localhost` on any port) failed Connector Test, package deploy and the server check with "A valid URL was not provided": `UrlSafety` allows loopback for local development, but then passed `reject_unsafe_urls` to WordPress, whose own check refuses every loopback address and any port but 80/443/8080. That check is now skipped only for a development host that resolves to loopback alone; LAN, link-local and cloud-metadata addresses stay blocked, and a public hostname pointing at 127.0.0.1 is still refused.

## [2.3.2] - 2026-10-26

### Changed
- **Lockdown adds a single address**: turning Lockdown on and the unlock link now add only the visitor's own address — `/32` for IPv4 and now `/128` for IPv6 (the unlock link used to add an IPv6 visitor's whole `/64`). Ranges are only ever added by hand; the list's help suggests adding your `/64` if your IPv6 address changes daily.
- Single addresses are shown without their `/32` or `/128` suffix in the Lockdown list, `wp shipstatic lockdown status` and the "address added" email; ranges keep theirs.

### Fixed
- Lockdown on nginx didn't say what it leaves open: without the optional nginx snippet only pages and PHP files are locked, and nginx still serves uploads, CSS and JavaScript to anyone with their address. The snippet's help text (which said "Lockdown works without it") and Docs → Lockdown now explain this.

## [2.3.1] - 2026-10-26

### Added
- **Process changes**: re-renders only the content recorded as changed since the last Process all — added, edited, unpublished or deleted posts, the listing pages that show them (blog/home, post-type and term archives), new pages they link to — removes pages whose post left the export and rebuilds the sitemap. Site-wide changes (menus, Bricks templates/design options, widgets, site settings, permalinks, theme) are tracked separately and the toolbar says when only **Process all** (the renamed full Process, still the safe choice) will do; it also says when a new page isn't linked from the export yet. Documentation section "Process all vs Process changes". WP-CLI: `wp shipstatic sync --check --changes`.
- **Sync reuses an up-to-date render** instead of rendering every page again; the progress panel says which render it used. A **Re-render before syncing** option (and `wp shipstatic sync --rerender`) forces a full render; scheduled syncs always re-render.
- The Images and Videos replacers' page picker is **filterable**, and lists published pages that aren't in the export greyed out, with why.
- Connector: a **"Using nginx?"** notice with the minimum nginx setup (opens when Test detects nginx); the nginx snippet now also blocks `.htaccess` files and in-progress Connector uploads.

### Fixed
- External URLs were changed by the URL rewriter: share links carrying the site URL in a query string, look-alike hosts, other ports and `ftp://` links are now left alone; non-http(s) links are never crawled; `srcset` values with commas in a URL are parsed correctly; page fetches no longer follow redirects to other sites.
- The setup wizard ran a full Process without saying so: its new last step explains Process and lets you skip it, and Finish closes the wizard straight away.
- The Connector failed on nginx (requests over nginx's default 1 MB limit): uploads go in pieces under 1 MB, shrink automatically if refused, and 413/405/502–504 errors get clear messages.
- Security review fixes: values inserted into exported HTML attributes can no longer break out of them (a crafted video URL); the live-site search index no longer keeps pages unpublished since the last Process; Lockdown's nginx snippet can't be used for header injection, its `.htaccess` rules also cover sub-folders with their own rewrite rules and avoid a slow pattern; the plugin's cache folder is never published; Export ZIP no longer writes `.gz` files next to theme, plugin or core files.

## [2.3.0] - 2026-10-24

First public release.

### Added
- **Static export** of Bricks-built (and any WordPress) pages: discovery modes (linked crawl, all published, manual), Process → Check → Sync, Bricks-aware stable hashing so only changed pages upload, rendering through Bricks maintenance mode, head cleaning, favicon and asset handling, gzip pre-compression, remote pruning, sitemap.xml + robots.txt.
- **Deploy methods**: SFTP / FTP / FTPS, the Connector (signed HTTPS to a one-file receiver), Cloudflare (assets-only Worker), GitHub Pages (one commit per sync via the Git Data API, resumable under rate limits), and Export ZIP for manual deploys. Package (zip) deploy where the host supports it; concurrent multi-destination deploys via WP-CLI workers.
- **Unlimited destinations**, each with its own served sub-path (taken from its Destination URL) from one destination-neutral render.
- **Per-destination replacements**: Text, Media (whole responsive sets), Links and Videos.
- **Site search** for static sites: a per-destination `search-index.json` built at deploy (after replacements), an in-browser search script, the **ShipStatic Search** Bricks element (style controls for every part, including the results scrollbar; builder preview) and the `[shipstatic_search]` shortcode. Optionally the element searches as you type on the live WordPress site too (off by default), from a read-only index of the exported pages at `GET /wp-json/shipstatic/v1/search-index`.
- **Scheduled sync**: hourly / daily / weekly / monthly, to all or chosen destinations, via WP-Cron (WP-CLI when available).
- **Lockdown**: lock the WordPress site to an IP allow-list and send everyone else to the same page on a destination. An unlock link adds a visitor's address (and emails the admin); blocking happens in `.htaccess` (Apache / LiteSpeed) or before every PHP file via `.user.ini` (nginx / FastCGI), with an mu-plugin everywhere. Process, Sync and WP-Cron keep working while locked. WP-CLI: `wp shipstatic lockdown`.
- **Single-page sync** from the pages list, the front end and the Bricks builder (draggable button).
- First-run setup wizard, settings drawer (theme, discovery mode, AI tools), WP-CLI commands, WordPress Abilities (MCP) with opt-in tiers.
- Translations: fr_FR, de_DE, it_IT, es_ES, nl_NL.
