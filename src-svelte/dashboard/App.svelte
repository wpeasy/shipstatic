<script lang="ts">
  import { onMount } from 'svelte';
  import { api } from '../shared/api';
  import { __, __f } from '../shared/i18n';
  import type { CheckPreview, DestinationsResponse, DiscoveryMode, ExportSnapshot, Status, SyncSnapshot } from '../shared/types';
  import NoticePanel from './NoticePanel.svelte';
  import { IconShip } from '../lib/icons';
  import DiscoveryToggle from './DiscoveryToggle.svelte';
  import MethodPanel from './MethodPanel.svelte';
  import DestinationTabs from './DestinationTabs.svelte';
  import DestinationPanel from './DestinationPanel.svelte';
  import DestinationToolbar from './DestinationToolbar.svelte';
  import ReplacementsPanel from './ReplacementsPanel.svelte';
  import ManualRunBanner from './ManualRunBanner.svelte';
  import AllDestinationsPanel from './AllDestinationsPanel.svelte';
  import ProgressPanel from './ProgressPanel.svelte';
  import ExportProgress from './ExportProgress.svelte';
  import ServerConfigPanel from './ServerConfigPanel.svelte';
  import SchedulePanel from './SchedulePanel.svelte';
  import LockdownPanel from './LockdownPanel.svelte';
  import SettingsDrawer from './SettingsDrawer.svelte';
  import SetupWizard from './SetupWizard.svelte';
  import { uiPrefs, themeAttr, accentAttr } from '../shared/uiPrefs.svelte';
  import WpMoveBanner from './WpMoveBanner.svelte';
  import { Alert, Badge, Button, ConfirmButton, Tabs, Tooltip, fadeUp, autoContrast } from '@wpeasy/ab-ui';

  const freeVersion = (window as unknown as { shipStaticData?: { version?: string } }).shipStaticData?.version ?? '';

  let dests = $state<DestinationsResponse | null>(null);
  let status = $state<Status | null>(null);
  let sync = $state<SyncSnapshot | null>(null);
  let syncing = $state(false);
  // Synchronous (no-render) Check result for the active destination.
  let checkResult = $state<CheckPreview | null>(null);
  let checking = $state(false);
  // Export ZIP job for the active destination.
  let exportSnap = $state<ExportSnapshot | null>(null);
  let exporting = $state(false);
  let manualRun = $state(false);
  let runCommand = $state('');
  let topTab = $state('destinations');
  let activeTab = $state('');
  let loadError = $state('');
  let settingsOpen = $state(false);
  let wizardOpen = $state(false);

  let destinations = $derived(dests?.destinations ?? []);
  let activeDest = $derived(destinations.find((d) => d.id === activeTab) ?? null);

  const sleep = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));

  async function loadDestinations(): Promise<void> {
    try {
      dests = await api.getDestinations();
      if (!activeTab || !destinations.some((d) => d.id === activeTab) && activeTab !== 'all') {
        activeTab = destinations.length > 1 ? 'all' : (destinations[0]?.id ?? '');
      }
    } catch (e) {
      loadError = (e as Error).message;
    }
  }

  async function loadStatus(): Promise<void> {
    try {
      status = await api.getStatus();
    } catch (e) {
      loadError = (e as Error).message;
    }
  }

  // Grace period for a spawned WP-CLI process to show a heartbeat before we
  // conclude the auto-spawn didn't launch and ask the user to run it by hand.
  const CLI_SPAWN_GRACE_MS = 8000;

  async function runActiveJob(): Promise<void> {
    if (syncing) return;
    syncing = true;
    manualRun = false;
    try {
      // The browser only drives the run itself when there is NO WP-CLI to do it
      // (driver === 'browser'). A /sync/tick web request holds a PHP worker AND
      // fires a loopback render needing a second worker — on hosts with very few
      // workers (Local on Windows runs just two php-cgi processes) the loopback
      // can route back to the busy worker and deadlock. So when WP-CLI IS present
      // (driver === 'cli') we never tick: we poll for the CLI process, and if its
      // auto-spawn never starts we ask the user to run the command instead — then
      // pick the run back up here the moment it's going.
      let driving = false;
      if (sync?.driver !== 'cli') {
        driving = (await api.syncClaim()).owner === 'browser';
      }
      const startedAt = Date.now();
      while ((sync && sync.running) || manualRun) {
        if (driving) {
          sync = await api.syncTick();
          continue;
        }

        await sleep(manualRun ? 2500 : 1500);
        sync = await api.syncStatus();

        // Parallel deploy: the detached CLI coordinator can't spawn the worker
        // pool on Windows (no console), so it flags needsDispatch and we launch
        // the workers from here — a web request with valid handles. Idempotent
        // server-side (it also re-fires if a worker later dies).
        if (sync?.phase === 'deploy' && sync.needsDispatch) {
          try {
            await api.deployDispatch();
          } catch {
            /* transient — the next poll retries while needsDispatch stays set */
          }
        }

        if (sync?.cliAlive) {
          manualRun = false; // a CLI process is driving it (auto-spawn or user-run)
        } else if (sync?.running && Date.now() - startedAt > CLI_SPAWN_GRACE_MS) {
          manualRun = true; // auto-spawn didn't start — prompt the user to run it
        } else if (!sync?.running && !manualRun) {
          break; // finished, nothing pending
        }
      }
    } catch (e) {
      loadError = (e as Error).message;
    } finally {
      syncing = false;
      manualRun = false;
      // Refresh BOTH: the global status panel and the per-destination dots in
      // the toolbar (Connected / Pushed / In sync), which come from /destinations.
      void loadStatus();
      void loadDestinations();
    }
  }

  function dismissManualRun(): void {
    manualRun = false; // the run loop exits on its next tick if no job is active
  }

  async function setDiscoveryMode(mode: DiscoveryMode): Promise<void> {
    if (!status) return;
    const previous = status.discoveryMode;
    status.discoveryMode = mode; // optimistic
    // A mode change makes the existing render (and its page list) stale, so flip
    // the control to "Process" right away; loadStatus() below reconciles the
    // case where the user switches back to the already-rendered mode.
    status.renderCurrent = false;
    try {
      const r = await api.setDiscoveryMode(mode);
      status.discoveryMode = r.discoveryMode;
      void loadStatus();
    } catch (e) {
      status.discoveryMode = previous;
      loadError = (e as Error).message;
    }
  }

  async function setSearchEnabled(enabled: boolean): Promise<void> {
    if (!status) return;
    const previous = status.searchEnabled;
    status.searchEnabled = enabled; // optimistic
    try {
      const r = await api.setSearchEnabled(enabled);
      status.searchEnabled = r.searchEnabled;
      // Pages gain/lose the search script, so the render needs Process again.
      if (r.searchEnabled !== previous) status.renderCurrent = false;
    } catch (e) {
      status.searchEnabled = previous;
      loadError = (e as Error).message;
    }
  }

  async function setRelayEnabled(enabled: boolean): Promise<void> {
    if (!status) return;
    const previous = status.relayEnabled;
    status.relayEnabled = enabled; // optimistic
    try {
      status.relayEnabled = (await api.setRelayEnabled(enabled)).relayEnabled;
      // Deploy-time only: the render stays current, destinations need a Sync.
      void loadStatus();
    } catch (e) {
      status.relayEnabled = previous;
      loadError = (e as Error).message;
    }
  }

  async function setFabEnabled(enabled: boolean): Promise<void> {
    if (!status) return;
    const previous = status.fabEnabled;
    status.fabEnabled = enabled; // optimistic
    try {
      const r = await api.setFabEnabled(enabled);
      status.fabEnabled = r.fabEnabled;
    } catch (e) {
      status.fabEnabled = previous;
      loadError = (e as Error).message;
    }
  }

  async function setConcurrentSyncs(n: number): Promise<void> {
    if (!status) return;
    const previous = status.concurrentSyncs;
    status.concurrentSyncs = n; // optimistic
    try {
      const r = await api.setConcurrentSyncs(n);
      status.concurrentSyncs = r.concurrentSyncs;
    } catch (e) {
      status.concurrentSyncs = previous;
      loadError = (e as Error).message;
    }
  }

  async function setAiToggles(toggles: { aiAllowChanges?: boolean; aiAllowSync?: boolean }): Promise<void> {
    if (!status) return;
    const prev = { aiAllowChanges: status.aiAllowChanges, aiAllowSync: status.aiAllowSync };
    if (toggles.aiAllowChanges !== undefined) status.aiAllowChanges = toggles.aiAllowChanges; // optimistic
    if (toggles.aiAllowSync !== undefined) status.aiAllowSync = toggles.aiAllowSync;
    try {
      const r = await api.setAiToggles(toggles);
      status.aiAllowChanges = r.aiAllowChanges;
      status.aiAllowSync = r.aiAllowSync;
    } catch (e) {
      status.aiAllowChanges = prev.aiAllowChanges;
      status.aiAllowSync = prev.aiAllowSync;
      loadError = (e as Error).message;
    }
  }

  // The exact CLI command for this run, shown if the auto-spawn doesn't start so
  // the user can run it by hand — targeting the same destination(s) they chose.
  function buildRunCommand(type: 'check' | 'sync', dest: string, prune: boolean, changes = false): string {
    let cmd = status?.cli ?? 'wp shipstatic sync';
    if (type === 'check') cmd += ' --check';
    if (changes) cmd += ' --changes';
    if (dest === 'all') cmd += ' --all';
    else if (dest) cmd += ` --dest=${dest}`;
    if (prune) cmd += ' --prune';
    return cmd;
  }

  // Process all: render the site for the current discovery mode so the
  // included/excluded page list is accurate (no upload — just refreshes the
  // manifest). Takes no arguments on purpose: it's wired to onclick handlers,
  // whose event argument must never turn it into "Process changes".
  function processPages(): Promise<void> {
    return runProcess(false);
  }

  async function runProcess(changes: boolean): Promise<void> {
    runCommand = buildRunCommand('check', '', false, changes);
    checkResult = null; // a fresh render invalidates the last preview
    exportSnap = null; // a stale needsProcess/result no longer applies
    try {
      sync = await api.syncStart('check', changes ? { changes: true } : {});
      await runActiveJob();
    } catch (e) {
      loadError = (e as Error).message;
    }
  }

  // "Process changes": only the content recorded as changed since the last
  // Process all (see Docs → Process changes).
  function processChanges(): Promise<void> {
    return runProcess(true);
  }

  // Setup wizard: Finish closes the modal at once and, if the last step's
  // "Process now" is ticked, starts the same Process used elsewhere — its
  // progress shows in the dashboard's progress panel, not behind the modal.
  // Opening it (auto on first run, or manually from Settings) is separate.
  function finishWizard(process: boolean): void {
    wizardOpen = false;
    if (process) void processPages();
  }

  // Manual re-run from the Settings drawer: close the drawer, open the wizard.
  function openWizard(): void {
    settingsOpen = false;
    wizardOpen = true;
  }

  // Check is a read-only preview now: diff the existing render against what was
  // last pushed to this destination — NO re-render (Process + content tracking
  // own the render). If the render is stale/missing the result asks to Process.
  async function startCheck(destId: string): Promise<void> {
    checking = true;
    checkResult = null;
    try {
      checkResult = await api.checkDestination(destId);
    } catch (e) {
      loadError = (e as Error).message;
    } finally {
      checking = false;
    }
  }

  // Confirmation lives in the Sync controls (ab-ui ConfirmButton), so by the time
  // we're here the user has already opted in.
  async function startSync(dest: string, prune: boolean, rerender = false): Promise<void> {
    runCommand = buildRunCommand('sync', dest, prune) + (rerender ? ' --rerender' : '');
    checkResult = null; // the push changes what's in sync — drop the stale preview
    try {
      sync = await api.syncStart('sync', rerender ? { dest, prune, rerender } : { dest, prune });
      await runActiveJob();
    } catch (e) {
      loadError = (e as Error).message;
    }
  }

  // Export ZIP: packages the CURRENT render for one destination into a
  // downloadable zip — no render, no upload. Deliberately simpler than
  // runActiveJob(): export is pure local file I/O in this same process, so
  // there's no CLI driver/claim/dispatch to coordinate and no need to
  // throttle ticks with a sleep.
  async function startExport(destId: string): Promise<void> {
    if (exporting) return;
    exporting = true;
    try {
      exportSnap = await api.exportStart(destId);
      if (exportSnap.needsProcess) return; // same UX as Check's "Process first" prompt
      while (exportSnap && exportSnap.running) {
        exportSnap = await api.exportTick();
      }
    } catch (e) {
      loadError = (e as Error).message;
    } finally {
      exporting = false;
    }
  }

  async function cancelExport(): Promise<void> {
    try {
      exportSnap = await api.exportCancel();
    } catch (e) {
      loadError = (e as Error).message;
    }
  }

  async function retryUploads(): Promise<void> {
    try {
      sync = await api.syncRetry();
      await runActiveJob();
    } catch (e) {
      loadError = (e as Error).message;
    }
  }

  async function cancelSync(): Promise<void> {
    try {
      sync = await api.syncCancel();
      // The run loop's finally normally refreshes these; when cancelling a run
      // that's no longer being driven (e.g. after a refresh) it isn't, so do it
      // here to update the global status + per-destination dots.
      void loadStatus();
      void loadDestinations();
    } catch (e) {
      loadError = (e as Error).message;
    }
  }

  async function resetSync(): Promise<void> {
    try {
      await api.syncReset();
      // Don't wipe the visible progress of the last run — just refresh the
      // status + per-destination dots to reflect the cleared push records.
      await Promise.all([loadStatus(), loadDestinations()]);
    } catch (e) {
      loadError = (e as Error).message;
    }
  }

  async function addDestination(): Promise<void> {
    try {
      dests = await api.addDestination({ name: __f('destinationNumbered', destinations.length + 1) });
      activeTab = destinations[destinations.length - 1]?.id ?? activeTab;
    } catch (e) {
      loadError = (e as Error).message;
    }
  }

  async function renameDestination(id: string, name: string): Promise<void> {
    try {
      dests = await api.updateDestination(id, { name });
    } catch (e) {
      loadError = (e as Error).message;
    }
  }

  async function removeDestination(id: string): Promise<void> {
    try {
      dests = await api.removeDestination(id);
      activeTab = destinations.length > 1 ? 'all' : (destinations[0]?.id ?? '');
    } catch (e) {
      loadError = (e as Error).message;
    }
  }

  onMount(() => {
    void (async () => {
      await loadDestinations();
      await loadStatus();
      try {
        sync = await api.syncStatus();
      } catch {
        /* ignore */
      }

      // First visit since activation: show the setup wizard, and mark it seen
      // immediately (not on Finish) so closing early or reloading mid-wizard
      // never re-triggers it. wp_localize_script booleans arrive as '1'/'' —
      // truthy check, never `=== true`.
      const shipStaticData = (window as unknown as { shipStaticData?: { isFirstRun?: unknown } }).shipStaticData;
      if (shipStaticData?.isFirstRun) {
        wizardOpen = true;
        try {
          await api.setWizardSeen();
        } catch {
          /* non-fatal — worst case the wizard reappears next visit */
        }
      }
    })();
  });
</script>

<div
  class="ab-ui bs-dash bs-stack bs-stack--lg"
  class:ab-compact={uiPrefs.compact}
  style="--ab-rem: 16px"
  style:--ab-primary={uiPrefs.accent === 'custom' ? uiPrefs.customPrimary : undefined}
  style:--ab-accent={uiPrefs.accent === 'custom' ? uiPrefs.customAccent : undefined}
  style:--ab-transition-distance={uiPrefs.accent === 'custom' ? `${uiPrefs.distance}px` : undefined}
  style:--ab-transition-duration={uiPrefs.accent === 'custom' ? `${uiPrefs.duration}ms` : undefined}
  data-theme={themeAttr()}
  data-accent={accentAttr()}
  use:autoContrast
>
  <div class="bs-dash__top">
    <header class="bs-stack bs-stack--xs">
      <h1>
        <IconShip class="bs-dash__mark" size={30} />
        ShipStatic
        <Badge class="bs-verbadge" variant="soft">v{freeVersion}</Badge>
        <span class="bs-dash__by">{__('byPrefix')} <a href="https://brxprod.com" target="_blank" rel="noopener noreferrer">BRXProd</a></span>
      </h1>
      <p class="bs-dash__lead">{__('appLead')}</p>
    </header>
    <Tooltip content={__('settings')} placement="bottom-end">
      <Button class="bs-dash__gear" variant="ghost" shape="square" onclick={() => (settingsOpen = true)} aria-label={__('settings')}>⚙</Button>
    </Tooltip>
  </div>

  {#if loadError}<div class="bs-dash__error">{loadError}</div>{/if}

  {#if status && !status.prettyPermalinks}
    <Alert tone="danger" title={__('permalinksTitle')}>
      <div class="bs-stack bs-stack--xs">
        <!-- Trusted dictionary string (contains a <code> sample URL). -->
        <!-- eslint-disable-next-line svelte/no-at-html-tags -->
        <p>{@html __('permalinksBody')}</p>
        <p><a href="options-permalink.php">{__('permalinksAction')}</a></p>
      </div>
    </Alert>
  {/if}

  {#if status?.wpMove}
    <WpMoveBanner move={status.wpMove} onChanged={() => void loadStatus()} />
  {/if}

  {#if status}
    <div class="bs-globalbar">
      <DiscoveryToggle
        section="actions"
        mode={status.discoveryMode}
        disabled={syncing}
        renderCurrent={status.renderCurrent}
        excludedPublished={status.excludedPublished}
        changes={status.changes}
        onChange={setDiscoveryMode}
        onProcess={processPages}
        onProcessChanges={processChanges}
      />
      <div class="bs-globalbar__notice">
        <NoticePanel isLocal={status.isLocal} cli={status.cli} wpCli={status.wpCli} inline />
      </div>
    </div>
  {/if}

  <SettingsDrawer
    bind:open={settingsOpen}
    {status}
    {syncing}
    onSetDiscoveryMode={setDiscoveryMode}
    onProcess={processPages}
    onSetFabEnabled={setFabEnabled}
    onSetSearchEnabled={setSearchEnabled}
    onSetRelayEnabled={setRelayEnabled}
    onSetAiToggles={setAiToggles}
    onSetConcurrentSyncs={setConcurrentSyncs}
    onOpenWizard={openWizard}
  />

  <SetupWizard
    bind:open={wizardOpen}
    {status}
    {syncing}
    onSetDiscoveryMode={setDiscoveryMode}
    onSetFabEnabled={setFabEnabled}
    onSetSearchEnabled={setSearchEnabled}
    onSetRelayEnabled={setRelayEnabled}
    onSetAiToggles={setAiToggles}
    onFinish={finishWizard}
  />

  <!-- Global progress for the current/last sync, above the tabs and labelled
       with its target — visible whichever tab is open. -->
  <ProgressPanel snapshot={sync} onRetry={retryUploads} retrying={syncing} />

  <!-- Export ZIP can be triggered from a destination's own toolbar or from the
       "All destinations" list, so its progress is global too, not per-panel. -->
  <ExportProgress snapshot={exportSnap} onProcess={processPages} onCancel={cancelExport} />

  <Tabs
    bind:value={topTab}
    items={[
      { value: 'destinations', label: __('tabDestinations') },
      { value: 'server', label: __('tabServerConfig') },
      { value: 'schedule', label: __('tabSchedule') },
      { value: 'lockdown', label: __('tabLockdown') },
    ]}
  >
    {#snippet panel(tab)}
      <div class="bs-stack bs-stack--lg">
        {#if tab === 'destinations'}
          {#if dests}
            <DestinationTabs {destinations} active={activeTab} onSelect={(t) => (activeTab = t)} onAdd={addDestination} onRename={renameDestination} />

            <!-- Swap between the All / per-destination panel fades in on change
                 ({#key} + in:fadeUp, the lib's content-swap transition). -->
            {#key activeTab}
              <div class="bs-stack bs-stack--lg" in:fadeUp>
                {#if activeTab === 'all'}
                  <div class="bs-panels">
                    <AllDestinationsPanel
                      {destinations}
                      running={syncing}
                      exporting={exporting}
                      renderCurrent={status?.renderCurrent ?? true}
                      changesPending={status?.changes?.count ?? 0}
                      onSyncAll={(prune, rerender) => startSync('all', prune, rerender)}
                      onSyncOne={(id, prune, rerender) => startSync(id, prune, rerender)}
                      onExportOne={startExport}
                      onSelect={(id) => (activeTab = id)}
                    />
                  </div>
                {:else if activeDest}
                  <DestinationToolbar
                    destination={activeDest}
                    running={syncing}
                    checking={checking}
                    result={checkResult?.destId === activeDest.id ? checkResult : null}
                    exporting={exporting}
                    onSaved={loadDestinations}
                    onCheck={startCheck}
                    onSync={startSync}
                    onProcess={processPages}
                  />
                  <div class="bs-destrow">
                    <DestinationPanel
                      destination={activeDest}
                      capabilities={dests.capabilities}
                      running={syncing}
                      canRemove={destinations.length > 1}
                      exporting={exporting}
                      onSaved={loadDestinations}
                      onRemove={removeDestination}
                      onExport={startExport}
                    />
                    <ReplacementsPanel destination={activeDest} running={syncing} onSaved={loadDestinations} />
                  </div>
                {/if}
              </div>
            {/key}
          {/if}

          {#if manualRun && status}
            <ManualRunBanner command={runCommand || status.cli} onDismiss={dismissManualRun} />
          {/if}

          <div class="bs-row bs-controls">
            {#if syncing || sync?.running}
              <Button variant="ghost" size="sm" onclick={cancelSync}>{__('btnCancel')}</Button>
            {/if}
            <ConfirmButton
              variant="ghost"
              size="sm"
              label={__('btnResetSync')}
              confirmLabel={__('btnConfirmReset')}
              onconfirm={resetSync}
            />
            <span class="bs-controls__hint">{__('resetHint')}</span>
          </div>
        {:else if tab === 'schedule'}
          <SchedulePanel {destinations} />
        {:else if tab === 'lockdown'}
          <LockdownPanel {destinations} />
        {:else}
          <div class="bs-panels">
            <MethodPanel method={status?.method ?? null} mode={status?.discoveryMode} />
            <ServerConfigPanel {destinations} defaultDestId={activeTab} />
          </div>
        {/if}
      </div>
    {/snippet}
  </Tabs>
</div>

<style>
  .bs-dash {
    padding: var(--ab-space-5);
    background: var(--ab-color-bg);
    border-radius: var(--ab-radius-lg);
  }

  /* Header row: title block on the left, settings gear pinned top-right. */
  .bs-dash__top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--ab-space-4);
  }

  /* The gear is the only way into Settings — 2× the default icon size so it
     reads as a real control, not a stray glyph (3× was too dominant).
     `class` is passed through ab-ui's Button, so this is a scoped override,
     not a lib edit. */
  .bs-dash__top :global(.bs-dash__gear) {
    font-size: 2em;
    width: auto;
    height: auto;
    line-height: 1;
    padding: var(--ab-space-1) var(--ab-space-2);
  }


  .bs-panels {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 500px));
    gap: var(--ab-space-5);
    align-items: stretch;
  }

  /* Single-destination layout: a full-width flex row — the Connection panel is a
     fixed width and the Replacements panel fills the rest (wraps when narrow). */
  .bs-destrow {
    display: flex;
    flex-wrap: wrap;
    gap: var(--ab-space-5);
    align-items: flex-start;
  }

  .bs-dash__lead {
    color: var(--ab-color-text-muted);
    font-size: var(--ab-text-lg);
  }

  :global(.ab-ui .ab-badge.bs-verbadge) {
    margin-left: var(--ab-space-2);
    vertical-align: middle;
  }

  /* The ship mark in the accent colour; nudged to sit on the text baseline. */
  .bs-dash__top :global(svg.bs-dash__mark) {
    display: inline-block; /* beats ab-ui's `.ab-ui svg { display: block }` */
    color: var(--ab-color-primary);
    vertical-align: -0.2em;
    margin-right: var(--ab-space-1);
  }

  .bs-dash__by {
    margin-left: var(--ab-space-3);
    font-size: var(--ab-text-sm);
    font-weight: var(--ab-weight-normal);
    color: var(--ab-color-text-muted);
    vertical-align: middle;
  }

  .bs-dash__by a {
    color: var(--ab-color-primary);
    text-decoration: none;
  }

  .bs-dash__by a:hover {
    text-decoration: underline;
  }

  .bs-dash__error {
    padding: var(--ab-space-3) var(--ab-space-4);
    border: 1px solid var(--ab-color-danger);
    border-radius: var(--ab-radius-md);
    color: var(--ab-color-danger);
    background: color-mix(in srgb, var(--ab-color-danger) 8%, transparent);
  }

  .bs-globalbar {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    flex-wrap: wrap;
    gap: var(--ab-space-3) var(--ab-space-5);
    padding: var(--ab-space-3) var(--ab-space-4);
    background: var(--ab-color-surface);
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-md);
  }

  .bs-globalbar__notice {
    flex: 0 1 auto;
    min-width: 0;
    margin-left: auto;
  }

  .bs-controls__hint {
    font-size: var(--ab-text-xs);
    color: var(--ab-color-text-muted);
  }
</style>
