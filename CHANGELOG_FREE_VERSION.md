# Changelog

## v2.4.5 (2026-10-27)

### Fixed
**Your logo link no longer points at the current page.** Bricks writes a link to your home page without a trailing slash (`https://example.com`), and the export turned it into an empty link, which browsers treat as "this page". On your About page, the logo linked to the About page. It now goes to your home page again. After updating, use **Process all** once so every page picks up the fix.

### Fixed
**Links to your site inside page scripts and structured data are no longer corrupted.** On sites published at the root of a domain, these links were left with a stray backslash, which could break the script or data around them.

### Fixed
**Move WordPress into /wp now works on nginx servers.** nginx needs one extra rule before WordPress can live in `/wp`; without it, the dashboard stopped loading after the move ("Unexpected token '<' … is not valid JSON") and the Undo button couldn't be reached. The move now shows the rule ready to copy, checks that your server is using it, and only moves WordPress once it is.

### Fixed
**Undo the move works again.** It refused with "wp-includes already exists", because your published static site contains its own copies of some WordPress files.

### Fixed
**Sync is ready straight after moving WordPress into /wp** — no connection test needed first.

### Fixed
**Sites on a non-standard port** (such as `:8080`) no longer get broken addresses after moving WordPress into /wp.

### Fixed
**Process and Sync no longer wait forever at 0 on some VPS servers.** The dashboard's background task couldn't find WP-CLI on servers where PHP runs without the usual command paths.

### Fixed
**The Lockdown check on nginx answers straight away** instead of taking about 25 seconds after you turn Lockdown on.

### Improved
**The "This server" folder wizard's messages** now show as proper notices instead of plain text.
