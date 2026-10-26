# Changelog

## v2.4.3 (2026-10-26)

### New
**Scripts and services can get past Lockdown with the unlock key.** Uptime monitors, deploy hooks and web cron jobs can send the unlock key as a header, `Authorization: Bearer <key>`, instead of opening the unlock link. Only that request gets through: its address isn't added to the list and no email is sent. The Lockdown tab shows the header ready to copy, and **New link** changes it too.

### New
**A web cron command for Lockdown.** If your host's cron jobs can only fetch a web address, the Lockdown tab's **Using a server cron job?** section now has a ready-made command that sends the unlock key with the request.
