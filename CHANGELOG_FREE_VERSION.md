# Changelog

## v2.5.0 (2026-11-03)

### New
**Bricks filters that work without WordPress (experimental).** Turn on “Run Bricks filters without WordPress” and your static site’s filters, sorting, search, pagination and infinite scroll are answered right in the visitor’s browser from data files that Process builds. Every Bricks filter type works except WooCommerce, which isn’t supported on a static site at all. A loop that can’t be answered exactly keeps using your WordPress site, and Settings lists which ones. Results are a snapshot, so new or changed posts appear after your next Sync.

### New
**A Bricks guide with Simple and Advanced setups.** Simple needs no questions and suits simple filters (a category filter, say), a few hundred posts and content that changes only when you Sync. Advanced is for complex or facet filters, larger sites and content that changes often: you choose where filters run and where their images come from. Open it from the dashboard banner, Settings, or the setup wizard’s Bricks step.

### New
**Optional: serve filter-result images from WordPress.** When filters run through WordPress, you can keep their images on WordPress so a picture your last Sync hasn’t uploaded still shows, and syncs get smaller. With Lockdown on, it lets image files (JPG, PNG, GIF, WebP, AVIF) from the uploads folder through, on Apache, LiteSpeed and nginx. Anyone who knows an image’s address can then open it, including images used only in drafts.

### New
**A dashboard notice when published items are waiting.** If Bricks filters are in use and some published items aren’t in the export yet, the dashboard tells you they won’t appear in filters until you Process and Sync.

### Improved
**Filter results fetched from WordPress show only posts in your last export.** A post published since your last Process and Sync has no page or image files on the static site yet, so it no longer shows up with a broken link and picture. It appears after the next Process and Sync.

### Improved
**Tidier Settings.** The long “Experimental” notes and the list of query loops now collapse to a single line until you open them.

### Improved
**Clearer WooCommerce wording.** Everywhere it’s mentioned, ShipStatic now says plainly that WooCommerce isn’t supported on a static site.
