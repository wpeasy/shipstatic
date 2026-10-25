# Changelog

## v2.3.1 (2026-10-26)

### New
**Process changes**: when you've added, edited or unpublished a few posts, re-render just those, the lists that show them and any new pages they link to, in seconds instead of re-rendering the whole site. **Process all** is still there and is always the safe choice; the dashboard tells you when only Process all will do, such as after changing a menu or a Bricks template.

### New
**Sync no longer renders the site a second time.** When the render is up to date, Sync uploads it straight away. Tick **Re-render before syncing** after plugin or theme updates. Scheduled syncs always render everything.

### New
The page picker in the Images and Videos replacers can be **filtered by typing**, and shows pages that aren't in the export greyed out, with a note on why.

### New
Connector on nginx: a **Using nginx?** notice lists the minimum server setup, and the nginx snippet now blocks server config files and unfinished uploads from public view.

### Fixed
Links to other sites (share buttons, archived pages, look-alike addresses) are no longer changed in your exported pages.

### Fixed
The setup wizard now explains Process before running it, lets you skip it, and closes straight away.

### Fixed
The Connector now works on nginx servers with their default upload limit, with clear messages if the server refuses something.

### Fixed
Several security hardening fixes.
