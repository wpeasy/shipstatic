# Changelog

## v2.4.9 (2026-10-28)

### New
**Bricks forms work on your static site (experimental).** Before, a Bricks form on the static site did nothing when submitted. Now each sync points your forms back at your WordPress site, which runs their actions as usual — emails, saved submissions, webhooks, Mailchimp and the rest. A Redirect after submit goes to the matching page on the static site, not to WordPress. It’s on by default under **Settings → Bricks forms and filters use WordPress**; sync after changing it.

### New
**Bricks query filters, AJAX pagination, load more and AJAX popups work on your static site (experimental).** WordPress finds the results, so they’re always current, and they come back with links and images that point at your static site, including that destination’s text and link replacements. Filtered links (such as `/shop/?brx_abc123=red`) open with the filters applied. When a page has filters or loads more posts, each sync also uploads every size of the featured image of every post in your export, so new results have their pictures.

### New
**Faster forms and filters after the first page.** The security token in an exported page has usually expired, so Bricks has to fetch a fresh one before its first request goes through. ShipStatic now remembers the fresh token in the visitor’s browser for 11 hours, so later pages and filters answer straight away.

### New
**Forms and filters keep working with Lockdown on (experimental).** Lockdown lets exactly these requests through to WordPress and still turns everything else away, on Apache, LiteSpeed and nginx (including the optional nginx snippet).

### Fixed
**Multisite: each site keeps its own export.** On a multisite network every site shared one render cache, so sites overwrote each other’s pages, and background syncs ran on the main site. Each site now has its own.

### Fixed
**Sitemap addresses with unusual characters.** A Destination URL whose path contained `$0` or similar could corrupt that destination’s `sitemap.xml` and `robots.txt`.

### Improved
**Multisite: features that change the server are disabled.** On a multisite network, Lockdown, “This server” and Move WordPress into /wp would write server files and folders every site on the network shares, so any site’s administrator could affect all of them. They now show **Disabled on Multisite**. The other deploy methods work as usual.

### Improved
**Lockdown is harder to get around.** Making a new unlock link now also replaces Lockdown’s internal token, which is never sent on to another site. The requests Lockdown lets through for forms and filters are narrower. Turning Lockdown off can no longer leave PHP failing on every request if a file couldn’t be updated.

### Improved
**Fewer false warnings.** While the forms and filters setting is on, the processed-pages list no longer warns that Bricks forms won’t work on the static site.
