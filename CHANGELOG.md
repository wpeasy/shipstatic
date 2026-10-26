# Changelog

All notable changes to **ShipStatic** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
