# Changelog

## v2.4.7 (2026-10-27)

### Fixed
**The static home page no longer stays on "Currently synchronising".** With WordPress in a sub-folder (such as `/wp`) and "This server" publishing to the domain root, your static home page could be replaced by the "Currently synchronising" page. A link to `/` on your pages (a logo or Home link) pointed at the published site instead of WordPress, and the export saved what it found there as the home page. Update and sync again to put the real home page back.
