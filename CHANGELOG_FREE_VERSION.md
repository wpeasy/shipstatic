# Changelog

## v2.3.2 (2026-10-26)

### Changed
**Lockdown only lets in the address you're on.** Turning Lockdown on, or opening the unlock link, now adds just your own address, never a range, and single addresses are listed plainly (no /32 or /128). If your IPv6 address changes every day, add your range by hand.

### Fixed
**Lockdown on nginx is clearer about what it covers.** Without the optional nginx snippet, uploaded files (images, PDFs), CSS and JavaScript can still be opened by anyone who has their address. The Lockdown tab and the documentation now say so and explain how to lock them too.
