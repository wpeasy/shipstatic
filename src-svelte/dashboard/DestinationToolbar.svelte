<script lang="ts">
  import { untrack } from 'svelte';
  import { Button, ConfirmButton, Switch, Status, Tooltip, fadeUp } from '@wpeasy/ab-ui';
  import { api } from '../shared/api';
  import type { CheckPreview, DestinationDisplay } from '../shared/types';
  import { __ } from '../shared/i18n';

  let {
    destination,
    running,
    checking = false,
    result = null,
    exporting = false,
    onSaved,
    onCheck,
    onSync,
    onProcess,
  }: {
    destination: DestinationDisplay;
    running: boolean;
    /** A Check (no-render diff) is in flight for this destination. */
    checking?: boolean;
    /** Last Check preview for this destination, or null. */
    result?: CheckPreview | null;
    /** An Export ZIP is in flight (for any destination) — disables run buttons here too. */
    exporting?: boolean;
    onSaved: () => void;
    onCheck: (id: string) => void;
    onSync: (id: string, prune: boolean) => void;
    /** Run a Process (render) so a stale page list/preview becomes accurate. */
    onProcess: () => void;
  } = $props();

  const d = untrack(() => destination);

  let enabled = $state(d.enabled);
  let saving = $state(false);

  // Follow reloads: something else can switch it (folder setup turns it on,
  // undoing a WordPress move turns it off).
  $effect(() => {
    enabled = destination.enabled;
  });

  let connected = $derived(destination.status.connected);
  let busy = $derived(saving || running || checking || exporting);

  // Why the run buttons are off (a disabled destination is never synced).
  let blockedReason = $derived(!enabled ? __('enableFirst') : !connected ? __('testConnFirst') : '');
  let canRun = $derived(enabled && connected && !busy);

  let url = $derived.by(() => {
    const explicit = destination.destinationUrl.value;
    if (explicit) return explicit;
    const host = destination.host.value;
    return host ? `https://${host}` : '';
  });

  // Tone for the inline Check result.
  let resultTone = $derived(
    result?.needsProcess ? 'warn' : result?.inSync && !result?.excludedPublished ? 'ok' : 'info',
  );

  async function toggle(value: boolean): Promise<void> {
    enabled = value; // optimistic (Switch is no longer two-way bound)
    saving = true;
    try {
      await api.updateDestination(d.id, { enabled: value });
      onSaved();
    } catch {
      enabled = !value; // revert the optimistic switch on failure
    } finally {
      saving = false;
    }
  }
</script>

<div class="bs-dtoolbar">
  <div class="bs-dstatus">
    <Status status={connected ? 'ok' : 'error'} label={__('stConnected')} />
    <Status status={destination.status.hasPushed ? 'ok' : 'error'} label={__('stPushed')} />
    <Status status={destination.status.inSync ? 'ok' : 'error'} label={__('stInSync')} />
  </div>

  <div class="bs-dtoolbar__switches">
    <Switch label={__('swEnabled')} checked={enabled ? 1 : 0} disabled={busy} onchange={(c) => toggle(c === 1)} />
  </div>

  <div class="bs-dtoolbar__actions">
    <Tooltip content={blockedReason || __('btnCheckHint')} placement="bottom">
      <Button variant="secondary" onclick={() => onCheck(d.id)} disabled={!canRun}>
        {checking ? __('btnChecking') : __('btnCheck')}
      </Button>
    </Tooltip>
    {#if blockedReason}
      <Tooltip content={blockedReason} placement="bottom">
        <ConfirmButton variant="primary" label={__('btnSync')} confirmLabel={__('btnConfirmSync')} onconfirm={() => onSync(d.id, false)} disabled={!canRun} />
      </Tooltip>
    {:else}
      <ConfirmButton variant="primary" label={__('btnSync')} confirmLabel={__('btnConfirmSync')} onconfirm={() => onSync(d.id, false)} disabled={!canRun} />
    {/if}
    {#if url}
      <a class="bs-dtoolbar__link" href={url} target="_blank" rel="noopener noreferrer">{__('visitSite')}</a>
    {/if}
  </div>

  {#if result}
    <div class="bs-dcheck bs-dcheck--{resultTone}" role="status" in:fadeUp>
      <span class="bs-dcheck__msg">{result.message}</span>
      {#if result.needsProcess}
        <Button variant="ghost" size="sm" onclick={onProcess} disabled={busy}>{__('btnProcess')}</Button>
      {/if}
    </div>
  {/if}
</div>

<style>
  .bs-dtoolbar {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--ab-space-4) var(--ab-space-5);
    padding: var(--ab-space-3) var(--ab-space-4);
    background: var(--ab-color-surface);
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-md);
  }

  .bs-dstatus {
    display: flex;
    flex-wrap: wrap;
    gap: var(--ab-space-4);
  }

  .bs-dtoolbar__switches {
    display: flex;
    flex-wrap: wrap;
    gap: var(--ab-space-4);
  }

  .bs-dtoolbar__actions {
    display: flex;
    align-items: center;
    gap: var(--ab-space-3);
    margin-left: auto;
  }

  .bs-dtoolbar__link {
    font-size: var(--ab-text-sm);
    font-weight: var(--ab-weight-medium);
    color: var(--ab-color-primary);
    text-decoration: none;
    white-space: nowrap;
  }

  .bs-dtoolbar__link:hover {
    text-decoration: underline;
  }

  /* Inline Check result: full-width row under the toolbar actions. */
  .bs-dcheck {
    grid-column: 1 / -1;
    flex-basis: 100%;
    display: flex;
    align-items: center;
    gap: var(--ab-space-3);
    padding: var(--ab-space-2) var(--ab-space-3);
    border-radius: var(--ab-radius-md);
    font-size: var(--ab-text-sm);
  }

  .bs-dcheck__msg {
    flex: 1 1 auto;
  }

  .bs-dcheck--ok {
    background: color-mix(in srgb, var(--ab-color-success) 12%, transparent);
    color: var(--ab-color-success);
  }

  .bs-dcheck--info {
    background: color-mix(in srgb, var(--ab-color-primary) 12%, transparent);
    color: var(--ab-color-text-muted);
  }

  .bs-dcheck--warn {
    background: color-mix(in srgb, var(--ab-color-warning) 14%, transparent);
    color: var(--ab-color-text-muted);
  }
</style>
