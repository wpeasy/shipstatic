<?php
/**
 * Translatable UI strings for the Svelte/JS dashboard.
 *
 * Single source of truth for every string the Free admin UI renders. PHP owns
 * the strings (so one `.mo` translates both PHP and JS); `Admin\Menu` localises
 * `self::all()` onto `window.shipStaticData.i18n` and the Svelte `shared/i18n.ts` helper
 * looks each key up. Keep keys stable — they are referenced by the JS bundle.
 *
 * @package WPEasy\ShipStatic
 * @since   1.0.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Support;

defined('ABSPATH') || exit;

/**
 * The dashboard i18n dictionary (key => translated string).
 */
final class I18n {

    /**
     * The full key => string map handed to the JS bundle.
     *
     * @return array<string,string>
     */
    public static function all(): array {
        return array_merge(
            self::header(),
            self::globalbar(),
            self::controls(),
            self::discovery(),
            self::notice(),
            self::method(),
            self::destination(),
            self::tabs(),
            self::toolbar(),
            self::replacements(),
            self::media(),
            self::links(),
            self::videos(),
            self::catalog(),
            self::allDestinations(),
            self::progress(),
            self::manualRun(),
            self::serverConfig(),
            self::textReplacements(),
            self::richText(),
            self::editor(),
            self::ai(),
            self::export(),
            self::wizard(),
            self::lockdown(),
            self::common()
        );
    }

    /**
     * AI tools (MCP) panel.
     *
     * @return array<string,string>
     */
    private static function ai(): array {
        return [
            'aiTitle'        => __('AI tools (MCP)', 'shipstatic'),
            'aiIntro'        => __('Expose ShipStatic to AI assistants through the WordPress Abilities API. Read-only abilities (status, page list, link checks) are always available to administrators; the actions below are off by default.', 'shipstatic'),
            'aiUnavailable'  => __('Requires WordPress 6.9 or newer (the Abilities API). Read-only and action abilities are unavailable on this site.', 'shipstatic'),
            'aiConsumerNote' => __('An MCP client (e.g. the WordPress MCP plugin) is needed to actually reach these abilities.', 'shipstatic'),
            'aiAllowChanges' => __('Allow AI to change settings', 'shipstatic'),
            'aiAllowChangesHint' => __('Discovery mode and the per-page Include switch.', 'shipstatic'),
            'aiAllowSync'    => __('Allow AI to run syncs', 'shipstatic'),
            'aiAllowSyncHint'=> __('Scan, sync, single-page sync, cancel and reset. These render pages and push to your destinations.', 'shipstatic'),
        ];
    }

    /**
     * First-run setup wizard.
     *
     * @return array<string,string>
     */
    private static function wizard(): array {
        return [
            'wizardBtn'       => __('Wizard', 'shipstatic'),
            'wizardTitle'     => __('Setup Wizard', 'shipstatic'),
            'wizardIntro'     => __('A few basics before your first sync — you can change any of these later in Settings.', 'shipstatic'),
            'wizardBack'      => __('Back', 'shipstatic'),
            'wizardNext'      => __('Next', 'shipstatic'),
            'wizardFinish'    => __('Finish & Process', 'shipstatic'),
            'wizardFinishOnly' => __('Finish', 'shipstatic'),
            'wizardProcessStep' => __('First Process', 'shipstatic'),
            'wizardProcessHelp' => __('Process renders every page you chose to include into static HTML, ready to check and sync. Nothing is uploaded anywhere yet.', 'shipstatic'),
            'wizardProcessTime' => __('It visits every included page, so it can take several minutes on a large site, and the site does a little extra work while it runs. Progress shows on the dashboard; you can keep working.', 'shipstatic'),
            'wizardProcessNow' => __('Process now when I finish', 'shipstatic'),
        ];
    }

    /**
     * Export ZIP progress panel.
     *
     * @return array<string,string>
     */
    private static function export(): array {
        return [
            'exportPhasePreparing'  => __('Preparing…', 'shipstatic'),
            'exportPhaseGzip'       => __('Creating gzip files…', 'shipstatic'),
            'exportPhasePackaging'  => __('Packaging…', 'shipstatic'),
            'exportPhaseSaving'     => __('Saving…', 'shipstatic'),
            'exportPhaseDone'       => __('Export ready', 'shipstatic'),
            'exportNeedsProcess'    => __('Nothing has been rendered yet. Click Process first, then Export.', 'shipstatic'),
            'exportCancel'          => __('Cancel', 'shipstatic'),
        ];
    }

    /**
     * Manual-mode post editor metabox + FAB include panel.
     *
     * @return array<string,string>
     */
    private static function editor(): array {
        return [
            'editorInclude'      => __('Include in static export', 'shipstatic'),
            'editorIncludeHint'  => __('When off, this page is left out of the static export.', 'shipstatic'),
            'editorSyncThisPage' => __('Sync this page', 'shipstatic'),
            'editorSyncing'      => __('Syncing…', 'shipstatic'),
            'editorPublishFirst' => __('Publish this page to sync it.', 'shipstatic'),
            'editorNoTargets'    => __('Enable a destination to sync this page.', 'shipstatic'),
            'editorNotPushed'    => __('Not pushed', 'shipstatic'),
            /* translators: %d is the number of included pages. */
            'editorIncludedUnlimited' => __('%d pages included', 'shipstatic'),
            /* translators: %d is a count of linked pages. */
            'editorLinksOut'   => __('This page links to %d page(s) not included:', 'shipstatic'),
            /* translators: %d is a count of pages that link here. */
            'editorLinksIn'    => __('%d included page(s) link here — they will have dead links:', 'shipstatic'),
            'editorLinksInNote'=> __('Based on the last check/sync — run a check to refresh.', 'shipstatic'),
            'editorIncludeAll' => __('Include all', 'shipstatic'),
            'editorIncluding'  => __('Including…', 'shipstatic'),
        ];
    }

    /**
     * Lockdown tab (docs/LOCKDOWN_PLAN.md).
     *
     * @return array<string,string>
     */
    private static function lockdown(): array {
        return [
            'ldHeading'          => __('Lockdown', 'shipstatic'),
            'ldLead'             => __('Lock this WordPress site to the addresses below and send everyone else to your static site. Your visitors use the static site, so only editors need WordPress, and a site no stranger can reach can’t be attacked through its plugins, logins or forms.', 'shipstatic'),
            'ldLinkHeading'      => __('Unlock link', 'shipstatic'),
            'ldLinkHelp'         => __('Opening this link adds the address you’re on to the list, so it gets you back in when your address changes. Anyone who has it can get in, so keep it private, and bookmark it rather than sharing it in public places.', 'shipstatic'),
            'ldCopy'             => __('Copy', 'shipstatic'),
            'ldCopied'           => __('Copied', 'shipstatic'),
            'ldNewLink'          => __('New link', 'shipstatic'),
            'ldNewLinkConfirm'   => __('Replace the link?', 'shipstatic'),
            'ldLinkSaved'        => __('I’ve saved this link somewhere safe', 'shipstatic'),
            'ldEnabled'          => __('Lockdown', 'shipstatic'),
            /* translators: %s: IP address. */
            'ldYourIp'           => __('Your address, as this server sees it: %s', 'shipstatic'),
            'ldAllowed'          => __('allowed', 'shipstatic'),
            'ldNotAllowed'       => __('not on the list', 'shipstatic'),
            'ldAddMine'          => __('Add my address', 'shipstatic'),
            'ldMyAddressLabel'   => __('Added from the dashboard', 'shipstatic'),
            /* translators: 1: the address a proxy reports, 2: the address the server sees. */
            'ldForwarded'        => __('A proxy or CDN says your address is %1$s, but the server sees %2$s. The site is probably behind a proxy, so the address it sees may be shared or change. Use the unlock link if you get locked out.', 'shipstatic'),
            'ldStatusOn'         => __('Locked', 'shipstatic'),
            'ldStatusOff'        => __('Off: anyone can reach this WordPress site.', 'shipstatic'),
            'ldMethodHtaccess'   => __('Strangers are stopped by .htaccess rules, before any PHP runs.', 'shipstatic'),
            'ldMethodUserIni'    => __('Strangers are stopped before any PHP file runs (through .user.ini). Changes can take up to 5 minutes to apply; until then WordPress itself blocks them.', 'shipstatic'),
            'ldMethodWordPress'  => __('Strangers are stopped inside WordPress, before other plugins load. This server offers no way to stop them earlier, so a plugin file opened directly isn’t covered.', 'shipstatic'),
            'ldBrowserBlockedTitle' => __('Your address isn’t getting in', 'shipstatic'),
            'ldBrowserBlocked'   => __('A test from this browser without its unlock cookie was turned away. You’re still in for 12 hours thanks to the cookie, but after that you’d be locked out. Add your address, or turn Lockdown off again.', 'shipstatic'),
            'ldTurnOff'          => __('Turn Lockdown off', 'shipstatic'),
            'ldProbeServer'      => __('Checked: a stranger is stopped by .htaccess, before PHP runs.', 'shipstatic'),
            'ldProbeGate'        => __('Checked: a stranger is stopped before any PHP file runs.', 'shipstatic'),
            'ldProbeWpHtaccess'  => __('Checked: strangers are only being stopped inside WordPress. The .htaccess rules aren’t taking effect: this server may ignore .htaccess, or (OpenLiteSpeed) need a restart to read it.', 'shipstatic'),
            'ldProbeWpUserIni'   => __('Checked: strangers are only being stopped inside WordPress for now. PHP can take up to 5 minutes to read .user.ini; check again then. If it stays like this, the host ignores .user.ini.', 'shipstatic'),
            'ldProbeWpOnly'      => __('Checked: strangers are stopped inside WordPress.', 'shipstatic'),
            /* translators: %s: IP address. */
            'ldProbeAllowed'     => __('Couldn’t check: the server’s own address (%s) is on the list, so its test request got in.', 'shipstatic'),
            'ldProbeOpen'        => __('A test request without permission got in, so the site isn’t locked. Write the files again.', 'shipstatic'),
            /* translators: %s: error message. */
            'ldProbeError'       => __('Couldn’t check: %s', 'shipstatic'),
            'ldFilesChanged'     => __('Lockdown’s files are missing or out of date (another plugin may have rewritten .htaccess).', 'shipstatic'),
            'ldWriteAgain'       => __('Write them again', 'shipstatic'),
            'ldCheckAgain'       => __('Check again', 'shipstatic'),
            'ldChecking'         => __('Checking…', 'shipstatic'),
            'ldListHeading'      => __('Allowed addresses', 'shipstatic'),
            'ldListHelp'         => __('Single addresses (203.0.113.7) or ranges (203.0.113.0/24, 2001:db8::/64). An address added with the unlock link is added as its /64 for IPv6, which changes within that range every day.', 'shipstatic'),
            'ldListEmpty'        => __('No addresses yet. Turning Lockdown on adds yours.', 'shipstatic'),
            'ldColAddress'       => __('Address', 'shipstatic'),
            'ldColLabel'         => __('Label', 'shipstatic'),
            'ldColAdded'         => __('Added', 'shipstatic'),
            'ldLabelPlaceholder' => __('Label, e.g. Office', 'shipstatic'),
            'ldAdd'              => __('Add', 'shipstatic'),
            'ldRemove'           => __('Remove', 'shipstatic'),
            'ldRemoveConfirm'    => __('Remove?', 'shipstatic'),
            'ldYou'              => __('you', 'shipstatic'),
            'ldViaEnable'        => __('when turned on', 'shipstatic'),
            'ldViaKey'           => __('via unlock link', 'shipstatic'),
            'ldViaManual'        => __('added by hand', 'shipstatic'),
            'ldViaCli'           => __('via WP-CLI', 'shipstatic'),
            'ldRedirectHeading'  => __('Send blocked visitors to', 'shipstatic'),
            'ldRedirectAuto'     => __('The first enabled destination', 'shipstatic'),
            'ldRedirectNone'     => __('Nowhere: show a 403 page', 'shipstatic'),
            /* translators: %s: URL. */
            'ldRedirectTarget'   => __('Blocked visitors go to the same page on %s.', 'shipstatic'),
            'ldRedirect403'      => __('Blocked visitors see a 403 page (no destination has a Destination URL).', 'shipstatic'),
            'ldNotify'           => __('Email the site admin when the unlock link adds an address', 'shipstatic'),
            'ldNginxHeading'     => __('Optional: block in nginx too', 'shipstatic'),
            'ldNginxHelp'        => __('If you manage this server, paste this into the site’s server { } block and reload nginx. It holds the list as it is now: after any change, including an address added by the unlock link, paste it again. Lockdown works without it.', 'shipstatic'),
            'ldHelpHeading'      => __('If you’re locked out', 'shipstatic'),
            'ldHelp1'            => __('Open your <strong>unlock link</strong>. It adds the address you’re on.', 'shipstatic'),
            'ldHelp2'            => __('With WP-CLI: <code>wp shipstatic lockdown off</code>, or <code>wp shipstatic lockdown allow &lt;address&gt;</code>.', 'shipstatic'),
            'ldHelp3'            => __('Over FTP or a file manager: replace everything in <code>wp-content/shipstatic-lockdown.php</code> with just <code>&lt;?php</code> (don’t delete it: PHP needs it while <code>.user.ini</code> points to it), then delete the <code># BEGIN ShipStatic Lockdown</code> block from <code>.htaccess</code> and the <code>; BEGIN ShipStatic Lockdown</code> lines from <code>.user.ini</code>. Renaming the plugin folder alone isn’t enough.', 'shipstatic'),
            'ldHelp4'            => __('Turning ShipStatic off (deactivating it) removes every Lockdown file.', 'shipstatic'),
        ];
    }

    /**
     * Header.
     *
     * @return array<string,string>
     */
    private static function header(): array {
        return [
            'appLead'      => __('Generate and serve static HTML versions of your site for performance.', 'shipstatic'),
            'byPrefix'     => __('Another plugin by', 'shipstatic'),
            'settings'     => __('Settings', 'shipstatic'),
            'styleGroup'   => __('Style', 'shipstatic'),
            'compactMode'  => __('Compact', 'shipstatic'),
            'colorScheme'  => __('Colour scheme', 'shipstatic'),
            'csAuto'       => __('Auto', 'shipstatic'),
            'csLight'      => __('Light', 'shipstatic'),
            'csDark'       => __('Dark', 'shipstatic'),
            'themeAccent'  => __('Theme', 'shipstatic'),
            'accentBlue'   => __('Blue', 'shipstatic'),
            'accentGreen'  => __('Green', 'shipstatic'),
            'accentYellow' => __('Yellow', 'shipstatic'),
            'accentPurple' => __('Purple', 'shipstatic'),
            'accentCustom' => __('Custom', 'shipstatic'),
        ];
    }

    /**
     * Global toolbar + top tabs.
     *
     * @return array<string,string>
     */
    private static function globalbar(): array {
        return [
            'enableSyncButton' => __('Enable sync single button', 'shipstatic'),
            'syncGroup'        => __('Sync', 'shipstatic'),
            'siteSearch'       => __('Site search', 'shipstatic'),
            'siteSearchHelp'   => __('Makes the search boxes on your static site work: each sync adds a search index for that destination, searched in the visitor’s browser. The live WordPress site keeps its own search. Press Process after changing this.', 'shipstatic'),
            'siteSearchLive'     => __('Use ShipStatic search on the WordPress site too', 'shipstatic'),
            'siteSearchLiveHelp' => __('The ShipStatic Search element then searches as you type on this WordPress site as well, from the pages in the last Process (press Process to pick up new content). Other search boxes keep normal WordPress search.', 'shipstatic'),
            'concurrentSyncs'  => __('Concurrent syncs', 'shipstatic'),
            'concurrentSyncsHelp' => __('How many destinations to upload to at the same time during a multi-destination sync. Higher is faster but uses more connections. Requires WP-CLI; otherwise syncs run one at a time.', 'shipstatic'),
            'tabDestinations'  => __('Destinations', 'shipstatic'),
            'tabServerConfig'  => __('Destination Server Configuration', 'shipstatic'),
            'tabSchedule'      => __('Schedule', 'shipstatic'),
            'tabLockdown'      => __('Lockdown', 'shipstatic'),
            'schedHeading'     => __('Scheduled sync', 'shipstatic'),
            'schedLead'        => __('Process and Sync automatically on a schedule — the same full sync as the Sync button, to the destinations you pick.', 'shipstatic'),
            'schedEnabled'     => __('Enabled', 'shipstatic'),
            'schedPeriod'      => __('How often', 'shipstatic'),
            'schedHourly'      => __('Hourly', 'shipstatic'),
            'schedDaily'       => __('Daily', 'shipstatic'),
            'schedWeekly'      => __('Weekly', 'shipstatic'),
            'schedMonthly'     => __('Monthly', 'shipstatic'),
            'schedDestinations' => __('Destinations', 'shipstatic'),
            'schedAll'         => __('All destinations', 'shipstatic'),
            'schedAllHelp'     => __('Every enabled destination, including ones you add later.', 'shipstatic'),
            'schedDisabledDest' => __('disabled', 'shipstatic'),
            'schedNoneChosen'  => __('Pick at least one destination, or turn on All destinations.', 'shipstatic'),
            /* translators: %s: date and time of the next scheduled sync. */
            'schedNext'        => __('Next sync: %s', 'shipstatic'),
            /* translators: 1: date and time, 2: outcome (e.g. "Done"), 3: details. */
            'schedLast'        => __('Last scheduled sync: %1$s — %2$s. %3$s', 'shipstatic'),
            'schedStRunning'   => __('In progress', 'shipstatic'),
            'schedStDone'      => __('Done', 'shipstatic'),
            'schedStError'     => __('Failed', 'shipstatic'),
            'schedStSkipped'   => __('Skipped', 'shipstatic'),
            'schedStCancelled' => __('Cancelled', 'shipstatic'),
            'schedStUnknown'   => __('Finished', 'shipstatic'),
            'schedCronNote'    => __('Schedules run through WP-Cron, which fires when someone visits the site. On a quiet site a sync can start a little late; a real server cron calling wp-cron.php keeps it on time.', 'shipstatic'),
            'schedSaved'       => __('Saved', 'shipstatic'),
        ];
    }

    /**
     * Bottom controls + confirm dialogs.
     *
     * @return array<string,string>
     */
    private static function controls(): array {
        return [
            'resetHint'         => __('Wiped a remote, or want a full re-upload? Reset clears the push record for every destination.', 'shipstatic'),
            'btnResetSync'      => __('Reset sync state', 'shipstatic'),
            'btnConfirmReset'   => __('Confirm reset?', 'shipstatic'),
            'btnConfirmSync'    => __('Confirm sync?', 'shipstatic'),
            'btnConfirmSyncAll' => __('Sync all destinations?', 'shipstatic'),
            'destinationDefault'=> __('Destination', 'shipstatic'),
            /* translators: %d is the new destination's number. */
            'destinationNumbered' => __('Destination %d', 'shipstatic'),
        ];
    }

    /**
     * Discovery toggle + its help modal.
     *
     * @return array<string,string>
     */
    private static function discovery(): array {
        return [
            'pagesToInclude'  => __('Pages to include', 'shipstatic'),
            'onlyLinkedPages' => __('Only linked pages', 'shipstatic'),
            'allPublished'    => __('All published', 'shipstatic'),
            'manualSelection' => __('Manual selection', 'shipstatic'),
            'whatsDifference' => __("What's the difference?", 'shipstatic'),
            'discControls'    => __('Controls which pages are discovered and exported.', 'shipstatic'),
            'tagDefault'      => __('default', 'shipstatic'),
            'discManualBody'  => __('Export only the pages you choose. Each Page, Post and custom post type gets an "Include" switch — off by default, except the home page — in its editor, the Pages/Posts list and the front-end Sync panel. Nothing else is crawled in, so the export is exactly your selection.', 'shipstatic'),
            'discLinkedBody'  => __('Starts at your home page and follows internal links from page to page. A page that nothing links to isn\'t reachable by a visitor, so it\'s left out — this also keeps builder-only URLs (such as Bricks <code>/template/…</code> previews) out of the static site.', 'shipstatic'),
            'discAllBody'     => __('Also includes every published page, post and taxonomy archive — even ones nothing links to (orphaned content). Use this if you rely on pages reached only through JavaScript navigation, or deliberately keep unlinked landing pages.', 'shipstatic'),
            'discNote'        => __('Either way, builder/preview post types are never exported.', 'shipstatic'),
            'viewPagesList'   => __('View included/excluded pages', 'shipstatic'),
            'btnProcess'      => __('Process', 'shipstatic'),
            'btnProcessHint'  => __('Render the site for the current mode so the page list is accurate.', 'shipstatic'),
            'btnViewList'     => __('View processed list', 'shipstatic'),
            /* translators: %d: number of published pages not in the static export. */
            'excludedHint'    => __('%d not in export', 'shipstatic'),
            'excludedHintTip' => __('Published pages that aren’t in the static export. Click to view them.', 'shipstatic'),
            'pagesOverviewTitle' => __('Pages in the static export', 'shipstatic'),
            'tabIncluded'     => __('Included', 'shipstatic'),
            'tabExcluded'     => __('Excluded', 'shipstatic'),
            'pagesLoading'    => __('Loading…', 'shipstatic'),
            'noIncludedPages' => __('No pages are in the export yet.', 'shipstatic'),
            'noExcludedPages' => __('Every published page is in the export.', 'shipstatic'),
            'colPage'         => __('Page', 'shipstatic'),
            'colPath'         => __('Path', 'shipstatic'),
            'colNotices'      => __('Notices', 'shipstatic'),
            'colReason'       => __('Reason', 'shipstatic'),
        ];
    }

    /**
     * WP-CLI notice panel.
     *
     * @return array<string,string>
     */
    private static function notice(): array {
        return [
            'wpCliDetected'     => __('WP-CLI detected', 'shipstatic'),
            'wpCliBody'         => __('pages are rendered by a WP-CLI process, taking the load off your web server.', 'shipstatic'),
            'runFromCli'        => __('Run Sync from the command line on this host', 'shipstatic'),
            'cliWarnBody'       => __('This environment serves PHP requests one at a time, so browser-driven Sync can time out. Run it from a terminal instead:', 'shipstatic'),
            'cliFlagsHint'      => __('Add <code>--check</code> for a dry run, or <code>--prune</code> to remove deleted files.', 'shipstatic'),
            'testBrowserRender' => __('Test browser rendering', 'shipstatic'),
            'permalinksTitle'   => __('Permalinks are set to “Plain”', 'shipstatic'),
            'permalinksBody'    => __('Every page shares the same address in this mode (<code>/?page_id=9</code>), so there is no separate file to export any of them to — the whole site would collapse into one page. Choose any other permalink structure, then run Process again.', 'shipstatic'),
            'permalinksAction'  => __('Open Permalink settings', 'shipstatic'),
        ];
    }

    /**
     * Method summary panel.
     *
     * @return array<string,string>
     */
    private static function method(): array {
        return [
            'method'         => __('Method', 'shipstatic'),
            'methodLead'     => __('How the next sync will run, resolved for this site:', 'shipstatic'),
            'mDiscovery'     => __('Discovery', 'shipstatic'),
            'methodDiscLinked' => __('Internal-link crawl from the home page — only pages reachable by following links.', 'shipstatic'),
            'methodDiscAll'    => __('Every published page, post and taxonomy archive — including content nothing links to.', 'shipstatic'),
            'methodDiscManual' => __('Only the pages you mark “Include” (per-post).', 'shipstatic'),
            'mTransport'     => __('Transport', 'shipstatic'),
            'mCompression'   => __('Compression', 'shipstatic'),
            'mServerTarget'  => __('Server target', 'shipstatic'),
            'mLinks'         => __('Links', 'shipstatic'),
        ];
    }

    /**
     * Destination connection panel.
     *
     * @return array<string,string>
     */
    private static function destination(): array {
        return [
            'fldTransport'     => __('Transport', 'shipstatic'),
            'optUnavailable'   => __(' (unavailable)', 'shipstatic'),
            'optFtps'          => __('FTPS (FTP over TLS)', 'shipstatic'),
            'ftpsNote'         => __('FTPS encrypts the connection but PHP can’t check the server’s certificate, so it doesn’t prove you’re talking to your real server. Prefer SFTP or the Connector when you can.', 'shipstatic'),
            'ftpNote'          => __('Plain FTP sends your password and files unencrypted. Use SFTP, FTPS or the Connector if your host offers them.', 'shipstatic'),
            'optFtp'           => __('FTP (insecure)', 'shipstatic'),
            'fldPort'          => __('Port', 'shipstatic'),
            'fldHost'          => __('Host', 'shipstatic'),
            'fldUsername'      => __('Username', 'shipstatic'),
            'fldPassword'      => __('Password', 'shipstatic'),
            'phSavedBlank'     => __('Saved — leave blank to keep', 'shipstatic'),
            'fldRemotePath'    => __('Remote path (web root)', 'shipstatic'),
            'phRemoteEmpty'    => __('(empty = FTP login dir)', 'shipstatic'),
            'fldDestUrl'       => __('Destination URL (optional)', 'shipstatic'),
            'fldSubPath'       => __('Served from sub-path (optional)', 'shipstatic'),
            'deployFastOk'     => __('Fast deploy: one package, extracted on the destination.', 'shipstatic'),
            'deployFastGuessed'=> __('Fast deploy enabled — the URL was guessed from the host. Set a Destination URL above to be sure it keeps working.', 'shipstatic'),
            'deployPerFileErr' => __('Per-file upload — package deploy hit an error and was turned off here.', 'shipstatic'),
            'deployNoZip'      => __('Per-file upload — ZipArchive isn’t available on this server.', 'shipstatic'),
            'deployPerFile'    => __('Per-file upload (slower). Set a Destination URL above to enable fast package deploy (needs PHP on the host).', 'shipstatic'),
            'btnReTesting'     => __('Re-testing…', 'shipstatic'),
            'btnReTest'        => __('Re-test', 'shipstatic'),
            // Deploy method (FTP/SFTP vs Connector — one per destination) + Manual ZIP.
            'lblDeployVia'     => __('Deploy via', 'shipstatic'),
            'tabFtp'           => __('FTP / SFTP', 'shipstatic'),
            'tabConnector'     => __('Connector', 'shipstatic'),
            'tabManualZip'     => __('Manual ZIP', 'shipstatic'),
            'optConnector'     => __('Connector (HTTPS)', 'shipstatic'),
            'ftpLead'          => __('Sync uploads straight to the destination over FTP, FTPS or SFTP. Enter the connection details, Test, then Save.', 'shipstatic'),
            'connectorLead'    => __('A single PHP file you download here and upload to the destination web root once. After that, syncs run over signed HTTPS — no FTP account, and each sync goes up as one package.', 'shipstatic'),
            'connectorStep1'   => __('Enter the <strong>Destination URL</strong> above (it must be HTTPS) and <strong>Save</strong>.', 'shipstatic'),
            'connectorStep2'   => __('Press <strong>Pair</strong>, then download the connector file made for this destination only (the link works once).', 'shipstatic'),
            'connectorStep3'   => __('Upload <code>bs-connector-&lt;id&gt;.php</code> to the folder served at that URL.', 'shipstatic'),
            'connectorStep4'   => __('Press <strong>Test</strong>, then Check / Sync as usual.', 'shipstatic'),
            'connectorReq'     => __('Needs PHP 7.4+ at the destination; ZipArchive there enables one-package deploys (otherwise files upload one by one).', 'shipstatic'),
            'connectorNginx'   => __('Using nginx?', 'shipstatic'),
            'connectorNginxLead' => __('nginx has its own limits, which PHP (and so the connector) can’t see. Make sure the destination site has at least this:', 'shipstatic'),
            'connectorNginx1'  => __('PHP runs for <code>.php</code> files in the folder the connector is in (a <code>location ~ \.php$</code> block passing to PHP-FPM, as for any PHP site). Without it, Test reports HTTP 405 or 404.', 'shipstatic'),
            'connectorNginx2'  => __('<code>client_max_body_size</code> of at least <code>2m</code>. ShipStatic uploads in pieces under 1 MB and retries smaller ones if refused, so nginx’s <code>1m</code> default works unless it has been lowered.', 'shipstatic'),
            'connectorNginx3'  => __('<code>fastcgi_read_timeout 300s;</code> in that PHP location: unpacking a large site can take longer than the 60-second default.', 'shipstatic'),
            'connectorNginx4'  => __('Block public access to <code>.htaccess</code> files and the connector’s in-progress uploads. The nginx snippet on the <strong>Destination Server Configuration</strong> tab includes the rules.', 'shipstatic'),
            'connectorUnpaired'=> __('Not paired yet.', 'shipstatic'),
            /* translators: %s: connector filename */
            'connectorPaired'  => __('Paired — expects %s in the destination folder.', 'shipstatic'),
            'connectorConst'   => __('Pairing is set by wp-config constants.', 'shipstatic'),
            // Cloudflare (Workers static assets) — docs/CLOUDFLARE_PLAN.md.
            'optCloudflare'    => __('Cloudflare', 'shipstatic'),
            'cfLead'           => __('Publishes the site to Cloudflare’s global network as a static Worker — no web host, PHP or FTP account needed. Each sync uploads only the files Cloudflare doesn’t already have, then switches the whole site over at once.', 'shipstatic'),
            'fldCfAccount'     => __('Account ID', 'shipstatic'),
            'fldCfWorker'      => __('Worker name', 'shipstatic'),
            'fldCfToken'       => __('API token', 'shipstatic'),
            'cfHowTo'          => __('How to set it up', 'shipstatic'),
            'cfStep1'          => __('In the Cloudflare dashboard, open <strong>Workers &amp; Pages</strong> and copy your <strong>Account ID</strong> (shown on the right of the overview).', 'shipstatic'),
            'cfStep2'          => __('Create an API token (<strong>My Profile → API Tokens → Create Token → Custom token</strong>) with the permission <strong>Account → Workers Scripts → Edit</strong>, limited to that account.', 'shipstatic'),
            'cfStep3'          => __('Choose a <strong>Worker name</strong> for this site (lowercase letters, numbers and dashes, e.g. <code>my-site</code>). It doesn’t need to exist — the first sync creates it.', 'shipstatic'),
            'cfStep4'          => __('Press <strong>Test</strong>. If the Destination URL is empty it is filled with the Worker’s <code>workers.dev</code> address — or enter your own domain once it is attached to the Worker (<strong>Worker → Settings → Domains &amp; Routes</strong>). <strong>Save</strong>, then Check / Sync as usual.', 'shipstatic'),
            'cfStep5'          => __('Cache headers are published automatically (Cloudflare’s <code>_headers</code>); Cloudflare compresses files itself, so no <code>.htaccess</code> or <code>.gz</code> files are used.', 'shipstatic'),
            'cfLimits'         => __('<strong>Limits:</strong> 25 MiB per file (larger files are left out and reported) and 20,000 files on the Free plan (100,000 on paid plans). Forms that post back to WordPress don’t work on a static copy.', 'shipstatic'),
            'cfUrlFilled'      => __('The Destination URL has been set to this address — Save to keep it.', 'shipstatic'),
            'optGitHub'        => __('GitHub Pages', 'shipstatic'),
            'subPathFromUrl'   => __('Taken from the Destination URL.', 'shipstatic'),
            'ghLead'           => __('Publishes the site to GitHub Pages by committing it to a branch of your repository — no web host, PHP or FTP account needed. Each sync is one commit with only the files that changed.', 'shipstatic'),
            'fldGhOwner'       => __('Owner', 'shipstatic'),
            'fldGhRepo'        => __('Repository', 'shipstatic'),
            'fldGhBranch'      => __('Branch', 'shipstatic'),
            'fldGhFolder'      => __('Folder', 'shipstatic'),
            'optGhRoot'        => __('Whole branch (/)', 'shipstatic'),
            'optGhDocs'        => __('The /docs folder', 'shipstatic'),
            /* translators: 1: Git branch name (e.g. gh-pages), 2: folder ("/" or "/docs"). */
            'ghAdvanced'       => __('Advanced: branch %1$s, %2$s', 'shipstatic'),
            'ghAdvancedHelp'   => __('Most sites keep <code>gh-pages</code> and the whole branch. Change them when the site should live next to your code — for example <code>main</code> with the <code>/docs</code> folder, so your source files at the root are left alone — or for a user or organisation site (a repository named <code>owner.github.io</code>), which usually publishes from <code>main</code>. Pick the same branch and folder in the repository’s <strong>Settings → Pages</strong>.', 'shipstatic'),
            'fldGhToken'       => __('Access token', 'shipstatic'),
            'ghReplaceNote'    => __('Everything in this folder of the branch is replaced by the site on each sync, except CNAME, README, LICENSE and .github/.', 'shipstatic'),
            'ghStep1'          => __('Create a repository on GitHub with at least one commit (tick <strong>Add a README</strong>). Make it public — GitHub Pages on a private repository needs a paid GitHub plan.', 'shipstatic'),
            'ghStep2'          => __('Create a fine-grained token (<strong>Settings → Developer settings → Fine-grained tokens → Generate new token</strong>): <strong>Only select repositories</strong> → this repository, with <strong>Contents: Read and write</strong>. Optionally add <strong>Pages: Read-only</strong> so Test can show the Pages status.', 'shipstatic'),
            'ghStep3'          => __('Enter the owner, repository and token here. With the default branch <code>gh-pages</code> GitHub turns Pages on by itself at the first sync; for another branch or <code>/docs</code> (under <strong>Advanced</strong>), choose it afterwards in the repository’s <strong>Settings → Pages → Deploy from a branch</strong>.', 'shipstatic'),
            'ghStep4'          => __('Press <strong>Test</strong>. An empty Destination URL is filled with the Pages address — for a project site <code>https://owner.github.io/repository/</code>, whose <code>/repository/</code> part becomes the sub-path links are built for. <strong>Save</strong>, press <strong>Process</strong>, then Check / Sync as usual.', 'shipstatic'),
            'ghStep5'          => __('For a custom domain, set it in the repository’s <strong>Settings → Pages</strong> (or enter it as the Destination URL); the plugin keeps the <code>CNAME</code> file GitHub uses.', 'shipstatic'),
            'ghLimits'         => __('<strong>Limits:</strong> 25 MiB per file and 1 GB per site. GitHub allows about 500 uploads an hour, so a first sync with many images pauses and carries on by itself. Pages rebuilds at most about 10 times an hour and sets its own cache headers; on a project site robots.txt has no effect. Forms that post back to WordPress don’t work on a static copy.', 'shipstatic'),
            'btnRepair'        => __('Re-pair (new file)', 'shipstatic'),
            'btnPairing'       => __('Pairing…', 'shipstatic'),
            'btnRevoke'        => __('Revoke', 'shipstatic'),
            'btnConfirmRevoke' => __('Yes, revoke', 'shipstatic'),
            /* translators: %s: connector filename */
            'btnDownloadFile'  => __('Download %s', 'shipstatic'),
            'btnPair'          => __('Pair', 'shipstatic'),
            'repairHint'       => __('Re-pairing invalidates the file currently on the destination — upload the new one before the next sync.', 'shipstatic'),
            'btnRebuild'       => __('Rebuild push record', 'shipstatic'),
            'btnRebuilding'    => __('Rebuilding…', 'shipstatic'),
            'rebuildHint'      => __('Ask the connector what is already on the destination and rebuild the push record from it — so after a reset or re-pair, files that are already there and identical don’t upload again.', 'shipstatic'),
            'zipHeading'       => __('Manual deploy', 'shipstatic'),
            'zipHowTo'         => __('How it works', 'shipstatic'),
            'zipLead'          => __('No server access needed. <strong>Export ZIP</strong> packages the current render — with this destination’s replacements applied — into a zip you upload and extract yourself. The plugin uploads nothing and the destination’s sync stats are untouched.', 'shipstatic'),
            'zipStep1'         => __('If the pages list is stale, run <strong>Process</strong> first (Export never renders on its own).', 'shipstatic'),
            'zipStep2'         => __('Press <strong>Export ZIP</strong> and download it when it finishes.', 'shipstatic'),
            'zipStep3'         => __('Extract it into the destination web root — the folder served at the <strong>Destination URL</strong>. If the site lives under a sub-folder, include it in the <strong>Destination URL</strong> (or, with no Destination URL, set <strong>Served from sub-path</strong>) <em>before</em> exporting so links are rewritten for it.', 'shipstatic'),
            'zipStep4'         => __('The zip includes the <code>.htaccess</code> (Apache/LiteSpeed), <code>.gz</code> siblings where supported, <code>sitemap.xml</code> and <code>robots.txt</code>. On nginx, see the <em>Destination Server Configuration</em> tab for the optional snippet and <strong>Check server</strong>.', 'shipstatic'),
            'zipDisabledHint'  => __('Enable this destination to export.', 'shipstatic'),
            'msgSaved'         => __('Saved.', 'shipstatic'),
            'btnTest'          => __('Test', 'shipstatic'),
            'confirmRemoveDest'=> __('Remove this destination?', 'shipstatic'),
            'btnYesRemove'     => __('Yes, remove', 'shipstatic'),
            'btnRemoveDest'    => __('Remove destination', 'shipstatic'),
        ];
    }

    /**
     * Destination tabs.
     *
     * @return array<string,string>
     */
    private static function tabs(): array {
        return [
            'allDestinations' => __('All Destinations', 'shipstatic'),
            'destNameAria'    => __('Destination name', 'shipstatic'),
            'dblClickRename'  => __('Double-click to rename', 'shipstatic'),
            'tabOff'          => __('off', 'shipstatic'),
            'addDestAria'     => __('Add destination', 'shipstatic'),
        ];
    }

    /**
     * Per-destination toolbar.
     *
     * @return array<string,string>
     */
    private static function toolbar(): array {
        return [
            'enableFirst'   => __('Enable this destination first', 'shipstatic'),
            'testConnFirst' => __('Test the connection first', 'shipstatic'),
            'stConnected'   => __('Connected', 'shipstatic'),
            'stPushed'      => __('Pushed', 'shipstatic'),
            'stInSync'      => __('In sync', 'shipstatic'),
            'swEnabled'     => __('Enabled', 'shipstatic'),
            'btnCheck'      => __('Check', 'shipstatic'),
            'btnChecking'   => __('Checking…', 'shipstatic'),
            'btnCheckHint'  => __('Preview what a sync would change on this destination — compares the last render, uploads nothing.', 'shipstatic'),
            'btnSync'       => __('Sync', 'shipstatic'),
            'visitSite'     => __('Visit site ↗', 'shipstatic'),
            'btnExport'     => __('Export ZIP', 'shipstatic'),
            'btnExporting'  => __('Exporting…', 'shipstatic'),
            'btnExportHint' => __('Package the current render into a downloadable zip for this destination — an alternative to Sync for manual deploys. Uploads nothing.', 'shipstatic'),
            'btnDownload'   => __('Download', 'shipstatic'),
        ];
    }

    /**
     * Replacements accordion shell.
     *
     * @return array<string,string>
     */
    private static function replacements(): array {
        return [
            'replacements'    => __('Replacements', 'shipstatic'),
            /* translators: %s: destination name */
            'replacementsFor' => __('Replacements for %s', 'shipstatic'),
            'replacementsSub' => __('(applied to this destination only · saved automatically)', 'shipstatic'),
        ];
    }

    /**
     * Media replacer panel, per page. Shared UI keys here are also read by the
     * Video/Link panels via the merged dictionary.
     *
     * @return array<string,string>
     */
    private static function media(): array {
        return [
            'mediaReplacer'        => __('Image Replacer', 'shipstatic'),
            'mediaSelectPage'      => __('Page', 'shipstatic'),
            'mediaSelectPagePh'    => __('Select a page…', 'shipstatic'),
            'mediaPickPageFirst'   => __('Select a page above to replace its images.', 'shipstatic'),
            'noRenderForMedia'     => __('Process the site first, then choose a page to replace its images.', 'shipstatic'),
            'noMediaOnPage'        => __('No replaceable images on this page.', 'shipstatic'),
            'loadingMedia'         => __('Loading images…', 'shipstatic'),
            'chooseReplacement'    => __('Choose a replacement', 'shipstatic'),
            'useThisMedia'         => __('Use this image', 'shipstatic'),
            'clickToReplace'       => __('Click to replace', 'shipstatic'),
            'noAlt'                => __('— no alt —', 'shipstatic'),
            'replacedWith'         => __('↳ replaced with', 'shipstatic'),
            'noMediaMatch'         => __('No images match the current filter.', 'shipstatic'),
            'mediaPagesWithSwaps'  => __('Pages with replacements:', 'shipstatic'),
            /* translators: %d is the number of image sizes. */
            'nSizes'               => __('%d sizes', 'shipstatic'),
            'mediaNotInLibrary'    => __('not in library', 'shipstatic'),
            'mediaNotInLibraryTip' => __('This image isn’t in the media library, so only this exact URL is swapped — other responsive sizes may remain.', 'shipstatic'),
            'mediaUnavailable'     => __('The WordPress media library is unavailable on this page.', 'shipstatic'),
            'btnReplace'           => __('Replace…', 'shipstatic'),
            'btnRemove'            => __('Remove', 'shipstatic'),
            'filterByPage'         => __('Filter by page…', 'shipstatic'),
            'filterByPageAria'     => __('Filter by page', 'shipstatic'),
            'onlyReplacements'     => __('Only replacements', 'shipstatic'),
        ];
    }

    /**
     * Link replacer panel.
     *
     * @return array<string,string>
     */
    private static function links(): array {
        return [
            'linkReplacer'      => __('Link replacer', 'shipstatic'),
            /* translators: %d is a link count (singular). */
            'nLink'             => __('%d link', 'shipstatic'),
            /* translators: %d is a link count (plural). */
            'nLinks'            => __('%d links', 'shipstatic'),
            /* translators: %d is a count of replaced links. */
            'nReplaced'         => __(', %d replaced', 'shipstatic'),
            'phSearchUrlLabel'  => __('Search URL or label…', 'shipstatic'),
            'linksHint'         => __('Replace a link target (<code>&lt;a&gt;</code>/button <code>href</code>) with another URL for this destination. Leave blank to keep the original.', 'shipstatic'),
            'loadingLinks'      => __('Loading links…', 'shipstatic'),
            'noLinksYet'        => __('No links found yet — run a Check or Sync first so the pages are rendered.', 'shipstatic'),
            'noLabel'           => __('— no label —', 'shipstatic'),
            /* translators: %d is a page count (singular). */
            'nPage'             => __('%d page', 'shipstatic'),
            /* translators: %d is a page count (plural). */
            'nPages'            => __('%d pages', 'shipstatic'),
            'phReplacementUrl'  => __('Replacement URL…', 'shipstatic'),
            'noLinksMatch'      => __('No links match the current filter.', 'shipstatic'),
        ];
    }

    /**
     * Video replacer panel.
     *
     * @return array<string,string>
     */
    private static function videos(): array {
        return [
            'videoReplacer'      => __('Video replacer', 'shipstatic'),
            'videoSelectPage'    => __('Page', 'shipstatic'),
            'videoPickPageFirst' => __('Select a page above to replace its videos.', 'shipstatic'),
            'noVideosOnPage'     => __('No replaceable videos on this page.', 'shipstatic'),
            /* translators: %d is a video count (singular). */
            'nVideo'             => __('%d video', 'shipstatic'),
            /* translators: %d is a video count (plural). */
            'nVideos'            => __('%d videos', 'shipstatic'),
            'phSearchUrlTitle'   => __('Search URL or title…', 'shipstatic'),
            'videosHint'         => __('Local videos swap via the media library; embeds (YouTube, Vimeo, …) take a URL <em>or</em> a video ID (like Bricks). An embed’s <code>origin=</code> is rewritten to this destination automatically when a Destination URL is set.', 'shipstatic'),
            'loadingVideos'      => __('Loading videos…', 'shipstatic'),
            'noVideosYet'        => __('No videos found yet — run a Check or Sync first so the pages are rendered.', 'shipstatic'),
            'localFile'          => __('local file', 'shipstatic'),
            'chooseReplacementVideo' => __('Choose a replacement video', 'shipstatic'),
            'useThisVideo'       => __('Use this video', 'shipstatic'),
            'phReplUrlOrId'      => __('Replacement URL or video ID…', 'shipstatic'),
            'noVideosMatch'      => __('No videos match the current filter.', 'shipstatic'),
        ];
    }

    /**
     * Replacement catalogue entries (accordion titles + locked-row descriptions).
     *
     * @return array<string,string>
     */
    private static function catalog(): array {
        return [
            'catText'       => __('Text', 'shipstatic'),
            'catMedia'      => __('Images', 'shipstatic'),
            'catLinks'      => __('Links', 'shipstatic'),
            'catVideos'     => __('Videos', 'shipstatic'),
            'catTextDesc'   => __('Find and replace visible text on this destination only.', 'shipstatic'),
            'catMediaDesc'  => __('Pick a page, then swap any of its images for another library item — responsive variants are rebuilt automatically.', 'shipstatic'),
            'catLinksDesc'  => __('Rewrite link and button targets per destination, without touching body text.', 'shipstatic'),
            'catVideosDesc' => __('Swap local or embedded videos and fix embed origins for the destination domain.', 'shipstatic'),
        ];
    }

    /**
     * All-destinations panel.
     *
     * @return array<string,string>
     */
    private static function allDestinations(): array {
        return [
            'allDestHeading' => __('All destinations', 'shipstatic'),
            'allDestLead'    => __('Render once, then push to every enabled destination in turn.', 'shipstatic'),
            'stDisabled'     => __('disabled', 'shipstatic'),
            'stOutOfDate'    => __('Out of date', 'shipstatic'),
            'stContentChanged' => __('Content changed — Process to check', 'shipstatic'),
            'stNotPushed'    => __('Not pushed', 'shipstatic'),
            'pruneOption'    => __('Remove deleted files from each destination (prune)', 'shipstatic'),
            'btnWorking'     => __('Working…', 'shipstatic'),
            /* translators: %d is the number of enabled destinations. */
            'syncAllN'       => __('Sync all (%d enabled)', 'shipstatic'),
        ];
    }

    /**
     * Sync progress panel.
     *
     * @return array<string,string>
     */
    private static function progress(): array {
        return [
            'progress'      => __('Progress', 'shipstatic'),
            'lastSync'      => __('Last sync', 'shipstatic'),
            'lastProcess'   => __('Last Process', 'shipstatic'),
            'phCollect'     => __('Collecting URLs', 'shipstatic'),
            'phRender'      => __('Rendering pages', 'shipstatic'),
            'phAssets'      => __('Processing assets', 'shipstatic'),
            'phFinalize'    => __('Finalising', 'shipstatic'),
            'phPackage'     => __('Packaging & deploying', 'shipstatic'),
            'phUpload'      => __('Uploading', 'shipstatic'),
            'phDeploy'      => __('Deploying', 'shipstatic'),
            'phPrune'       => __('Removing old files', 'shipstatic'),
            'phDone'        => __('Done', 'shipstatic'),
            'tgt_done'      => __('Done', 'shipstatic'),
            'tgt_error'     => __('Failed', 'shipstatic'),
            'tgt_cancelled' => __('Cancelled', 'shipstatic'),
            'tgt_active'    => __('Uploading…', 'shipstatic'),
            'tgtPackaging'  => __('Packaging…', 'shipstatic'),
            'zipFallbackNotice' => __('Uploaded file-by-file: the PHP runtime running this sync has no Zip (ZipArchive) extension, so the faster single-package deploy wasn’t available. The result is identical, just slower. This is common with Local’s bundled WP-CLI PHP — most production hosts include Zip and will package automatically.', 'shipstatic'),
            /* translators: %s is the reason package deploy failed. */
            'pkgFallbackNotice' => __('The faster one-shot package deploy didn’t complete — %s. Files were uploaded individually instead: same result, just slower.', 'shipstatic'),
            'phError'       => __('Error', 'shipstatic'),
            'phCancelled'   => __('Cancelled', 'shipstatic'),
            'btnViewErrorDetails' => __('View error details', 'shipstatic'),
            'errorDetailsTitle'   => __('Sync error details', 'shipstatic'),
            'noErrorDetail'       => __('No further detail was recorded for this destination.', 'shipstatic'),
            'lblPages'      => __('Pages', 'shipstatic'),
            'lblAssets'     => __('Assets', 'shipstatic'),
            'lblDeploy'     => __('Deploy', 'shipstatic'),
            'lblUploads'    => __('Uploads', 'shipstatic'),
            /* translators: %d is a file count. */
            'nFiles'        => __('%d files', 'shipstatic'),
            /* translators: %1$d is a file count, %2$s a human size (e.g. "1.2 MB"). */
            'statsSite'     => __('Site: %1$d files · %2$s', 'shipstatic'),
            /* translators: %d is a count of removed files. */
            'nRemoved'      => __('%d removed', 'shipstatic'),
            /* translators: %d is a count of skipped pages. */
            'nSkipped'      => __('%d skipped', 'shipstatic'),
            /* translators: %d is a count of pages flagged as not static-friendly. */
            'nNotStatic'    => __('%d not static-friendly', 'shipstatic'),
            /* translators: %d is a count of errors. */
            'nErrors'       => __('%d errors', 'shipstatic'),
            /* translators: %d is a count of failed uploads (singular). */
            'nFailedUpload' => __('%d failed upload', 'shipstatic'),
            /* translators: %d is a count of failed uploads (plural). */
            'nFailedUploads'=> __('%d failed uploads', 'shipstatic'),
            /* translators: %d is a count of failed files (singular). */
            'nFileFailed'   => __('%d file failed to upload.', 'shipstatic'),
            /* translators: %d is a count of failed files (plural). */
            'nFilesFailed'  => __('%d files failed to upload.', 'shipstatic'),
            'btnRetrying'   => __('Retrying…', 'shipstatic'),
            /* translators: %d is a count of uploads to retry (singular). */
            'btnRetryUpload'  => __('Retry %d upload', 'shipstatic'),
            /* translators: %d is a count of uploads to retry (plural). */
            'btnRetryUploads' => __('Retry %d uploads', 'shipstatic'),
            /* translators: %d is the error count. */
            'summaryErrors'   => __('Errors (%d)', 'shipstatic'),
            /* translators: %d is the skipped count. */
            'summarySkipped'  => __('Skipped (%d)', 'shipstatic'),
            /* translators: %d is the count of pages that won't work on static. */
            'summaryCompat'   => __('Won\'t work on static (%d) — forms / dynamic endpoints (notice only; pages are still uploaded)', 'shipstatic'),
        ];
    }

    /**
     * Manual-run banner.
     *
     * @return array<string,string>
     */
    private static function manualRun(): array {
        return [
            'manualRunTitle' => __('Run this in your terminal to render & sync', 'shipstatic'),
            'manualRunBody'  => __('This host serves only a couple of PHP requests at once, so the browser can\'t render reliably here. WP-CLI does it without that limit. In Local, right-click the site → <em>Open site shell</em>, then run:', 'shipstatic'),
            'manualRunHint'  => __('Add <code>--check</code> for a dry run, or <code>--prune</code> to remove deleted files. Progress will appear here automatically once it starts.', 'shipstatic'),
        ];
    }

    /**
     * Server-config panel.
     *
     * @return array<string,string>
     */
    private static function serverConfig(): array {
        return [
            'serverConfig'     => __('Server config', 'shipstatic'),
            'btnHide'          => __('Hide', 'shipstatic'),
            'btnView'          => __('View', 'shipstatic'),
            'serverConfigLead' => __('The <code>.htaccess</code> is uploaded to the destination automatically (Apache/LiteSpeed). nginx serves the static site as-is; its snippet below is an <em>optional</em> tuning (cache policy + pre-compressed serving) to paste into your server block. Use <strong>Check server</strong> to see what the destination is actually doing.', 'shipstatic'),
            'serverCheckTitle' => __('Server check', 'shipstatic'),
            'serverCheckLead'  => __('Fetches the destination’s home page and one asset, and reports the web server and whether the cache rules are in effect.', 'shipstatic'),
            'btnCheckServer'   => __('Check server', 'shipstatic'),
            'btnRecheck'       => __('Check again', 'shipstatic'),
            'checkingServer'   => __('Checking…', 'shipstatic'),
            'lblDestination'   => __('Destination', 'shipstatic'),
            'noDestUrl'        => __('No destination has a Destination URL yet — set one on the destination to enable the check.', 'shipstatic'),
            'lblServer'        => __('Server', 'shipstatic'),
            'lblCachePolicy'   => __('Cache policy', 'shipstatic'),
            'lblCompression'   => __('Compression', 'shipstatic'),
            'lblHtmlHeaders'   => __('HTML', 'shipstatic'),
            'lblAssetHeaders'  => __('Asset', 'shipstatic'),
            'cacheApplied'     => __('Applied', 'shipstatic'),
            'cachePartial'     => __('Partial', 'shipstatic'),
            'cacheNotApplied'  => __('Not applied', 'shipstatic'),
            'cacheUnknown'     => __('Unknown', 'shipstatic'),
            'gzipYes'          => __('gzip', 'shipstatic'),
            'gzipNo'           => __('none', 'shipstatic'),
            'noCacheControl'   => __('(no Cache-Control header)', 'shipstatic'),
            'urlGuessedHint'   => __('No Destination URL is set, so the host was guessed from the connection — set the URL for an accurate check.', 'shipstatic'),
            /* translators: %s: time the check ran */
            'checkedAt'        => __('Checked %s', 'shipstatic'),
            'serverUnknown'    => __('unknown', 'shipstatic'),
            'serverProxied'    => __('CDN / proxy', 'shipstatic'),
            'cfHeadersNote'    => __('Sent automatically with every Cloudflare deploy — nothing to paste.', 'shipstatic'),
        ];
    }

    /**
     * Text-replacements panel + editor.
     *
     * @return array<string,string>
     */
    private static function textReplacements(): array {
        return [
            'textReplTitle'  => __('Text replacements', 'shipstatic'),
            'optional'       => __('(optional)', 'shipstatic'),
            'btnAdd'         => __('+ Add', 'shipstatic'),
            'textReplWarn'   => __('Literal find/replace, applied only to visible text content between tags — never attributes, scripts or markup. Be specific to avoid unintended matches.', 'shipstatic'),
            'noTextRepl'     => __('No text replacements yet.', 'shipstatic'),
            'lblFind'        => __('Find', 'shipstatic'),
            'lblReplace'     => __('Replace', 'shipstatic'),
            'valEmpty'       => __('(empty)', 'shipstatic'),
            'richEmpty'      => '<em>' . esc_html__('(empty)', 'shipstatic') . '</em>',
            'confirmDelete'  => __('Confirm delete', 'shipstatic'),
            'keep'           => __('Keep', 'shipstatic'),
            'edit'           => __('Edit', 'shipstatic'),
            'delete'         => __('Delete', 'shipstatic'),
            'modalAddText'   => __('Add text replacement', 'shipstatic'),
            'modalEditText'  => __('Edit text replacement', 'shipstatic'),
            'phTextToFind'   => __('Text to find', 'shipstatic'),
            'lblReplaceWith' => __('Replace with', 'shipstatic'),
            'replFormatAria' => __('Replacement format', 'shipstatic'),
            'formatPlain'    => __('Plain', 'shipstatic'),
            'formatRich'     => __('Rich', 'shipstatic'),
            'phReplaceWith'  => __('Replace with', 'shipstatic'),
            'btnAddShort'    => __('Add', 'shipstatic'),
        ];
    }

    /**
     * Rich-text editor toolbar tooltips.
     *
     * @return array<string,string>
     */
    private static function richText(): array {
        return [
            'rtParagraph'    => __('Paragraph', 'shipstatic'),
            'rtH1'           => __('Heading 1', 'shipstatic'),
            'rtH2'           => __('Heading 2', 'shipstatic'),
            'rtH3'           => __('Heading 3', 'shipstatic'),
            'rtBold'         => __('Bold', 'shipstatic'),
            'rtItalic'       => __('Italic', 'shipstatic'),
            'rtUnderline'    => __('Underline', 'shipstatic'),
            'rtBullet'       => __('Bullet list', 'shipstatic'),
            'rtNumbered'     => __('Numbered list', 'shipstatic'),
            'rtLink'         => __('Link', 'shipstatic'),
            'rtClearFormat'  => __('Clear formatting', 'shipstatic'),
            'rtHtmlSource'   => __('HTML source', 'shipstatic'),
            'rtVisualEditor' => __('Visual editor', 'shipstatic'),
            'rtEditHtml'     => __('Edit HTML', 'shipstatic'),
            'rtLinkPrompt'   => __('Link URL', 'shipstatic'),
            'rtPlaceholder'  => __('Replace with…', 'shipstatic'),
            'rtFormatting'   => __('Formatting', 'shipstatic'),
        ];
    }

    /**
     * Strings shared across components.
     *
     * @return array<string,string>
     */
    private static function common(): array {
        return [
            'loading'       => __('Loading…', 'shipstatic'),
            'btnCancel'     => __('Cancel', 'shipstatic'),
            'btnSave'       => __('Save', 'shipstatic'),
            'btnSaving'     => __('Saving…', 'shipstatic'),
            'btnTesting'    => __('Testing…', 'shipstatic'),
            'btnCopy'       => __('Copy', 'shipstatic'),
            'btnCopied'     => __('Copied', 'shipstatic'),
            'btnDismiss'    => __('Dismiss', 'shipstatic'),
            'close'         => __('Close', 'shipstatic'),
        ];
    }
}
