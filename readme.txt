=== ShipStatic ===
Contributors: brxprod
Tags: static site, bricks, performance, static html, deployment
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.4.10
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish your WordPress site as static HTML over SFTP/FTP, HTTPS, Cloudflare or GitHub Pages. Bricks-aware; works with any WordPress content.

== Description ==

**ShipStatic** turns your WordPress pages into plain static HTML and publishes them to your own host (SFTP/FTP or HTTPS), to Cloudflare or to GitHub Pages. Serving flat files means no PHP, no database and no plugin stack on the public site — so it's dramatically faster, cheaper to host, and a much smaller attack surface.

It crawls your site, renders each page, collects the CSS/JS/images, rewrites URLs to root-relative, and deploys the result — either file-by-file or as a single zip that's extracted on the destination. Prefer to deploy manually? **Export ZIP** packages the same render into a downloadable zip instead. A floating "sync this page" button lets you push a single edited page without rebuilding the whole site.

= Built for Bricks =

ShipStatic understands Bricks. Bricks Builder gives elements a new random ID (`brxe-…`, form field IDs and several `data-*` attributes) every time a page renders. To a generic static exporter, every Bricks page then looks changed on every run, so the whole site is re-uploaded each time and "in sync" never means anything. ShipStatic recognises those IDs and ignores them when comparing renders, so only pages whose content actually changed are uploaded. It also renders through Bricks' maintenance / coming-soon mode (which would otherwise be captured and published in place of every page), and keeps its "Sync this page" button working alongside the Bricks editor.

= Works with all standard WordPress content =

Bricks isn't required. Rendering is builder-agnostic: ShipStatic captures whatever your site outputs, including block editor (Gutenberg) and Classic Editor posts and pages, archives and taxonomy pages, and other themes. On pages not built with Bricks, the Bricks-specific handling simply has nothing to do. Another builder that adds its own per-render noise can register patterns with the `shipstatic_stable_hash_patterns` filter.

= Features =

* Render Bricks pages to static HTML — **unlimited pages** per sync
* Deploy to **unlimited destinations** over SFTP, FTP or FTPS, with sync-all
* **Text**, **Media**, **Links** and **Videos** replacements per destination — no per-page limits
* **Gzip pre-compression** (`.gz`) for faster delivery
* **Remote pruning** — delete files that no longer exist locally
* **Sitemap.xml + robots.txt** generation
* **Site search** — search boxes work on the static site: each sync adds a search index for that destination, searched in the visitor's browser (no server or third-party service); includes a fully styleable **ShipStatic Search** Bricks element and a `[shipstatic_search]` shortcode
* Per-file **and** one-shot package (zip) deploy
* **Connector (HTTPS)** — no FTP account needed: upload one small PHP receiver to the destination once, then every sync goes up as a single signed, chunked package
* **Cloudflare** — publish to Cloudflare's global network (as a static Worker) with an account ID, an API token and a Worker name; no web host needed
* **GitHub Pages** — publish to a branch of your GitHub repository; each sync is one commit containing only the files that changed
* **This server** — publish into a folder on the same server as WordPress, written directly (no FTP or Connector). On cPanel the easiest setup is one domain: the static site at the root and WordPress in `/wp`, locked to editors with Lockdown
* **Export ZIP** — download the current render as a zip instead of uploading it, for manual deploys (no FTP/SFTP needed)
* Automatic `.htaccess` generation **plus** a copy-paste nginx snippet
* Favicon and asset handling
* "Sync this page" floating button for single-page pushes
* **Scheduled sync** — Process and Sync automatically every hour, day, week or month, to all destinations or the ones you pick
* **Lockdown** — lock the WordPress site to your editors' IP addresses and send everyone else to the static site, with an unlock link for when your address changes (scripts and services can send the same key as an `Authorization: Bearer` header)
* WP-CLI command for reliable syncs on any host

= Credits =

The plugin icon includes the [HTML5 logo](https://www.w3.org/html/logo/) by the W3C, licensed under [CC BY 3.0](https://creativecommons.org/licenses/by/3.0/).

== Installation ==

1. Upload the `shipstatic` folder to `/wp-content/plugins/`, or install it from **Plugins → Add New**.
2. Activate **ShipStatic** through the **Plugins** menu.
3. Open **ShipStatic** in the admin menu and enter your destination's SFTP/FTP details.
4. Click **Check** to render a preview, then **Sync** to deploy.

Bricks Builder is optional: ShipStatic works with any theme or editor and is Bricks-aware when Bricks is in use. Your WordPress host needs outbound SFTP/FTP or HTTPS access to reach the destination.

== Frequently Asked Questions ==

= Does this replace my server with static files automatically? =

No. It generates a static copy and deploys it to a destination you choose (a separate host, subdomain or bucket-backed server). Your WordPress install stays where it is and is used only to build and push.

= Do I need Bricks Builder? =

No. Any standard WordPress content exports fine: block editor, Classic Editor, archives, any theme. The name reflects the extra Bricks handling (ignoring the random element IDs Bricks regenerates on every render, and rendering through Bricks' maintenance mode), not a requirement.

= Where do I get support? =

In the plugin's support forum on WordPress.org: https://wordpress.org/support/plugin/shipstatic/

= How many pages can I export? =

There's no limit — ShipStatic renders and syncs every page in your export.

= Does search work on the static site? =

Yes, once you turn on **Site search** in ShipStatic → Settings, then Process and Sync. Every search box (WordPress, Bricks' Search element, or the ShipStatic Search element) then searches the destination's own index right in the browser, with results as you type. Each destination's index has its own text replacements and addresses. The live WordPress site keeps its normal search, unless you also turn on **Use ShipStatic search on the WordPress site too**: the ShipStatic Search element then searches as you type there as well, from the pages in your last Process.

= Which connections are supported? =

SFTP, FTP and explicit-TLS FTPS; the Connector (signed HTTPS to a small receiver file you upload once); Cloudflare; GitHub Pages; and a folder on this same server. SFTP uses a bundled pure-PHP library, so no special server extensions are required.

= What files does Lockdown write? =

Lockdown is off by default. When you turn it on, it writes `wp-content/shipstatic-lockdown.php` (the check that decides who gets in) and `wp-content/mu-plugins/shipstatic-lockdown.php` (which runs that check inside WordPress), plus a marked block at the top of `.htaccess` on Apache and LiteSpeed, or in `.user.ini` on nginx and other FastCGI hosts. Turning Lockdown off or deactivating the plugin removes them all. On hosts using `.user.ini`, the check file is left as an empty stub for 10 minutes first, because PHP keeps reading `.user.ini` from its cache for up to 5 minutes.

= Can a cron job or script get past Lockdown? =

WordPress's own WP-Cron and command-line PHP (`php wp-cron.php`, WP-CLI) are never stopped. A script or service that calls the site over the web can send the unlock key as a header, `Authorization: Bearer <key>`: that request gets through without its address being added to the list. The Lockdown tab shows the header and a ready-made cron command.

= Why does the plugin run shell commands? =

Only to start its own WP-CLI commands (`wp shipstatic …`) as background processes, so a long sync keeps running after the admin page request ends and several destinations can deploy in parallel. The commands are built from fixed internal paths and arguments, never from request input. When `exec()`/`proc_open()` are disabled on the host, the plugin falls back to running the sync in the browser.

= Where is the source code? =

The admin interface is written in Svelte and shipped as compiled, minified JavaScript in `assets/dist/`. The full, human-readable source is on GitHub: https://github.com/wpeasy/shipstatic (the `src-svelte/` folder). To build it yourself: `npm install`, then `npm run build`.

== External services ==

ShipStatic sends data only to destinations you configure yourself. Nothing is sent until you test a connection or run a sync, and there is no tracking or telemetry.

**Your own servers (SFTP, FTP/FTPS, Connector).** When you test a connection or sync, the plugin connects to the host you entered and uploads the generated static files (HTML, CSS, JavaScript, images and other media from your site), plus `.htaccess`, `sitemap.xml` and `robots.txt` where enabled. For the Connector, it sends signed HTTPS requests to the receiver file you uploaded to that server. "Check server" also requests your Destination URL to read its HTTP response headers. These servers are yours or your host's; their terms are your hosting provider's.

**Cloudflare API (`api.cloudflare.com`)** — only for destinations set to the Cloudflare deploy method. When you test the connection or sync, the plugin sends your Cloudflare account ID and API token (as the request's authorization), the Worker name, the list of exported files with their content hashes and sizes, the contents of any files Cloudflare doesn't already have, and the site's cache-header rules. Cloudflare then serves those files publicly at your Worker's address. Cloudflare [Terms of Service](https://www.cloudflare.com/terms/) and [Privacy Policy](https://www.cloudflare.com/privacypolicy/).

**GitHub API (`api.github.com`)** — only for destinations set to the GitHub Pages deploy method. When you test the connection or sync, the plugin sends your GitHub access token (as the request's authorization) and the repository owner, name and branch; it reads the branch's file list, and uploads the contents of changed exported files, then creates a commit on that branch. GitHub Pages then serves those files publicly. GitHub [Terms of Service](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service) and [Privacy Statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement).

**YouTube thumbnails (`img.youtube.com`)** — the Videos replacements panel in the admin dashboard shows a preview image for each YouTube embed found on a page, loaded by your browser from YouTube, which receives your IP address and the video ID. Nothing is sent unless you open that panel on a page with YouTube embeds. YouTube [Terms of Service](https://www.youtube.com/t/terms) and Google [Privacy Policy](https://policies.google.com/privacy).

== Screenshots ==

1. The ShipStatic dashboard — destinations, status and sync controls.
2. Per-destination replacements — Text, Media, Links and Videos.
3. The "sync this page" button on the front end.

== Changelog ==

= 2.4.10 =
* New: in "Manual selection" mode, the Pages and Posts lists have a one-click include icon (grey = not in the export, green = in it), and Quick Edit has an "Include in static export" checkbox.
* Fixed: page titles with an ampersand or quotes showed HTML codes such as "&#038;" in the processed list and other ShipStatic lists.

= 2.4.9 =
* New (experimental): Bricks forms, query filters, AJAX pagination, load more and AJAX popups work on the static site. They send their requests to your WordPress site, results link to the static site, and filtered links open filtered. With Lockdown on, these requests still get through.
* Changed: on a multisite network, Lockdown, "This server" and Move WordPress into /wp are disabled (they write server files every site shares). Each site now keeps its own render cache.
* Security: Lockdown's internal render token now changes with a new unlock link and is never sent to another site; Lockdown's cleanup can no longer leave PHP failing on every request.

= 2.4.8 =
* Improved: the "This server" wizard starts by asking where the static site goes (move WordPress into /wp, or another domain), and Back always returns to that choice. It also explains what your host needs for another domain.

= 2.4.7 =
* Fix: with WordPress in a sub-folder (such as /wp) and "This server" publishing to the domain root, the static home page stayed on "Currently synchronising" after the first sync.

= 2.4.6 =
* Fix: link-tracking and short links on your site (such as /go/partner) no longer break on the static site. Links to them now go straight to where they redirect, and the skip reason says so.
* Fix: "This server" can now publish to another domain whose folder sits beside WordPress, even when WordPress is at its domain's root.
* Fix: the "This server" wizard shows its result instead of jumping back to the start, and switches the destination on.
* Fix: after moving WordPress into /wp or back, the sitemap no longer keeps the old /wp/ addresses, and the dashboard asks for Process.
* Fix: the optional nginx Lockdown snippet no longer redirects visitors in a loop when WordPress is in /wp.

= 2.4.5 =
* Fix: a link to your site's home written without a trailing slash (such as the Bricks logo) pointed at the current page in the export. After updating, use Process all once.
* Fix: links to your site inside page scripts and structured data could be corrupted on sites published at a domain root.
* Fix: on nginx, Move WordPress into /wp now shows the server rule WordPress needs and waits until it's in place, instead of leaving the dashboard unable to load. Undo the move works again.
* Fix: Process and Sync from the dashboard could wait forever at 0 on some VPS servers.
* Fix: the Lockdown check on nginx answers straight away instead of after ~25 seconds.

= 2.4.4 =
* Fix: a live static site no longer shows the "Currently synchronising" page while an update uploads. It only appears on a destination's first Sync, before it has a home page.
* Fix: for a site published in a sub-folder over FTP, SFTP or the Connector, the "Currently synchronising" page could replace the page at the domain root and stay there.

= 2.4.3 =
* Lockdown: scripts and services can now get past Lockdown by sending the unlock key as a header, `Authorization: Bearer <key>`. Only that request gets through; its address isn't added to the list.
* Lockdown: a ready-made web cron command that sends the key, for hosts whose cron jobs can only fetch a web address.

= 2.4.2 =
* Fix: right after turning Lockdown on, the tab could wrongly report a problem on LiteSpeed servers, which take a few seconds to read the new rules. It now keeps checking for about ten seconds first.
* Lockdown now recognises an address that changes between requests (mobile connections) and suggests the unlock link or your provider's range, instead of reporting you locked out.
* Lockdown: new help for server cron jobs (run wp-cron.php with PHP, not wget or curl), and a warning when DISABLE_WP_CRON is set.
* Fix: two Lockdown warnings showed only their button, without their text.

= 2.4.1 =
* Fix: with "This server", pages in sub-folders returned 404 on cPanel hosts (folders were created with the parent folder's permissions, which the web server can't enter). Test now checks a sub-folder too.

= 2.4.0 =
* New deploy method "This server": publish the static site into a folder on the same server as WordPress, written directly (no FTP, Connector or outside service), with a setup wizard that checks the folder and that its address serves it.
* The wizard can move a WordPress site installed at the domain root into /wp, so the root becomes the static site and WordPress (locked with Lockdown) lives at /wp. It can be undone from the dashboard.
* WP-CLI: wp shipstatic folder and wp shipstatic move-wordpress.

= 2.3.3 =
* Fix: Connector, FTP package deploy and the server check now work with a destination on your own computer (Herd or Valet *.test, Local *.local, localhost on any port), which WordPress used to refuse with "A valid URL was not provided".

= 2.3.2 =
* Lockdown: turning it on and the unlock link now add only your single address (IPv6 included), and single addresses are shown without /32 or /128.
* Lockdown on nginx: the panel and documentation now explain that, without the optional nginx snippet, uploaded files, CSS and JavaScript stay reachable by direct address.

= 2.3.1 =
* Process changes: re-render only the content you added, edited or unpublished since the last Process all (Process all remains the safe choice, and the dashboard says when it's needed).
* Sync now reuses an up-to-date render instead of rendering every page again, with an option to re-render.
* Filterable page picker in the Images and Videos replacers, showing pages that aren't in the export.
* Connector: works on nginx out of the box, with a "Using nginx?" setup notice.
* Fixes: external URLs are no longer changed in exported pages; the setup wizard explains Process before running it; several security hardening fixes.

= 2.3.0 =
* First public release.
* Render Bricks (or any WordPress) pages to static HTML and deploy them to unlimited destinations: SFTP / FTP / FTPS, the Connector (HTTPS), Cloudflare, GitHub Pages, or an Export ZIP.
* Per-destination Text, Media, Links and Videos replacements, with each destination's own sub-path.
* Site search for the static site, with a fully styleable ShipStatic Search element for Bricks and a [shipstatic_search] shortcode. The element can also search as you type on the live WordPress site (optional).
* Scheduled syncs (hourly, daily, weekly or monthly), single-page sync from the admin, the front end and the Bricks builder, gzip pre-compression, remote pruning, sitemap.xml and robots.txt generation.
* Lockdown: lock the WordPress site to your editors' IP addresses and send everyone else to the static site, with an unlock link for when your address changes.
* Translations: French, German, Italian, Spanish and Dutch.
