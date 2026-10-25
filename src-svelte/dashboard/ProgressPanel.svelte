<script lang="ts">
  import { Badge, Button, Progress, Tooltip, fadeUp } from '@wpeasy/ab-ui';
  import type { SyncSnapshot } from '../shared/types';
  import { __, __f } from '../shared/i18n';
  import Modal from '../lib/Modal.svelte';

  let {
    snapshot,
    onRetry,
    retrying = false,
  }: {
    snapshot: SyncSnapshot | null;
    onRetry?: () => void;
    retrying?: boolean;
  } = $props();

  let failedCount = $derived(snapshot?.failedCount ?? 0);
  let canRetry = $derived(failedCount > 0 && !snapshot?.running && !!onRetry);

  const PHASE_LABELS: Record<string, string> = {
    collect: __('phCollect'),
    render: __('phRender'),
    assets: __('phAssets'),
    finalize: __('phFinalize'),
    package: __('phPackage'),
    upload: __('phUpload'),
    deploy: __('phDeploy'),
    prune: __('phPrune'),
    done: __('phDone'),
    error: __('phError'),
    cancelled: __('phCancelled'),
  };

  // Dismissal: once a run has finished it can be closed. Keyed to the run's
  // startedAt so a NEW run re-shows the card (the next run has a fresh id), and
  // persisted to localStorage so a dismissed run stays dismissed across reloads
  // (the status endpoint still returns the last finished snapshot on reload).
  const DISMISS_KEY = 'shipstatic_progress_dismissed';

  function loadDismissed(): number | null {
    try {
      const v = localStorage.getItem(DISMISS_KEY);
      return v ? Number(v) : null;
    } catch {
      return null;
    }
  }

  let dismissedRun = $state<number | null>(loadDismissed());
  let finished = $derived(!snapshot?.running && ['done', 'error', 'cancelled'].includes(snapshot?.phase ?? ''));
  let dismissed = $derived(snapshot?.startedAt != null && dismissedRun === snapshot.startedAt);

  let visible = $derived(snapshot !== null && snapshot.phase !== 'idle' && !dismissed);
  let phaseLabel = $derived(snapshot ? (PHASE_LABELS[snapshot.phase] ?? snapshot.phase) : '');

  function dismiss(): void {
    if (snapshot?.startedAt != null) {
      dismissedRun = snapshot.startedAt;
      try {
        localStorage.setItem(DISMISS_KEY, String(snapshot.startedAt));
      } catch {
        /* ignore */
      }
    }
  }

  // Which destination(s) this run is for (a 'check' run has no real target).
  let destLabel = $derived.by<string>(() => {
    const t = snapshot?.targets;
    if (!t || !t.total || snapshot?.type === 'check') return '';
    if (t.total === 1) return t.name;
    const pos = snapshot?.phase === 'done' ? t.total : Math.min(t.done + 1, t.total);
    return `${t.name} · ${pos} of ${t.total}`;
  });

  let pagesDone = $derived(snapshot?.counts?.pagesDone ?? 0);
  let pagesTotal = $derived(Math.max(snapshot?.totals?.pages ?? 0, pagesDone));
  let assetsDone = $derived(snapshot?.counts?.assetsDone ?? 0);
  let assetsTotal = $derived(Math.max(snapshot?.totals?.assets ?? 0, assetsDone));
  let uploaded = $derived(snapshot?.counts?.uploaded ?? 0);
  let uploadsTotal = $derived(Math.max(snapshot?.totals?.uploads ?? 0, uploaded));
  // The package phase (and a Cloudflare snapshot publish) is one atomic
  // operation, so a file counter would just sit at 0 — show an indeterminate
  // bar and the live stage message instead.
  let isPackaging = $derived(snapshot?.phase === 'package' || snapshot?.phase === 'snapshot');
  let showUploads = $derived((snapshot?.type === 'sync') && uploadsTotal > 0 && !isPackaging);

  // Parallel deploy: fan-out across destinations. When active, show a per-
  // destination progress row instead of the single aggregate upload bar.
  let parallelTargets = $derived(snapshot?.parallelTargets ?? []);
  let isParallel = $derived(!!snapshot?.parallel && parallelTargets.length > 0);
  const TARGET_TONE: Record<string, 'primary' | 'success' | 'warning' | 'danger'> = {
    done: 'success',
    error: 'danger',
    cancelled: 'warning',
    active: 'primary',
    pending: 'primary',
  };

  let tone = $derived(
    snapshot?.phase === 'error'
      ? 'err'
      : snapshot?.phase === 'cancelled'
        ? 'warn'
        : snapshot?.phase === 'done'
          ? 'ok'
          : 'active',
  );
  // Map our run-tone to ab-ui's Progress/Badge tones. 'error' gets its own
  // danger colour, distinct from 'cancelled' (warning) — they used to share
  // one colour, which made a real failure look no different from a plain cancel.
  let abTone = $derived<'primary' | 'success' | 'warning' | 'danger'>(
    tone === 'ok' ? 'success' : tone === 'err' ? 'danger' : tone === 'warn' ? 'warning' : 'primary',
  );

  // Error-details modal: the top badge/message is necessarily a one-line
  // summary; click it to see the full picture — the per-destination failure
  // reasons (parallel sync) and the per-file error list, both already present
  // in the snapshot but previously only shown in a buried <details>.
  let detailsOpen = $state(false);
  let failedTargets = $derived((snapshot?.parallelTargets ?? []).filter((t) => t.status === 'error'));
  let hasErrorDetail = $derived(snapshot?.phase === 'error' && (!!snapshot?.message || failedTargets.length > 0 || (snapshot?.errors?.length ?? 0) > 0));

  function humanBytes(bytes: number): string {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    return `${(bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
  }

  let heading = $derived(!finished ? __('progress') : snapshot?.type === 'sync' ? __('lastSync') : __('lastProcess'));
</script>

{#if visible && snapshot}
  <section class="bs-card bs-stack bs-stack--sm bs-progress--{tone}" in:fadeUp>
    <div class="bs-row bs-row--between">
      <!-- While a run is going it's "Progress"; afterwards it's a record of
           the LAST run, which may be for a different destination than the
           tab you're on — so say so. -->
      <h2>{heading}{#if destLabel}<span class="bs-progress__dest">{' · '}{destLabel}</span>{/if}</h2>
      <div class="bs-progress__head-right">
        {#if hasErrorDetail}
          <Tooltip content={__('btnViewErrorDetails')} placement="bottom">
            <button type="button" class="bs-progress__badgebtn" onclick={() => (detailsOpen = true)}>
              <Badge tone={abTone} variant="soft">{phaseLabel}</Badge>
            </button>
          </Tooltip>
        {:else}
          <Badge tone={abTone} variant="soft">{phaseLabel}</Badge>
        {/if}
        {#if finished}
          <Tooltip content={__('btnDismiss')} placement="bottom-end">
            <Button variant="ghost" size="sm" onclick={dismiss} aria-label={__('btnDismiss')}>×</Button>
          </Tooltip>
        {/if}
      </div>
    </div>

    {#if snapshot.message}
      <p class="bs-progress__msg">{snapshot.message}</p>
    {/if}
    {#if snapshot.reusedRender}
      <p class="bs-progress__msg">{__f('reusedRenderNote', snapshot.reusedRenderAt ?? '')}</p>
    {/if}

    <div class="bs-progress__bar-group">
      <!-- No render bars when Sync reused the current render (nothing rendered). -->
      {#if !snapshot.reusedRender}
      <div class="bs-progress__row">
        <span class="bs-progress__label">{__('lblPages')}</span>
        <Progress class="bs-progress__bar" value={pagesDone} max={Math.max(pagesTotal, 1)} tone={abTone} label={__('lblPages')} />
        <span class="bs-progress__num">{pagesDone}/{pagesTotal}</span>
      </div>
      <div class="bs-progress__row">
        <span class="bs-progress__label">{__('lblAssets')}</span>
        <Progress class="bs-progress__bar" value={assetsDone} max={Math.max(assetsTotal, 1)} tone={abTone} label={__('lblAssets')} />
        <span class="bs-progress__num">{assetsDone}/{assetsTotal}</span>
      </div>
      {/if}
      {#if isParallel}
        {#each parallelTargets as t (t.destId)}
          {@const terminal = t.status === 'done' || t.status === 'error' || t.status === 'cancelled'}
          {@const indeterminate = t.status === 'active' && (t.packaging || t.total === 0)}
          <div class="bs-progress__row">
            <span class="bs-progress__label bs-progress__label--dest">{t.name}</span>
            <Progress
              class="bs-progress__bar"
              value={indeterminate ? null : t.status === 'done' ? Math.max(t.total, 1) : t.uploaded}
              max={Math.max(t.total, 1)}
              tone={TARGET_TONE[t.status] ?? 'primary'}
              label={t.name}
            />
            <span class="bs-progress__num">
              {#if terminal}
                <Badge tone={TARGET_TONE[t.status]} variant="soft">{__('tgt_' + t.status)}</Badge>
              {:else if indeterminate}
                {t.packaging ? __('tgtPackaging') : __('tgt_active')}
              {:else}
                {t.uploaded}/{Math.max(t.total, 0)}
              {/if}
            </span>
          </div>
        {/each}
      {:else if isPackaging}
        <div class="bs-progress__row">
          <span class="bs-progress__label">{__('lblDeploy')}</span>
          <Progress class="bs-progress__bar" value={null} tone={abTone} label={__('lblDeploy')} />
          <span class="bs-progress__num">{__f('nFiles', uploadsTotal)}</span>
        </div>
      {:else if showUploads}
        <div class="bs-progress__row">
          <span class="bs-progress__label">{__('lblUploads')}</span>
          <Progress class="bs-progress__bar" value={uploaded} max={Math.max(uploadsTotal, 1)} tone={abTone} label={__('lblUploads')} />
          <span class="bs-progress__num">{uploaded}/{uploadsTotal}</span>
        </div>
      {/if}
    </div>

    <div class="bs-progress__stats">
      <span>{__f('statsSite', snapshot.counts?.files ?? 0, humanBytes(snapshot.counts?.bytes ?? 0))}</span>
      {#if (snapshot.counts?.pruned ?? 0) > 0}<span>{__f('nRemoved', snapshot.counts?.pruned ?? 0)}</span>{/if}
      {#if (snapshot.skippedCount ?? 0) > 0}<span>{__f('nSkipped', snapshot.skippedCount ?? 0)}</span>{/if}
      {#if (snapshot.compatCount ?? 0) > 0}<span class="bs-progress__warn">{__f('nNotStatic', snapshot.compatCount ?? 0)}</span>{/if}
      {#if (snapshot.errorCount ?? 0) > 0}<span class="bs-progress__err">{__f('nErrors', snapshot.errorCount ?? 0)}</span>{/if}
      {#if failedCount > 0}<span class="bs-progress__err">{failedCount === 1 ? __f('nFailedUpload', failedCount) : __f('nFailedUploads', failedCount)}</span>{/if}
    </div>

    {#if snapshot.packageFallback}
      <div class="bs-progress__notice">{__f('pkgFallbackNotice', snapshot.packageFallback)}</div>
    {:else if snapshot.zipUnavailable}
      <div class="bs-progress__notice">{__('zipFallbackNotice')}</div>
    {/if}

    {#if failedCount > 0}
      <div class="bs-progress__retry">
        <span>{failedCount === 1 ? __f('nFileFailed', failedCount) : __f('nFilesFailed', failedCount)}</span>
        {#if canRetry}
          <Button variant="primary" size="sm" onclick={onRetry} disabled={retrying}>
            {retrying ? __('btnRetrying') : failedCount === 1 ? __f('btnRetryUpload', failedCount) : __f('btnRetryUploads', failedCount)}
          </Button>
        {/if}
      </div>
    {/if}

    {#if snapshot.errors && snapshot.errors.length > 0}
      <details>
        <summary>{__f('summaryErrors', snapshot.errorCount ?? 0)}</summary>
        <ul class="bs-progress__list">
          {#each snapshot.errors as e}
            <li><code>{e.url}</code> — {e.error}</li>
          {/each}
        </ul>
      </details>
    {/if}

    {#if snapshot.skipped && snapshot.skipped.length > 0}
      <details>
        <summary>{__f('summarySkipped', snapshot.skippedCount ?? 0)}</summary>
        <ul class="bs-progress__list">
          {#each snapshot.skipped as s}
            <li><code>{s.url}</code> — {s.reason}</li>
          {/each}
        </ul>
      </details>
    {/if}

    {#if snapshot.compat && snapshot.compat.length > 0}
      <details>
        <summary>{__f('summaryCompat', snapshot.compatCount ?? 0)}</summary>
        <ul class="bs-progress__list">
          {#each snapshot.compat as c}
            <li>
              <code>{c.url}</code>
              <ul class="bs-progress__sublist">
                {#each c.issues as issue}
                  <li><span class="bs-progress__itype">{issue.type}</span>{#if issue.link}: <code>{issue.link}</code>{/if}</li>
                {/each}
              </ul>
            </li>
          {/each}
        </ul>
      </details>
    {/if}
  </section>

  <Modal bind:open={detailsOpen} title={__('errorDetailsTitle')}>
    <div class="bs-progress__errdetail bs-stack bs-stack--sm">
      {#if snapshot.message}<p>{snapshot.message}</p>{/if}

      {#if failedTargets.length > 0}
        <div class="bs-progress__errtargets">
          {#each failedTargets as t (t.destId)}
            <div class="bs-progress__errtarget">
              <strong>{t.name}</strong>
              <p>{t.message || __('noErrorDetail')}</p>
            </div>
          {/each}
        </div>
      {/if}

      {#if snapshot.errors && snapshot.errors.length > 0}
        <details open>
          <summary>{__f('summaryErrors', snapshot.errorCount ?? 0)}</summary>
          <ul class="bs-progress__list">
            {#each snapshot.errors as e}
              <li><code>{e.url}</code> — {e.error}</li>
            {/each}
          </ul>
        </details>
      {/if}
    </div>
  </Modal>
{/if}

<style>
  .bs-card {
    padding: var(--ab-space-5);
    background: var(--ab-color-surface);
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-lg);
    box-shadow: var(--ab-shadow-sm);
  }

  .bs-progress__head-right {
    display: flex;
    align-items: center;
    gap: var(--ab-space-2);
  }

  /* Strip button chrome so the badge inside still looks like a badge — just
     an interactive one (cursor + hover lift) that opens the error-details modal. */
  .bs-progress__badgebtn {
    border: 0;
    padding: 0;
    margin: 0;
    background: none;
    cursor: pointer;
    line-height: 0;
    transition: transform var(--ab-duration-fast, 120ms) var(--ab-ease-standard, ease);
  }

  .bs-progress__badgebtn:hover {
    transform: translateY(-1px);
  }

  .bs-progress__errdetail p {
    margin: 0;
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text);
  }

  .bs-progress__errtargets {
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-2);
  }

  .bs-progress__errtarget {
    padding: var(--ab-space-3);
    border: 1px solid var(--ab-color-danger);
    border-radius: var(--ab-radius-md);
    background: color-mix(in srgb, var(--ab-color-danger) 7%, var(--ab-color-surface));
  }

  .bs-progress__errtarget strong {
    display: block;
    margin-bottom: var(--ab-space-1);
    color: var(--ab-color-text);
    font-size: var(--ab-text-sm);
  }

  .bs-progress__errtarget p {
    margin: 0;
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
  }

  .bs-progress__msg {
    color: var(--ab-color-text-muted);
    font-size: var(--ab-text-sm);
  }

  .bs-progress__bar-group {
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-2);
  }

  .bs-progress__row {
    display: grid;
    grid-template-columns: 4rem 1fr 4rem;
    align-items: center;
    gap: var(--ab-space-3);
  }

  .bs-progress__label {
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
  }

  /* Per-destination rows use the destination name as the label — let it truncate
     instead of widening the (grid) label column. */
  .bs-progress__label--dest {
    max-width: 12ch;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--ab-color-text);
  }

  .bs-progress__dest {
    font-weight: var(--ab-weight-normal);
    color: var(--ab-color-text-muted);
  }

  .bs-progress__num {
    font-size: var(--ab-text-sm);
    font-variant-numeric: tabular-nums;
    text-align: right;
    color: var(--ab-color-text-muted);
  }

  .bs-progress__stats {
    display: flex;
    flex-wrap: wrap;
    gap: var(--ab-space-4);
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
  }

  .bs-progress__err {
    color: var(--ab-color-danger);
  }

  .bs-progress__warn {
    color: var(--ab-color-warning);
  }

  .bs-progress__retry {
    display: flex;
    align-items: center;
    gap: var(--ab-space-4);
    padding: var(--ab-space-3) var(--ab-space-4);
    border: 1px solid var(--ab-color-danger);
    border-radius: var(--ab-radius-md);
    background: color-mix(in srgb, var(--ab-color-danger) 7%, var(--ab-color-surface));
    font-size: var(--ab-text-sm);
  }

  .bs-progress__retry span {
    flex: 1;
  }

  /* Informational fallback notice (e.g. zip unavailable → file-by-file). */
  .bs-progress__notice {
    padding: var(--ab-space-3) var(--ab-space-4);
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-md);
    background: var(--ab-color-surface-2, var(--ab-color-surface));
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
    line-height: 1.5;
  }

  .bs-progress__list {
    margin: var(--ab-space-2) 0 0;
    padding-left: var(--ab-space-5);
    font-size: var(--ab-text-xs);
    color: var(--ab-color-text-muted);
    max-height: 12rem;
    overflow: auto;
  }

  .bs-progress__list code {
    color: var(--ab-color-text);
  }

  .bs-progress__sublist {
    margin: var(--ab-space-1) 0 var(--ab-space-2);
    padding-left: var(--ab-space-4);
    list-style: none;
  }

  .bs-progress__itype {
    font-weight: var(--ab-weight-medium);
    color: var(--ab-color-text);
  }

  summary {
    cursor: pointer;
    font-size: var(--ab-text-sm);
  }
</style>
