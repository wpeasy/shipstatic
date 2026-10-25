<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import { Button, Tag, Tooltip } from '@wpeasy/ab-ui';
  import { api } from '../shared/api';
  import type { MediaItem, MediaReplacement, DestinationDisplay, ReplacementPage, ExcludedPage } from '../shared/types';
  import { __, __f } from '../shared/i18n';
  import ListSkeleton from '../lib/ListSkeleton.svelte';
  import PageSelect from '../lib/PageSelect.svelte';

  let { destination, onSaved }: { destination: DestinationDisplay; onSaved: () => void } = $props();

  // The full per-page replacement list is the source of truth for persistence.
  let replacements = $state<MediaReplacement[]>(
    untrack(() => (destination.mediaReplacements ?? []).map((r) => ({ ...r }))),
  );

  let pages = $state<ReplacementPage[]>([]);
  let excluded = $state<ExcludedPage[]>([]);
  let selectedPage = $state('');
  let media = $state<MediaItem[]>([]);
  let loadingPages = $state(true);
  let loadingMedia = $state(false);
  let error = $state('');

  onMount(async () => {
    try {
      const r = await api.getMedia('');
      pages = r.pages;
      excluded = r.excluded ?? [];
    } catch (e) {
      error = (e as Error).message;
    } finally {
      loadingPages = false;
    }
  });

  // Load the selected page's media whenever the selection changes.
  let loadedFor = '';
  $effect(() => {
    const page = selectedPage;
    if (page === '' || page === loadedFor) return;
    loadedFor = page;
    loadingMedia = true;
    error = '';
    api
      .getMedia(page)
      .then((r) => {
        media = r.media;
        untrack(() => pruneStaleForPage(page));
      })
      .catch((e) => (error = (e as Error).message))
      .finally(() => (loadingMedia = false));
  });

  // Drop saved swaps for THIS page whose original image is no longer on the page
  // (e.g. the element was removed). Only prunes when the page actually returned
  // media, so an empty result never wipes valid swaps.
  function pruneStaleForPage(page: string): void {
    if (media.length === 0) return;
    const present = new Set(media.map((m) => m.url));
    const before = replacements.length;
    replacements = replacements.filter((r) => r.page !== page || present.has(r.from));
    if (replacements.length !== before) void persist();
  }


  // Pages that already have at least one saved swap, with their count — lets the
  // user see AT A GLANCE which pages need attention and jump straight to one,
  // instead of a single aggregate count that says "something" but not "where".
  const pagesWithSwaps = $derived.by(() => {
    const counts: Record<string, number> = {};
    for (const r of replacements) counts[r.page] = (counts[r.page] ?? 0) + 1;
    return pages
      .filter((p) => (counts[p.value] ?? 0) > 0)
      .map((p) => ({ value: p.value, label: p.label, count: counts[p.value] }));
  });

  // The swaps saved for the currently selected page, keyed by original URL.
  const pageSwaps = $derived.by(() => {
    const map: Record<string, MediaReplacement> = {};
    for (const r of replacements) if (r.page === selectedPage) map[r.from] = r;
    return map;
  });

  function basename(url: string): string {
    const path = url.split('?')[0];
    return path.substring(path.lastIndexOf('/') + 1) || url;
  }

  async function persist(): Promise<void> {
    try {
      await api.updateDestination(destination.id, { mediaReplacements: replacements });
      onSaved();
    } catch (e) {
      error = (e as Error).message;
    }
  }

  function pick(item: MediaItem): void {
    const wp = window.wp;
    if (!wp?.media) {
      error = __('mediaUnavailable');
      return;
    }
    const frame = wp.media({
      title: __('chooseReplacement'),
      button: { text: __('useThisMedia') },
      multiple: false,
      library: { type: item.type },
    });
    frame.on('select', () => {
      const att = frame.state().get('selection').first().toJSON();
      if (att?.url) {
        const next = replacements.filter((r) => !(r.page === selectedPage && r.from === item.url));
        next.push({ page: selectedPage, from: item.url, to: att.url, toId: att.id ?? 0 });
        replacements = next;
        void persist();
      }
    });
    frame.open();
  }

  function clearSwap(from: string): void {
    replacements = replacements.filter((r) => !(r.page === selectedPage && r.from === from));
    void persist();
  }
</script>

<div class="bs-media">
  <div class="bs-media__head">
    <span>{__('mediaReplacer')}</span>
  </div>

  {#if loadingPages}
    <ListSkeleton rows={3} label={__('loadingMedia')} />
  {:else if pages.length === 0}
    <p class="bs-media__note">{__('noRenderForMedia')}</p>
  {:else}
    {#if pagesWithSwaps.length > 0}
      <div class="bs-media__pagebadges">
        <span class="bs-media__pagebadges-label">{__('mediaPagesWithSwaps')}</span>
        {#each pagesWithSwaps as p (p.value)}
          <Tag
            size="sm"
            tone="primary"
            variant={selectedPage === p.value ? 'solid' : 'soft'}
            label={p.count > 1 ? `${p.label} (${p.count})` : p.label}
            onclick={() => (selectedPage = p.value)}
          />
        {/each}
      </div>
    {/if}

    <div class="bs-media__picker">
      <PageSelect
        label={__('mediaSelectPage')}
        placeholder={__('mediaSelectPagePh')}
        {pages}
        {excluded}
        value={selectedPage}
        onchange={(v) => (selectedPage = v)}
      />
    </div>

    {#if selectedPage === ''}
      <p class="bs-media__note">{__('mediaPickPageFirst')}</p>
    {:else if loadingMedia}
      <ListSkeleton rows={4} label={__('loadingMedia')} />
    {:else if error}
      <p class="bs-media__note bs-media__note--err">{error}</p>
    {:else if media.length === 0}
      <p class="bs-media__note">{__('noMediaOnPage')}</p>
    {:else}
      <div class="bs-media__list">
        {#each media as item (item.url)}
          {@const swapped = pageSwaps[item.url]}
          <div class="bs-media__row" class:is-swapped={swapped}>
            <div class="bs-media__line">
              <button
                type="button"
                class="bs-media__thumbbtn"
                onclick={() => pick(item)}
                title={__('clickToReplace')}
              >
                <img class="bs-media__thumb" src={item.thumb} alt={item.alt} loading="lazy" />
              </button>
              <div class="bs-media__meta">
                <span class="bs-media__name" title={item.url}>
                  {basename(item.url)}
                  {#if (item.variants ?? 0) > 1}<small class="bs-media__sizes">{__f('nSizes', item.variants ?? 0)}</small>{/if}
                </span>
                <span class="bs-media__alt">{item.alt || __('noAlt')}</span>
                {#if item.inLibrary === false}
                  <Tooltip content={__('mediaNotInLibraryTip')} placement="top">
                    <span class="bs-media__warn">⚠ {__('mediaNotInLibrary')}</span>
                  </Tooltip>
                {/if}
              </div>
              <Button variant="secondary" size="sm" onclick={() => pick(item)}>{__('btnReplace')}</Button>
            </div>

            {#if swapped}
              <div class="bs-media__line bs-media__line--swap">
                <img class="bs-media__thumb" src={swapped.to} alt="" loading="lazy" />
                <div class="bs-media__meta">
                  <span class="bs-media__swaplabel">{__('replacedWith')}</span>
                  <span class="bs-media__name" title={swapped.to}>{basename(swapped.to)}</span>
                </div>
                <Button variant="ghost" size="sm" onclick={() => clearSwap(item.url)}>{__('btnRemove')}</Button>
              </div>
            {/if}
          </div>
        {/each}
      </div>
    {/if}
  {/if}
</div>

<style>
  .bs-media {
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-3);
  }

  .bs-media__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--ab-space-3);
    font-size: var(--ab-text-sm);
    font-weight: var(--ab-weight-medium);
    color: var(--ab-color-text-muted);
  }

  .bs-media__pagebadges {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--ab-space-2);
  }

  .bs-media__pagebadges-label {
    font-size: var(--ab-text-xs);
    color: var(--ab-color-text-muted);
  }

  .bs-media__picker {
    max-width: 22rem;
  }

  .bs-media__list {
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-2);
    max-height: 32rem;
    overflow: auto;
  }

  .bs-media__row {
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-md);
    background: var(--ab-color-surface);
  }

  .bs-media__row.is-swapped {
    border-color: var(--ab-color-primary);
  }

  .bs-media__line {
    display: flex;
    gap: var(--ab-space-3);
    align-items: center;
    padding: var(--ab-space-2);
  }

  .bs-media__line--swap {
    border-top: 1px dashed var(--ab-color-border);
    background: color-mix(in srgb, var(--ab-color-primary) 6%, transparent);
  }

  .bs-media__thumbbtn {
    border: 0;
    padding: 0;
    background: none;
    cursor: pointer;
    line-height: 0;
  }

  .bs-media__thumb {
    width: 3rem;
    height: 3rem;
    flex: 0 0 auto;
    object-fit: cover;
    border-radius: var(--ab-radius-sm);
    background: var(--ab-color-bg);
  }

  .bs-media__meta {
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-1);
    min-width: 0;
    flex: 1;
  }

  .bs-media__name {
    font-size: var(--ab-text-sm);
    font-weight: var(--ab-weight-medium);
    color: var(--ab-color-text);
    word-break: break-all;
  }

  .bs-media__alt {
    font-size: var(--ab-text-xs);
    color: var(--ab-color-text-muted);
  }

  .bs-media__sizes {
    margin-left: var(--ab-space-1);
    font-weight: var(--ab-weight-normal);
    color: var(--ab-color-text-muted);
  }

  .bs-media__warn {
    font-size: var(--ab-text-xs);
    color: var(--ab-color-warning);
    cursor: help;
  }

  .bs-media__swaplabel {
    font-size: var(--ab-text-xs);
    color: var(--ab-color-primary);
    font-weight: var(--ab-weight-medium);
  }

  .bs-media__note {
    margin: 0;
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
  }

  .bs-media__note--err {
    color: var(--ab-color-danger);
  }
</style>
