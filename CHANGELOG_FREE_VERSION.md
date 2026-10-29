# Changelog

## v2.4.12 (2026-10-30)

### New
**Setup wizard: site search and Bricks forms and filters.** The first-run wizard now has a step for turning on site search, and (on Bricks sites) a step for letting Bricks forms, filters and AJAX loads work on your static site, with a note about firewalls.

### New
**Setup wizard: AI abilities.** The wizard now covers AI abilities, so you can choose what connected AI assistants may do before you start.

### New
**Short help under two settings.** “Pages to include” and “Enable sync single button” now have a line of help underneath explaining what they do.

### New
**Updating the Connector file no longer means pairing again.** When a newer Connector file is available, the destination shows an “update available” notice and a button that gives you the new file for the same pairing. Upload it over the old one and carry on.

### Fixed
**Disabled switches no longer show a stray bar.** Greyed-out switches, checkboxes and radio buttons in the dashboard were showing a leftover bar next to them.

### Improved
**Cleaner setup wizard.** More space between the step list and the content, with a faint divider line between them.

### Improved
**“AI tools (MCP)” is now “AI abilities”.** The setting and its labels have been renamed. The site search and Bricks forms switches also have clearer names: “Enable JavaScript site search” and “Allow Bricks forms and filters”.

### Improved
**Live search follows Site search.** The separate live-search setting is gone. Live search on your WordPress site is simply on whenever Site search is on.

### Improved
**Stronger security behind the scenes.** Destination addresses can no longer contain odd path segments, FTP and SFTP connections are checked more strictly, and the Connector file validates its settings more carefully. Nothing changes in how you use them.
