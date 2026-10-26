<script lang="ts">
  import { Alert, Button, Checkbox, Collapsible, ConfirmButton, CopyButton, Input, Select, Status, Switch, Table, Tag, fadeUp } from '@wpeasy/ab-ui';
  import { api } from '../shared/api';
  import type { DestinationDisplay, LockdownEntry, LockdownProbe, LockdownSettings } from '../shared/types';
  import { __, __f } from '../shared/i18n';
  import ListSkeleton from '../lib/ListSkeleton.svelte';

  let {
    destinations = [],
  }: {
    destinations?: DestinationDisplay[];
  } = $props();

  let ld = $state<LockdownSettings | null>(null);
  let probe = $state<LockdownProbe | null>(null);
  let error = $state('');
  let problems = $state<string[]>([]);
  let saving = $state(false);
  let probing = $state(false);
  let linkSaved = $state(false);
  /** The browser check after turning it on: null = not run, true = got in. */
  let browserIn = $state<boolean | null>(null);
  let newCidr = $state('');
  let newLabel = $state('');
  let helpOpen = $state(false);
  let snippetOpen = $state(false);

  const columns = [
    { key: 'cidr', header: __('ldColAddress'), width: '30%' },
    { key: 'label', header: __('ldColLabel') },
    { key: 'added', header: __('ldColAdded'), width: '22%' },
    { key: 'actions', header: '', width: '6rem' },
  ];

  const viaLabel: Record<LockdownEntry['via'], string> = {
    enable: __('ldViaEnable'),
    key: __('ldViaKey'),
    manual: __('ldViaManual'),
    cli: __('ldViaCli'),
  };

  let redirectOptions = $derived([
    { value: '', label: __('ldRedirectAuto') },
    ...destinations
      .filter((d) => d.destinationUrl.value.trim() !== '')
      .map((d) => ({ value: d.id, label: `${d.name || d.id} — ${d.destinationUrl.value}` })),
    { value: 'none', label: __('ldRedirectNone') },
  ]);

  // What the probe found, as a tone + message.
  let probeNote = $derived.by((): { tone: 'success' | 'warning' | 'danger' | 'info'; text: string } | null => {
    if (!ld?.enabled || !probe) return null;
    switch (probe.result) {
      case 'server':
        return { tone: 'success', text: __('ldProbeServer') };
      case 'gate':
        return { tone: 'success', text: __('ldProbeGate') };
      case 'wordpress':
        if (ld.method === 'htaccess') return { tone: 'warning', text: __('ldProbeWpHtaccess') };
        if (ld.method === 'userIni') return { tone: 'warning', text: __('ldProbeWpUserIni') };
        return { tone: 'info', text: __('ldProbeWpOnly') };
      case 'allowed':
        return { tone: 'info', text: __f('ldProbeAllowed', probe.address) };
      case 'open':
        return { tone: 'danger', text: __('ldProbeOpen') };
      case 'error':
        return { tone: 'warning', text: __f('ldProbeError', probe.message) };
      default:
        return null;
    }
  });

  let filesNeedWriting = $derived(
    !!ld?.enabled && (ld.layers.gate === 'missing' || ld.layers.mu === 'missing' || ld.layers.htaccess === 'missing' || ld.layers.htaccess === 'notFirst' || ld.layers.userIni === 'missing' || ld.layers.version === 'old'),
  );

  function when(ts: number): string {
    return ts ? new Date(ts * 1000).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
  }

  function take(next: LockdownSettings): void {
    problems = next.errors ?? [];
    ld = next;
  }

  async function load(): Promise<void> {
    try {
      take(await api.getLockdown());
      if (ld?.enabled) runProbe();
    } catch (e) {
      error = (e as Error).message;
    }
  }

  async function runProbe(): Promise<void> {
    probing = true;
    try {
      probe = await api.probeLockdown();
    } catch (e) {
      probe = { result: 'error', address: '', message: (e as Error).message };
    } finally {
      probing = false;
    }
  }

  // Ask for the home page as a stranger with this browser's address would:
  // no cookies, so only the address is tested; no redirects followed.
  async function checkBrowser(): Promise<void> {
    if (!ld) return;
    try {
      const url = new URL(ld.checkUrl);
      url.searchParams.set('shipstatic_lockdown_check', Math.random().toString(36).slice(2));
      const res = await fetch(url.toString(), { credentials: 'omit', redirect: 'manual', cache: 'no-store' });
      browserIn = res.status === 204;
    } catch {
      browserIn = false;
    }
  }

  async function save(patch: Parameters<typeof api.saveLockdown>[0]): Promise<boolean> {
    saving = true;
    error = '';
    try {
      take(await api.saveLockdown(patch));
      return true;
    } catch (e) {
      error = (e as Error).message;
      return false;
    } finally {
      saving = false;
    }
  }

  async function setEnabled(on: boolean): Promise<void> {
    browserIn = null;
    probe = null;
    if (!(await save({ enabled: on })) || !on || !ld?.enabled) return;
    await checkBrowser();
    runProbe();
  }

  async function addEntry(): Promise<void> {
    if (newCidr.trim() === '') return;
    if (await save({ add: { cidr: newCidr.trim(), label: newLabel.trim() } })) {
      newCidr = '';
      newLabel = '';
    }
  }

  async function newLink(): Promise<void> {
    saving = true;
    error = '';
    try {
      take(await api.newLockdownLink());
      linkSaved = false;
    } catch (e) {
      error = (e as Error).message;
    } finally {
      saving = false;
    }
  }

  load();
</script>

<section class="bs-card bs-stack bs-stack--sm">
  <h2>{__('ldHeading')}</h2>
  <p class="bs-ld__lead">{__('ldLead')}</p>

  {#if error}
    <Alert tone="danger" description={error} />
  {/if}
  {#each problems as p (p)}
    <Alert tone="danger" description={p} />
  {/each}

  {#if !ld}
    <ListSkeleton />
  {:else}
    <div class="bs-stack bs-stack--md" in:fadeUp>
      <!-- 1. The unlock link: saved before Lockdown can be turned on. -->
      <div class="bs-stack bs-stack--sm">
        <h3>{__('ldLinkHeading')}</h3>
        <p class="bs-ld__help">{__('ldLinkHelp')}</p>
        <div class="bs-ld__link">
          <Input value={ld.link} readonly aria-label={__('ldLinkHeading')} />
          <CopyButton text={ld.link} variant="button" label={__('ldCopy')} copiedLabel={__('ldCopied')} oncopy={() => (linkSaved = true)} />
          <ConfirmButton variant="ghost" size="sm" label={__('ldNewLink')} confirmLabel={__('ldNewLinkConfirm')} onconfirm={newLink} disabled={saving} />
        </div>
        {#if !ld.enabled}
          <Checkbox label={__('ldLinkSaved')} checked={linkSaved ? 1 : 0} onchange={(c) => (linkSaved = c === 1)} />
        {/if}
      </div>

      <!-- 2. On / off. -->
      <div class="bs-stack bs-stack--sm">
        <Switch
          label={__('ldEnabled')}
          checked={ld.enabled ? 1 : 0}
          disabled={saving || (!ld.enabled && !linkSaved)}
          onchange={(c) => setEnabled(c === 1)}
        />
        <p class="bs-ld__help">
          {__f('ldYourIp', ld.yourIp || '?')}
          {#if ld.enabled}
            <Tag size="sm" tone={ld.yourIpAllowed ? 'success' : 'warning'} variant="soft" label={ld.yourIpAllowed ? __('ldAllowed') : __('ldNotAllowed')} />
            {#if !ld.yourIpAllowed && ld.yourIp}
              <Button variant="ghost" size="sm" onclick={() => save({ add: { cidr: ld?.yourIp ?? '', label: __('ldMyAddressLabel') } }).then(() => (browserIn = null))} disabled={saving}>{__('ldAddMine')}</Button>
            {/if}
          {/if}
        </p>
        {#if ld.forwarded}
          <Alert tone="warning" description={__f('ldForwarded', ld.forwarded, ld.yourIp)} />
        {/if}

        {#if ld.enabled}
          <div class="bs-ld__status">
            <Status status="ok" label={__('ldStatusOn')} />
            <span class="bs-ld__help">
              {ld.method === 'htaccess' ? __('ldMethodHtaccess') : ld.method === 'userIni' ? __('ldMethodUserIni') : __('ldMethodWordPress')}
            </span>
          </div>

          {#if browserIn === false}
            <Alert tone="danger" title={__('ldBrowserBlockedTitle')} description={__('ldBrowserBlocked')}>
              <div class="bs-row">
                <Button variant="secondary" size="sm" onclick={() => setEnabled(false)} disabled={saving}>{__('ldTurnOff')}</Button>
              </div>
            </Alert>
          {/if}

          {#if probeNote}
            <Alert tone={probeNote.tone} description={probeNote.text} />
          {/if}

          {#if filesNeedWriting}
            <Alert tone="warning" description={__('ldFilesChanged')}>
              <div class="bs-row">
                <Button variant="secondary" size="sm" onclick={() => save({}).then(runProbe)} disabled={saving}>{__('ldWriteAgain')}</Button>
              </div>
            </Alert>
          {/if}

          <div class="bs-row">
            <Button variant="ghost" size="sm" onclick={runProbe} disabled={probing}>{probing ? __('ldChecking') : __('ldCheckAgain')}</Button>
          </div>
        {:else}
          <Status status="neutral" label={__('ldStatusOff')} />
        {/if}
      </div>

      <!-- 3. Allowed addresses. -->
      <div class="bs-stack bs-stack--sm">
        <h3>{__('ldListHeading')}</h3>
        <p class="bs-ld__help">{__('ldListHelp')}</p>
        {#if ld.entries.length}
          <Table {columns} rows={ld.entries} {cell} compact />
        {:else}
          <p class="bs-ld__help">{__('ldListEmpty')}</p>
        {/if}
        <div class="bs-ld__add">
          <Input bind:value={newCidr} placeholder="203.0.113.7" aria-label={__('ldColAddress')} disabled={saving} />
          <Input bind:value={newLabel} placeholder={__('ldLabelPlaceholder')} aria-label={__('ldColLabel')} disabled={saving} />
          <Button variant="secondary" size="sm" onclick={addEntry} disabled={saving || newCidr.trim() === ''}>{__('ldAdd')}</Button>
        </div>
      </div>

      <!-- 4. Where blocked visitors go. -->
      <div class="bs-stack bs-stack--sm">
        <h3>{__('ldRedirectHeading')}</h3>
        <Select
          value={ld.redirect}
          options={redirectOptions}
          aria-label={__('ldRedirectHeading')}
          disabled={saving}
          onchange={(v) => save({ redirect: String(v) })}
        />
        <p class="bs-ld__help">{ld.redirectTarget ? __f('ldRedirectTarget', ld.redirectTarget) : __('ldRedirect403')}</p>
      </div>

      <Switch label={__('ldNotify')} checked={ld.notify ? 1 : 0} disabled={saving} onchange={(c) => save({ notify: c === 1 })} />

      {#if ld.nginxSnippet}
        <Collapsible title={__('ldNginxHeading')} bind:open={snippetOpen}>
          <div class="bs-stack bs-stack--sm">
            <p class="bs-ld__help">{__('ldNginxHelp')}</p>
            <pre class="bs-ld__pre">{ld.nginxSnippet}</pre>
            <div class="bs-row">
              <CopyButton text={ld.nginxSnippet} variant="button" label={__('ldCopy')} copiedLabel={__('ldCopied')} />
            </div>
          </div>
        </Collapsible>
      {/if}

      <Collapsible title={__('ldHelpHeading')} bind:open={helpOpen}>
        <!-- eslint-disable svelte/no-at-html-tags -- trusted PHP dictionary -->
        <ol class="bs-ld__steps">
          <li>{@html __('ldHelp1')}</li>
          <li>{@html __('ldHelp2')}</li>
          <li>{@html __('ldHelp3')}</li>
          <li>{@html __('ldHelp4')}</li>
        </ol>
        <!-- eslint-enable svelte/no-at-html-tags -->
      </Collapsible>
    </div>
  {/if}
</section>

{#snippet cell(row: Record<string, any>, col: { key: string })}
  {#if col.key === 'cidr'}
    <code>{row.display ?? row.cidr}</code>
    {#if row.display === ld?.yourIp}
      <Tag size="sm" tone="primary" variant="soft" label={__('ldYou')} />
    {/if}
  {:else if col.key === 'label'}
    {row.label || '—'}
  {:else if col.key === 'added'}
    <span class="bs-ld__help">{when(row.added)} · {viaLabel[row.via as LockdownEntry['via']] ?? row.via}</span>
  {:else}
    <ConfirmButton variant="ghost" size="sm" label={__('ldRemove')} confirmLabel={__('ldRemoveConfirm')} onconfirm={() => save({ remove: row.cidr })} disabled={saving} />
  {/if}
{/snippet}

<style>
  .bs-card {
    padding: var(--ab-space-5);
    background: var(--ab-color-surface);
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-lg);
    box-shadow: var(--ab-shadow-sm);
    max-width: 52rem;
  }

  .bs-ld__lead,
  .bs-ld__help {
    color: var(--ab-color-text-muted);
    font-size: var(--ab-text-sm);
  }

  .bs-ld__link,
  .bs-ld__add {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--ab-space-2);
  }

  .bs-ld__link :global(.ab-field) {
    flex: 1 1 24rem;
  }

  .bs-ld__add :global(.ab-field) {
    flex: 1 1 12rem;
  }

  .bs-ld__status {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--ab-space-3);
  }

  .bs-ld__pre {
    margin: 0;
    padding: var(--ab-space-3);
    overflow-x: auto;
    font-size: var(--ab-text-xs);
    background: var(--ab-color-bg);
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-md);
  }

  .bs-ld__steps {
    margin: 0;
    padding-left: var(--ab-space-5);
    font-size: var(--ab-text-sm);
  }

  .bs-ld__steps li + li {
    margin-top: var(--ab-space-2);
  }
</style>
