<script lang="ts">
  import { Button, ConfirmButton, Select, Table, Tabs, Tag, Tooltip } from '@wpeasy/ab-ui';
  import Modal from '../lib/Modal.svelte';
  import ListSkeleton from '../lib/ListSkeleton.svelte';
  import { api } from '../shared/api';
  import type { DiscoveryMode, PageRef, Status } from '../shared/types';
  import { __, __f } from '../shared/i18n';

  let {
    mode,
    disabled = false,
    renderCurrent = false,
    excludedPublished = 0,
    section = 'all',
    changes = { count: 0, unlinked: [], sitewide: [] },
    onChange,
    onProcess,
    onProcessChanges,
  }: {
    mode: DiscoveryMode;
    disabled?: boolean;
    /** Render reflects current mode + content, so the list is accurate. */
    renderCurrent?: boolean;
    /** Published pages not in the export — shown as a hint beside "View list". */
    excludedPublished?: number;
    /** Which half to render: `mode` (the select + help, in the Settings drawer),
        `actions` (Process / View list / excluded, on the toolbar), or `all`. */
    section?: 'mode' | 'actions' | 'all';
    /** Recorded changes since the last Process all (offers "Process changes"). */
    changes?: Status['changes'];
    onChange: (mode: DiscoveryMode) => void;
    /** Process all: run a full render so the list becomes accurate. */
    onProcess: () => void;
    /** Process changes: render only the recorded changes. */
    onProcessChanges?: () => void;
  } = $props();

  const docsUrl = (window as unknown as { shipStaticData?: { docsUrl?: string } }).shipStaticData?.docsUrl ?? '';

  const sitewideLabel: Record<string, string> = {
    menus: __('swMenus'),
    templates: __('swTemplates'),
    bricks: __('swBricks'),
    widgets: __('swWidgets'),
    site: __('swSite'),
    permalinks: __('swPermalinks'),
    theme: __('swTheme'),
    many: __('swMany'),
  };
  let sitewideText = $derived(changes.sitewide.map((r) => sitewideLabel[r] ?? r).join(', '));

  let helpOpen = $state(false);

  // Included/Excluded pages modal.
  let pagesOpen = $state(false);
  let activeTab = $state('included');
  let included = $state<PageRef[]>([]);
  let excluded = $state<PageRef[]>([]);
  let loadingPages = $state(false);
  let pagesErr = $state('');

  let modeOptions = $derived([
    { value: 'linked', label: __('onlyLinkedPages') },
    { value: 'all', label: __('allPublished') },
    { value: 'manual', label: __('manualSelection') },
  ]);

  let currentList = $derived(activeTab === 'included' ? included : excluded);

  let columns = $derived([
    { key: 'title', header: __('colPage'), width: '35%' },
    { key: 'path', header: __('colPath'), width: '30%' },
    { key: 'tags', header: activeTab === 'included' ? __('colNotices') : __('colReason') },
  ]);

  function set(next: string): void {
    if (next !== mode) onChange(next as DiscoveryMode);
  }

  async function openPages(tab: 'included' | 'excluded' = 'included'): Promise<void> {
    pagesOpen = true;
    activeTab = tab;
    loadingPages = true;
    pagesErr = '';
    try {
      const r = await api.getPagesOverview();
      included = r.included;
      excluded = r.excluded;
    } catch (e) {
      pagesErr = (e as Error).message;
    } finally {
      loadingPages = false;
    }
  }

  function pagePath(url: string): string {
    try {
      return new URL(url).pathname || url;
    } catch {
      return url;
    }
  }
</script>

<div class="bs-disc">
  {#if section !== 'actions'}
    <span class="bs-disc__label" id="bs-disc-label">{__('pagesToInclude')}</span>
    <Select value={mode} options={modeOptions} {disabled} aria-label={__('pagesToInclude')} onchange={(v) => set(String(v))} />
    <Tooltip content={__('whatsDifference')} placement="bottom">
      <Button shape="circle" variant="ghost" size="sm" aria-label={__('whatsDifference')} onclick={() => (helpOpen = true)}>?</Button>
    </Tooltip>
  {/if}
  {#if section !== 'mode'}
    {#if renderCurrent}
      <Button variant="secondary" size="sm" {disabled} onclick={() => openPages('included')}>{__('btnViewList')}</Button>
      {#if excludedPublished > 0}
        <Tooltip content={__('excludedHintTip')} placement="bottom">
          <Button
            variant="warning"
            size="sm"
            {disabled}
            onclick={() => openPages('excluded')}
          >⚠ {__f('excludedHint', excludedPublished)}</Button>
        </Tooltip>
      {/if}
    {:else}
      <Tooltip content={__('btnProcessHint')} placement="bottom">
        <Button variant="secondary" size="sm" {disabled} onclick={() => onProcess()}>{__('btnProcess')}</Button>
      </Tooltip>
      {#if onProcessChanges && changes.count > 0}
        <Tooltip content={__('btnProcessChangesHint')} placement="bottom">
          <ConfirmButton
            variant="ghost"
            size="sm"
            {disabled}
            label={__f('btnProcessChanges', changes.count)}
            confirmLabel={__('btnProcessChangesConfirm')}
            onconfirm={() => onProcessChanges?.()}
          />
        </Tooltip>
      {/if}
      {#if changes.unlinked.length > 0}
        <Tooltip content={__('unlinkedTip')} placement="bottom">
          <Tag
            size="sm"
            tone="warning"
            variant="soft"
            label={changes.unlinked.length === 1 ? __f('unlinkedOne', changes.unlinked[0]) : __f('unlinkedMany', changes.unlinked.length)}
          />
        </Tooltip>
      {/if}
      {#if docsUrl && (changes.count > 0 || changes.unlinked.length > 0 || changes.sitewide.length > 0)}
        <a class="bs-disc__doc" href={`${docsUrl}#process-changes`} target="_blank" rel="noopener noreferrer">{__('processChangesDoc')}</a>
      {/if}
      {#if changes.sitewide.length > 0}
        <Tooltip content={__('sitewideTip')} placement="bottom">
          <Tag size="sm" tone="warning" variant="soft" label={__f('sitewideChanged', sitewideText)} />
        </Tooltip>
      {/if}
    {/if}
  {/if}
</div>

{#if section !== 'actions'}

<Modal bind:open={helpOpen} title={__('pagesToInclude')}>
  <div class="bs-help">
    <p>{__('discControls')}</p>
    <dl>
      <dt>{__('onlyLinkedPages')} <Tag size="sm" tone="primary" variant="solid" label={__('tagDefault')} /></dt>
      <!-- eslint-disable-next-line svelte/no-at-html-tags -->
      <dd>{@html __('discLinkedBody')}</dd>
      <dt>{__('allPublished')}</dt>
      <dd>{__('discAllBody')}</dd>
      <dt>{__('manualSelection')}</dt>
      <dd>{__('discManualBody')}</dd>
    </dl>
    <p class="bs-help__note">{__('discNote')}</p>
  </div>
</Modal>
{/if}

{#if section !== 'mode'}
<Modal bind:open={pagesOpen} title={__('pagesOverviewTitle')} wide>
  <div class="bs-pages">
    <Tabs
      bind:value={activeTab}
      items={[
        { value: 'included', label: `${__('tabIncluded')} (${included.length})` },
        { value: 'excluded', label: `${__('tabExcluded')} (${excluded.length})` },
      ]}
    >
      {#snippet panel(tab)}
        {#if loadingPages}
          <ListSkeleton rows={8} label={__('pagesLoading')} />
        {:else if pagesErr}
          <p class="bs-pages__msg bs-pages__msg--err">{pagesErr}</p>
        {:else if currentList.length === 0}
          <p class="bs-pages__msg">{tab === 'included' ? __('noIncludedPages') : __('noExcludedPages')}</p>
        {:else}
          <div class="bs-pages__scroll">
            {#snippet cell(row: Record<string, any>, col: { key: string })}
              {#if col.key === 'title'}
                <span class="bs-pcell__title">{row.title}</span>
              {:else if col.key === 'path'}
                <a class="bs-pcell__path" href={row.url} target="_blank" rel="noopener noreferrer">{pagePath(row.url)}</a>
              {:else if row.tags.length}
                <span class="bs-pcell__tags">
                  {#each row.tags as t (t)}
                    <Tag size="sm" tone={tab === 'included' ? 'warning' : 'neutral'} variant="soft" label={t} />
                  {/each}
                </span>
              {:else}
                <span class="bs-pcell__none">—</span>
              {/if}
            {/snippet}
            <Table {columns} rows={currentList} {cell} striped hover compact />
          </div>
        {/if}
      {/snippet}
    </Tabs>
  </div>
</Modal>
{/if}

<style>
  .bs-disc {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--ab-space-2) var(--ab-space-3);
  }

  .bs-disc__doc {
    font-size: var(--ab-text-sm);
  }

  .bs-disc__label {
    font-size: var(--ab-text-sm);
    font-weight: var(--ab-weight-medium);
    color: var(--ab-color-text-muted);
    white-space: nowrap;
  }

  /* Fill the wide modal body: tabs stay put, the list scrolls. */
  .bs-pages {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: 0;
    gap: var(--ab-space-4);
  }

  .bs-pages__msg {
    margin: 0;
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
  }

  .bs-pages__msg--err {
    color: var(--ab-color-danger);
  }

  /* Scroll region that fills the wide modal. */
  .bs-pages__scroll {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
  }

  .bs-pcell__title {
    color: var(--ab-color-text);
    font-weight: var(--ab-weight-medium);
  }

  .bs-pcell__path {
    color: var(--ab-color-text-muted);
    text-decoration: none;
    font-family: var(--ab-font-mono);
    font-size: var(--ab-text-xs);
    word-break: break-all;
  }

  .bs-pcell__path:hover {
    color: var(--ab-color-primary);
    text-decoration: underline;
  }

  .bs-pcell__tags {
    display: flex;
    flex-wrap: wrap;
    gap: var(--ab-space-1);
  }

  .bs-pcell__none {
    color: var(--ab-color-text-muted);
  }

  .bs-help p {
    margin: 0 0 var(--ab-space-4);
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
  }

  .bs-help dl {
    margin: 0 0 var(--ab-space-4);
  }

  .bs-help dt {
    display: flex;
    align-items: center;
    gap: var(--ab-space-2);
    font-size: var(--ab-text-sm);
    font-weight: var(--ab-weight-semibold);
    color: var(--ab-color-text);
    margin-top: var(--ab-space-3);
  }

  .bs-help dd {
    margin: var(--ab-space-1) 0 0;
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
  }

  .bs-help__note {
    margin: 0;
    font-size: var(--ab-text-xs);
    color: var(--ab-color-text-muted);
  }
</style>
