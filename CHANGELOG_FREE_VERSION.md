# Changelog

## v2.4.9 (2026-10-28)

### New
**Bricks forms work on your static site (experimental).** Before, a Bricks form on the static site did nothing when submitted. Now each sync points your forms back at your WordPress site, which runs their actions as usual — emails, saved submissions, webhooks, Mailchimp and the rest. A Redirect after submit goes to the matching page on the static site, not to WordPress. It's on by default under **Settings → Bricks forms and filters use WordPress**; sync after changing it.

### New
**Bricks query filters, AJAX pagination, load more and AJAX popups work on your static site (experimental).** WordPress finds the results, so they're always current, and they come back with links and images that point at your static site, including that destination's text and link replacements. Filtered links (such as `/shop/?brx_abc123=red`) open with the filters applied. When a page has filters or loads more posts, each sync also uploads every size of every published post's featured image, so new results have their pictures.

### New
**Forms and filters keep working with Lockdown on (experimental).** Lockdown lets exactly these requests through to WordPress and still turns everything else away, on Apache, LiteSpeed and nginx (including the optional nginx snippet).

### Improved
**Fewer false warnings.** While the setting is on, the processed-pages list no longer warns that Bricks forms won't work on the static site.
