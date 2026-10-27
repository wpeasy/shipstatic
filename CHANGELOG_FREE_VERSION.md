# Changelog

## v2.4.6 (2026-10-27)

### Fixed
**Tracked outbound links work on your static site.** If a URL manager or link-tracking plugin (Pretty Links, ThirstyAffiliates and similar) gives your outbound links an address on your site, such as `/go/partner`, those links used to lead to a missing page on the static site. They now go straight to where they redirect. Clicks from the static site aren't counted, because the tracker runs inside WordPress.

### Fixed
**Clearer reason for skipped redirects.** A page skipped because it redirects to another site now says where it goes and why it isn't exported, instead of just "HTTP 301". In **View processed list**, such links are marked "Redirects to another site".

### Fixed
**"This server" can publish to another domain.** When WordPress sits at the root of its domain, the folder wizard used to offer only "Move WordPress into /wp". It now also lets you publish to another domain or subdomain (such as `static.example.com`) whose folder is beside WordPress, and suggests that folder for you.

### Fixed
**The "This server" wizard finishes properly.** After **Create and test** it no longer jumps back to the first step, and it switches the destination on so you can Sync straight away.

### Fixed
**Moving WordPress into /wp, or back, now asks for a fresh Process.** Previously the old render was reused, so after an Undo the sitemap still listed `/wp/` addresses, and the dashboard showed "In sync" when it wasn't.

### Fixed
**The optional nginx Lockdown snippet no longer loops.** With WordPress in `/wp`, it now blocks only WordPress's own addresses and sends visitors to the matching page on your static site.
