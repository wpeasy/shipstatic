# Changelog

## v2.4.1 (2026-10-26)

### Fixed
**Pages publish correctly with "This server" on cPanel.** Every page except the home page could show "Not found", because the folders ShipStatic created couldn't be opened by the web server. They're now created so the web server can read them, and **Test** checks this too. If you already synced, Sync again after updating.
