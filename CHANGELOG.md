# Changelog

All notable changes to **ShipStatic** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
