# Changelog

All notable changes to **ShipStatic** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
