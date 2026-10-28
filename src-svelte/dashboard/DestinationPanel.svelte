<script lang="ts">
  import { untrack } from 'svelte';
  import { Alert, Button, Collapsible, ConfirmButton, Input, Segmented, Select, Status, Tooltip, fadeUp } from '@wpeasy/ab-ui';
  import { api, connectorDownloadUrl } from '../shared/api';
  import type { Capabilities, ConnectionInput, DestinationDisplay, Transport } from '../shared/types';
  import { __, __f } from '../shared/i18n';
  import FolderWizard from './FolderWizard.svelte';

  let {
    destination,
    capabilities,
    running,
    canRemove,
    exporting = false,
    onSaved,
    onRemove,
    onExport,
  }: {
    destination: DestinationDisplay;
    capabilities: Capabilities;
    running: boolean;
    canRemove: boolean;
    /** An Export ZIP is in flight (for any destination). */
    exporting?: boolean;
    onSaved: () => void;
    onRemove: (id: string) => void;
    onExport: (id: string) => void;
  } = $props();

  type FtpTransport = Exclude<Transport, 'connector' | 'cloudflare' | 'github' | 'folder'>;
  const DEFAULT_PORTS: Record<FtpTransport, number> = { sftp: 22, ftps: 21, ftp: 21 };
  const KNOWN_DEFAULT_PORTS = [0, 21, 22];

  const d = untrack(() => destination);

  let transport = $state<Transport>(d.transport.value);
  let host = $state(d.host.value);
  let port = $state<number>(d.port.value || DEFAULT_PORTS[d.transport.value as FtpTransport] || 21);
  let username = $state(d.username.value);
  let password = $state('');
  let remotePath = $state(d.remotePath.value);
  let basePath = $state(d.basePath.value);
  // With a Destination URL, the sub-path IS that URL's path (the server
  // applies the same rule — Destination::served_base_path()), so the field
  // just shows it. A wp-config constant still wins.
  let urlBasePath = $derived.by<string | null>(() => {
    if (d.basePath.fromConstant) return null;
    try {
      const u = new URL(destinationUrl.trim());
      return '/' + u.pathname.replace(/^\/+|\/+$/g, '') + (u.pathname.replace(/^\/+|\/+$/g, '') ? '/' : '');
    } catch {
      return null;
    }
  });
  let destinationUrl = $state(d.destinationUrl.value);
  // Cloudflare (Workers static assets). The token is write-only, like the password.
  let cfAccountId = $state(d.cfAccountId.value);
  let cfWorker = $state(d.cfWorker.value);
  let cfApiToken = $state('');
  // GitHub Pages. The token is write-only, like the password.
  let ghOwner = $state(d.ghOwner.value);
  let ghRepo = $state(d.ghRepo.value);
  let ghBranch = $state(d.ghBranch.value || 'gh-pages');
  let ghFolder = $state<'/' | '/docs'>(d.ghFolder.value === '/docs' ? '/docs' : '/');
  let ghToken = $state('');
  const ghFolderOptions = [
    { value: '/', label: __('optGhRoot') },
    { value: '/docs', label: __('optGhDocs') },
  ];

  let saving = $state(false);
  let testing = $state(false);
  let message = $state('');
  let messageOk = $state(true);
  let warnings = $state<string[]>([]);
  let busy = $derived(saving || testing || running || exporting);

  // ---- Deploy method: FTP/SFTP vs Connector vs Cloudflare vs GitHub — ONE per
  // destination (it IS the saved `transport`), so a Segmented choice rather
  // than tabs. The FTP protocol picker lives inside the FTP branch.
  type Method = 'ftp' | 'connector' | 'cloudflare' | 'github' | 'folder';
  const isFtp = (t: Transport): t is FtpTransport => t !== 'connector' && t !== 'cloudflare' && t !== 'github' && t !== 'folder';
  let method = $derived<Method>(isFtp(transport) ? 'ftp' : transport);
  let lastFtp: FtpTransport = isFtp(d.transport.value) ? d.transport.value : 'ftps';
  let methodOptions = $derived([
    { value: 'ftp', label: __('tabFtp') },
    { value: 'connector', label: __('optConnector'), disabled: !capabilities.connector },
    { value: 'cloudflare', label: __('optCloudflare'), disabled: !capabilities.cloudflare },
    { value: 'github', label: __('optGitHub'), disabled: !capabilities.github },
    { value: 'folder', label: __('optFolder'), disabled: !capabilities.folder },
  ]);
  function selectMethod(value: string): void {
    if (isFtp(transport)) lastFtp = transport;
    transport = value === 'ftp' ? lastFtp : (value as Transport);
  }


  // How this destination deploys (live from /destinations).
  let deployNote = $derived.by<{ tone: 'ok' | 'muted'; text: string; retest: boolean } | null>(() => {
    const dp = destination.deploy;
    if (!dp) return null;
    if (dp.strategy === 'package') {
      return {
        tone: 'ok',
        retest: false,
        text: dp.hasUrl ? __('deployFastOk') : __('deployFastGuessed'),
      };
    }
    if (dp.disabled) {
      return {
        tone: 'muted',
        retest: true,
        text: __('deployPerFileErr'),
      };
    }
    if (!dp.canBuild) {
      return { tone: 'muted', retest: false, text: __('deployNoZip') };
    }
    return {
      tone: 'muted',
      retest: false,
      text: __('deployPerFile'),
    };
  });

  let retesting = $state(false);
  let retestMsg = $state('');
  async function retestPackage(): Promise<void> {
    retesting = true;
    retestMsg = '';
    try {
      const r = await api.packageTest(d.id);
      retestMsg = r.message;
      if (r.ok) onSaved(); // reload destinations so the badge flips to package
    } catch (e) {
      retestMsg = (e as Error).message;
    } finally {
      retesting = false;
    }
  }

  let transportOptions = $derived([
    { value: 'sftp', label: 'SFTP' + (capabilities.sftp ? '' : __('optUnavailable')), disabled: !capabilities.sftp },
    { value: 'ftps', label: __('optFtps') + (capabilities.ftps ? '' : __('optUnavailable')), disabled: !capabilities.ftps },
    { value: 'ftp', label: __('optFtp') + (capabilities.ftp ? '' : __('optUnavailable')), disabled: !capabilities.ftp },
  ]);

  function handleTransportChange(value: string): void {
    transport = value as Transport;
    if (isFtp(transport) && !d.port.fromConstant && KNOWN_DEFAULT_PORTS.includes(port)) {
      port = DEFAULT_PORTS[transport];
    }
  }

  function payload(includeMeta: boolean): ConnectionInput {
    const data: ConnectionInput = { transport, host, port, username, remotePath, cfAccountId, cfWorker, ghOwner, ghRepo, ghBranch, ghFolder };
    if (password) data.password = password;
    if (cfApiToken) data.cfApiToken = cfApiToken;
    if (ghToken) data.ghToken = ghToken;
    // Test needs the URL too (for the sub-path check); only Save stores it.
    data.destinationUrl = destinationUrl;
    if (includeMeta) {
      data.basePath = basePath;
    }
    return data;
  }

  async function save(): Promise<boolean> {
    saving = true;
    message = '';
    try {
      await api.updateDestination(d.id, payload(true));
      password = '';
      cfApiToken = '';
      ghToken = '';
      warnings = [];
      message = __('msgSaved');
      messageOk = true;
      onSaved();
      return true;
    } catch (e) {
      message = (e as Error).message;
      messageOk = false;
      return false;
    } finally {
      saving = false;
    }
  }

  async function test(): Promise<void> {
    testing = true;
    message = '';
    warnings = [];
    try {
      const r = await api.testDestination(d.id, payload(false));
      message = r.message;
      messageOk = r.ok;
      warnings = r.warnings ?? [];
      // Cloudflare / GitHub Pages: offer the address the site is served at as
      // the Destination URL when none is set yet (sitemap/robots/replacements
      // all build from it).
      if (r.ok && r.suggestedUrl && !destinationUrl && !d.destinationUrl.fromConstant) {
        destinationUrl = r.suggestedUrl;
        message += ' ' + __('cfUrlFilled');
      }
      if (r.ok) {
        onSaved(); // refresh the Connected indicator from the stored test result
      }
    } catch (e) {
      message = (e as Error).message;
      messageOk = false;
    } finally {
      testing = false;
    }
  }

  // ---- Connector pairing. `paired` reflects the SERVER state (the id is
  // plugin-managed, never edited here). The download link is SINGLE-USE and
  // only exists right after a pair (10-minute token), so it's local state and
  // is cleared on click — a lost file means re-pairing, by design.
  let paired = $derived(!!destination.connectorId.value);
  let pairedFile = $derived(`bs-connector-${destination.connectorId.value}.php`);

  // Test needs whatever the chosen method connects with.
  let canTest = $derived(
    method === 'folder'
      ? !!destination.folderPath.value
      : method === 'ftp'
      ? !!host
      : method === 'connector'
        ? paired
        : method === 'github'
          ? !!ghOwner && !!ghRepo && (!!ghToken || d.ghToken.hasValue)
          : !!cfAccountId && !!cfWorker && (!!cfApiToken || d.cfApiToken.hasValue),
  );
  let pairing = $state(false);
  let downloadToken = $state('');

  async function pair(): Promise<void> {
    if (pairing) return;
    pairing = true;
    message = '';
    downloadToken = '';
    // Pairing binds the receiver to the SAVED Destination URL, so persist the
    // form first (this also switches the transport to connector).
    const ok = await save();
    if (!ok) {
      pairing = false;
      return;
    }
    try {
      const r = await api.connectorPair(d.id);
      message = r.message;
      messageOk = r.ok;
      if (r.ok && r.downloadToken) {
        downloadToken = r.downloadToken;
        onSaved();
      }
    } catch (e) {
      message = (e as Error).message;
      messageOk = false;
    } finally {
      pairing = false;
    }
  }

  async function revoke(): Promise<void> {
    message = '';
    try {
      const r = await api.connectorRevoke(d.id);
      message = r.message;
      messageOk = r.ok;
      downloadToken = '';
      onSaved();
    } catch (e) {
      message = (e as Error).message;
      messageOk = false;
    }
  }

  // Rebuild the push record from what the receiver reports — after a wipe,
  // a fresh pairing or "Reset sync state", so identical files don't re-upload.
  let rebuilding = $state(false);
  async function rebuild(): Promise<void> {
    if (rebuilding) return;
    rebuilding = true;
    message = '';
    try {
      const r = await api.connectorRebuild(d.id);
      message = r.message;
      messageOk = r.ok;
      if (r.ok) onSaved();
    } catch (e) {
      message = (e as Error).message;
      messageOk = false;
    } finally {
      rebuilding = false;
    }
  }

  let folderWizardOpen = $state(false);
  let zipHelpOpen = $state(false);
  let cfHelpOpen = $state(!d.cfApiToken.hasValue);
  let ghHelpOpen = $state(!d.ghToken.hasValue);
  // Opens by itself when a Test finds the connector behind nginx.
  let nginxHelpOpen = $state(false);
  $effect(() => {
    if (method === 'connector' && /nginx/i.test(message)) nginxHelpOpen = true;
  });
  // Branch + folder are rarely changed: collapsed unless already non-default.
  let ghAdvancedOpen = $state((d.ghBranch.value || 'gh-pages') !== 'gh-pages' || d.ghFolder.value === '/docs');
</script>

<section class="bs-card bs-stack bs-stack--md">
  <!-- Site fields apply to every deploy method: the URL drives package deploy,
       the server probe and the Connector; the sub-path drives link rewriting
       for Sync AND Export ZIP. -->
  <div class="bs-grid">
    <Input label={__('fldDestUrl')} type="url" bind:value={destinationUrl} placeholder="https://www.example.com" disabled={d.destinationUrl.fromConstant || busy} />
    {#if urlBasePath !== null}
      <Input label={__('fldSubPath')} value={urlBasePath} hint={__('subPathFromUrl')} disabled />
    {:else}
      <Input label={__('fldSubPath')} bind:value={basePath} placeholder="/" disabled={d.basePath.fromConstant || busy} />
    {/if}
  </div>

  <Segmented
    label={__('lblDeployVia')}
    options={methodOptions}
    value={method}
    block
    disabled={d.transport.fromConstant || busy}
    onchange={selectMethod}
  />
  {#if capabilities.multisite}
    <Alert tone="info" title={__('folderMultisiteTitle')} description={__('folderMultisite')} />
  {/if}

  {#key method}
    <div class="bs-stack bs-stack--sm" in:fadeUp>
      {#if method === 'ftp'}
        <p class="bs-lead">{__('ftpLead')}</p>
        <div class="bs-grid">
          <Select
            label={__('fldTransport')}
            id="bs-transport-{d.id}"
            value={transport}
            options={transportOptions}
            disabled={d.transport.fromConstant || busy}
            onchange={(v) => handleTransportChange(String(v))}
          />
          <Input label={__('fldPort')} type="number" bind:value={port} placeholder={transport === 'sftp' ? '22' : '21'} disabled={d.port.fromConstant || busy} />

          <Input label={__('fldHost')} bind:value={host} placeholder="ftp.example.com" disabled={d.host.fromConstant || busy} />
          <Input label={__('fldUsername')} bind:value={username} autocomplete="off" disabled={d.username.fromConstant || busy} />

          <Input label={__('fldPassword')} type="password" bind:value={password} autocomplete="new-password" placeholder={d.password.hasValue ? __('phSavedBlank') : __('fldPassword')} disabled={d.password.fromConstant || busy} />
          <Input label={__('fldRemotePath')} bind:value={remotePath} placeholder={__('phRemoteEmpty')} disabled={d.remotePath.fromConstant || busy} />
        </div>
        {#if transport === 'ftps'}
          <p class="bs-lead">{__('ftpsNote')}</p>
        {:else if transport === 'ftp'}
          <p class="bs-lead">{__('ftpNote')}</p>
        {/if}

        {#if deployNote}
          <p class="bs-deploy bs-deploy--{deployNote.tone}">
            {#if deployNote.tone === 'ok'}⚡ {/if}{deployNote.text}
            {#if deployNote.retest}
              <Button variant="ghost" size="sm" onclick={retestPackage} disabled={retesting || busy}>{retesting ? __('btnReTesting') : __('btnReTest')}</Button>
            {/if}
            {#if retestMsg}<span class="bs-deploy__result"> — {retestMsg}</span>{/if}
          </p>
        {/if}
      {:else if method === 'folder'}
        <p class="bs-lead">{__('folderLead')}</p>
        {#if destination.folderPath.value && d.transport.value === 'folder'}
          <Status status={destination.status.connected ? 'ok' : 'warn'}>
            {#snippet label()}
              <span>{__('folderIn')} <code>{destination.folderPath.value}</code></span>
            {/snippet}
          </Status>
        {:else}
          <Status status="unknown" label={__('folderNotSet')} />
        {/if}
        <div class="bs-row">
          <Button variant={destination.folderPath.value ? 'secondary' : 'primary'} size="sm" onclick={() => (folderWizardOpen = true)} disabled={busy}>
            {destination.folderPath.value ? __('folderChange') : __('folderSetUp')}
          </Button>
        </div>
      {:else if method === 'cloudflare'}
        <p class="bs-lead">{__('cfLead')}</p>
        <div class="bs-grid">
          <Input label={__('fldCfAccount')} bind:value={cfAccountId} placeholder="0123456789abcdef0123456789abcdef" autocomplete="off" disabled={d.cfAccountId.fromConstant || busy} />
          <Input label={__('fldCfWorker')} bind:value={cfWorker} placeholder="my-site" autocomplete="off" disabled={d.cfWorker.fromConstant || busy} />
        </div>
        <Input label={__('fldCfToken')} type="password" bind:value={cfApiToken} autocomplete="new-password" placeholder={d.cfApiToken.hasValue ? __('phSavedBlank') : __('fldCfToken')} disabled={d.cfApiToken.fromConstant || busy} />
        <Collapsible title={__('cfHowTo')} bind:open={cfHelpOpen}>
          <!-- eslint-disable svelte/no-at-html-tags -- trusted PHP dictionary -->
          <ol class="bs-steps">
            <li>{@html __('cfStep1')}</li>
            <li>{@html __('cfStep2')}</li>
            <li>{@html __('cfStep3')}</li>
            <li>{@html __('cfStep4')}</li>
            <li>{@html __('cfStep5')}</li>
          </ol>
          <p class="bs-lead">{@html __('cfLimits')}</p>
          <!-- eslint-enable svelte/no-at-html-tags -->
        </Collapsible>
      {:else if method === 'github'}
        <p class="bs-lead">{__('ghLead')}</p>
        <div class="bs-grid">
          <Input label={__('fldGhOwner')} bind:value={ghOwner} placeholder="my-account" autocomplete="off" disabled={d.ghOwner.fromConstant || busy} />
          <Input label={__('fldGhRepo')} bind:value={ghRepo} placeholder="my-site" autocomplete="off" disabled={d.ghRepo.fromConstant || busy} />
        </div>
        <Input label={__('fldGhToken')} type="password" bind:value={ghToken} autocomplete="new-password" placeholder={d.ghToken.hasValue ? __('phSavedBlank') : 'github_pat_…'} disabled={d.ghToken.fromConstant || busy} />
        <Collapsible title={__f('ghAdvanced', ghBranch || 'gh-pages', ghFolder === '/docs' ? '/docs' : '/')} bind:open={ghAdvancedOpen}>
          <!-- eslint-disable-next-line svelte/no-at-html-tags -- trusted PHP dictionary -->
          <p class="bs-lead">{@html __('ghAdvancedHelp')}</p>
          <div class="bs-grid">
            <Input label={__('fldGhBranch')} bind:value={ghBranch} placeholder="gh-pages" autocomplete="off" disabled={d.ghBranch.fromConstant || busy} />
            <Select
              label={__('fldGhFolder')}
              id="bs-gh-folder-{d.id}"
              value={ghFolder}
              options={ghFolderOptions}
              disabled={d.ghFolder.fromConstant || busy}
              onchange={(v) => (ghFolder = String(v) === '/docs' ? '/docs' : '/')}
            />
          </div>
        </Collapsible>
        <p class="bs-lead">{__('ghReplaceNote')}</p>
        <Collapsible title={__('cfHowTo')} bind:open={ghHelpOpen}>
          <!-- eslint-disable svelte/no-at-html-tags -- trusted PHP dictionary -->
          <ol class="bs-steps">
            <li>{@html __('ghStep1')}</li>
            <li>{@html __('ghStep2')}</li>
            <li>{@html __('ghStep3')}</li>
            <li>{@html __('ghStep4')}</li>
            <li>{@html __('ghStep5')}</li>
          </ol>
          <p class="bs-lead">{@html __('ghLimits')}</p>
          <!-- eslint-enable svelte/no-at-html-tags -->
        </Collapsible>
      {:else}
        <p class="bs-lead">{__('connectorLead')}</p>
        <!-- eslint-disable svelte/no-at-html-tags -- trusted PHP dictionary -->
        <ol class="bs-steps">
          <li>{@html __('connectorStep1')}</li>
          <li>{@html __('connectorStep2')}</li>
          <li>{@html __('connectorStep3')}</li>
          <li>{@html __('connectorStep4')}</li>
        </ol>
        <!-- eslint-enable svelte/no-at-html-tags -->
        <p class="bs-lead">{__('connectorReq')}</p>
        <Collapsible title={__('connectorNginx')} bind:open={nginxHelpOpen}>
          <p class="bs-lead">{__('connectorNginxLead')}</p>
          <!-- eslint-disable svelte/no-at-html-tags -- trusted PHP dictionary -->
          <ul class="bs-steps">
            <li>{@html __('connectorNginx1')}</li>
            <li>{@html __('connectorNginx2')}</li>
            <li>{@html __('connectorNginx3')}</li>
            <li>{@html __('connectorNginx4')}</li>
            <li>{@html __('connectorNginx5')}</li>
          </ul>
          <!-- eslint-enable svelte/no-at-html-tags -->
        </Collapsible>

        <div class="bs-connector bs-stack bs-stack--xs">
          {#if d.connectorId.fromConstant}
            <Status status="info" label={__('connectorConst')} />
          {:else if paired}
            <Status status={destination.status.connected ? 'ok' : 'warn'}>
              {#snippet label()}
                <span>{@html __f('connectorPaired', `<code>${pairedFile}</code>`)}</span>
              {/snippet}
            </Status>
            <div class="bs-row bs-row--wrap">
              {#if downloadToken}
                <a class="ab-button ab-button--primary bs-connector__download" href={connectorDownloadUrl(downloadToken)} onclick={() => setTimeout(() => (downloadToken = ''), 500)}>
                  {__f('btnDownloadFile', pairedFile)}
                </a>
              {/if}
              <Tooltip content={__('repairHint')} placement="bottom">
                <Button variant="secondary" size="sm" onclick={pair} disabled={busy || pairing}>{pairing ? __('btnPairing') : __('btnRepair')}</Button>
              </Tooltip>
              <ConfirmButton variant="ghost" size="sm" label={__('btnRevoke')} confirmLabel={__('btnConfirmRevoke')} onconfirm={revoke} disabled={busy || pairing} />
            </div>
            {#if destination.status.connected}
              <div class="bs-row">
                <Tooltip content={__('rebuildHint')} placement="bottom">
                  <Button variant="ghost" size="sm" onclick={rebuild} disabled={busy || pairing || rebuilding}>{rebuilding ? __('btnRebuilding') : __('btnRebuild')}</Button>
                </Tooltip>
              </div>
            {/if}
          {:else}
            <Status status="unknown" label={__('connectorUnpaired')} />
            <div class="bs-row">
              <Button variant="secondary" size="sm" onclick={pair} disabled={busy || pairing || !destinationUrl}>{pairing ? __('btnPairing') : __('btnPair')}</Button>
            </div>
          {/if}
        </div>
      {/if}
    </div>
  {/key}

  {#if message}
    <p class="bs-msg bs-msg--{messageOk ? 'ok' : 'err'}">{message}</p>
  {/if}
  {#each warnings as w (w)}
    <Alert tone="warning" description={w} />
  {/each}

  <div class="bs-row bs-row--between">
    <div class="bs-row bs-row--wrap">
      <Button variant="secondary" onclick={test} disabled={busy || !canTest}>{testing ? __('btnTesting') : __('btnTest')}</Button>
      <Button variant="primary" onclick={save} disabled={busy}>{saving ? __('btnSaving') : __('btnSave')}</Button>
    </div>
    {#if canRemove}
      <ConfirmButton
        variant="danger"
        size="sm"
        label={__('btnRemoveDest')}
        confirmLabel={__('btnYesRemove')}
        onconfirm={() => onRemove(d.id)}
        disabled={busy}
      />
    {/if}
  </div>

  <!-- Manual ZIP is not a transport (nothing is uploaded), so it is always
       available regardless of the deploy method chosen above. -->
  <div class="bs-zip bs-stack bs-stack--xs">
    <div class="bs-row bs-row--between">
      <h3>{__('zipHeading')}</h3>
      <Tooltip content={destination.enabled ? __('btnExportHint') : __('zipDisabledHint')} placement="bottom">
        <Button variant="secondary" size="sm" onclick={() => onExport(d.id)} disabled={busy || !destination.enabled}>
          {exporting ? __('btnExporting') : __('btnExport')}
        </Button>
      </Tooltip>
    </div>
    <!-- eslint-disable svelte/no-at-html-tags -- trusted PHP dictionary -->
    <p class="bs-lead">{@html __('zipLead')}</p>
    <Collapsible title={__('zipHowTo')} bind:open={zipHelpOpen}>
      <ol class="bs-steps">
        <li>{@html __('zipStep1')}</li>
        <li>{@html __('zipStep2')}</li>
        <li>{@html __('zipStep3')}</li>
        <li>{@html __('zipStep4')}</li>
      </ol>
    </Collapsible>
    <!-- eslint-enable svelte/no-at-html-tags -->
  </div>
</section>

<FolderWizard
  bind:open={folderWizardOpen}
  destId={d.id}
  destUrl={destinationUrl}
  onDone={(u) => {
    // The wizard saved the address; keep this form in step so Save can't undo it.
    if (u && !d.destinationUrl.fromConstant) destinationUrl = u;
    transport = 'folder';
    onSaved();
  }}
/>

<style>
  .bs-card {
    /* Fixed-width Connection panel; Replacements fills the rest of the row. */
    flex: 0 0 34rem;
    max-width: 100%;
    padding: var(--ab-space-5);
    background: var(--ab-color-surface);
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-lg);
    box-shadow: var(--ab-shadow-sm);
  }

  .bs-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--ab-space-4);
  }

  .bs-lead {
    margin: 0;
    color: var(--ab-color-text-muted);
    font-size: var(--ab-text-sm);
  }

  .bs-steps {
    margin: 0;
    padding-left: var(--ab-space-5);
    font-size: var(--ab-text-sm);
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-1);
  }

  .bs-connector {
    padding: var(--ab-space-3) var(--ab-space-4);
    background: var(--ab-color-bg);
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-md);
  }

  /* Same fix as ExportProgress: app.css's `a` colour rule would otherwise
     paint the download link's text in the button's own background colour. */
  a.bs-connector__download,
  a.bs-connector__download:hover {
    color: var(--ab-color-primary-on);
    text-decoration: none;
  }

  .bs-zip {
    padding-top: var(--ab-space-4);
    border-top: 1px solid var(--ab-color-border);
  }

  .bs-deploy {
    margin: 0;
    font-size: var(--ab-text-sm);
  }

  .bs-deploy--ok {
    color: var(--ab-color-success);
  }

  .bs-deploy--muted {
    color: var(--ab-color-text-muted);
  }

  .bs-deploy__result {
    color: var(--ab-color-text-muted);
  }

  .bs-msg {
    margin: 0;
    font-size: var(--ab-text-sm);
  }

  .bs-msg--ok {
    color: var(--ab-color-success);
  }

  .bs-msg--err {
    color: var(--ab-color-danger);
  }

  @media (max-width: 640px) {
    .bs-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
