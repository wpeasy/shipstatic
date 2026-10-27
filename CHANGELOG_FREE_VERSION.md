# Changelog

## v2.4.4 (2026-10-27)

### Fixed
**Visitors no longer see "Currently synchronising" while you update a live site.** The holding page used to replace your home page during every sync that uploaded files one by one, which is how **This server** always works. Now it only appears on a destination's first Sync, before it has a home page, and your live site stays fully visible while updates upload.

### Fixed
**Sites in a sub-folder no longer affect the page at the domain root.** For a site published in a sub-folder (such as `example.com/site/`) over FTP, SFTP or the Connector, the "Currently synchronising" page could be written to the domain's main home page and stay there.
