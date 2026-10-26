# Changelog

## v2.4.2 (2026-10-26)

### New
**Help for server cron jobs with Lockdown.** A cron job that calls `wp-cron.php` over the web (with wget, curl or an online cron service) is turned away by Lockdown, so scheduled tasks stop. The Lockdown tab now has a **Using a server cron job?** section with the command to run it with PHP instead, which Lockdown never stops.

### New
**A warning when WordPress's own cron is turned off.** If `wp-config.php` sets `DISABLE_WP_CRON` while Lockdown is on, the Lockdown tab (and `wp shipstatic lockdown status`) reminds you that your server cron job must use PHP.

### Fixed
**No more false alarms right after turning Lockdown on.** Some servers, LiteSpeed especially, take a few seconds to read the new rules, so the tab could briefly say your address wasn't getting in or that the rules weren't taking effect. It now keeps checking for about ten seconds before reporting a problem.

### Fixed
**Lockdown warnings show their text again.** Two warnings on the Lockdown tab showed only their button.

### Improved
**Changing mobile addresses are recognised.** On a mobile connection your address can change from one request to the next. Instead of reporting you locked out, the tab now explains what's happening and offers the unlock link or your provider's address range.
