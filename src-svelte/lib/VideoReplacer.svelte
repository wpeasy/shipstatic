<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import { Button, Input, Tag } from '@wpeasy/ab-ui';
  import { api } from '../shared/api';
  import type { VideoItem, VideoReplacement, DestinationDisplay, ReplacementPage, ExcludedPage } from '../shared/types';
  import { __ } from '../shared/i18n';
  import ListSkeleton from './ListSkeleton.svelte';
  import PageSelect from './PageSelect.svelte';

  let { destination, onSaved }: { destination: DestinationDisplay; onSaved: () => void } = $props();

  // Full per-page replacement list = source of truth for persistence.
  let replacements = $state<VideoReplacement[]>(
    untrack(() => (destination.videoReplacements ?? []).map((r) => ({ ...r }))),
  );

  let pages = $state<ReplacementPage[]>([]);
  let excluded = $state<ExcludedPage[]>([]);
  let selectedPage = $state('');
  let videos = $state<VideoItem[]>([]);
  let loadingPages = $state(true);
  let loadingVideos = $state(false);
  let error = $state('');
  let search = $state('');

  onMount(async () => {
    try {
      const r = await api.getVideos('');
      pages = r.pages;
      excluded = r.excluded ?? [];
    } catch (e) {
      error = (e as Error).message;
    } finally {
      loadingPages = false;
    }
  });

  let loadedFor = '';
  $effect(() => {
    const page = selectedPage;
    if (page === '' || page === loadedFor) return;
    loadedFor = page;
    loadingVideos = true;
    error = '';
    api
      .getVideos(page)
      .then((r) => {
        videos = r.videos;
        untrack(() => pruneStaleForPage(page));
      })
      .catch((e) => (error = (e as Error).message))
      .finally(() => (loadingVideos = false));
  });

  function pruneStaleForPage(page: string): void {
    if (videos.length === 0) return;
    const present = new Set(videos.map((v) => v.url));
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

  const pageSwaps = $derived.by(() => {
    const map: Record<string, VideoReplacement> = {};
    for (const r of replacements) if (r.page === selectedPage) map[r.from] = r;
    return map;
  });

  let filtered = $derived.by(() => {
    const term = search.trim().toLowerCase();
    if (term === '') return videos;
    return videos.filter((v) => v.url.toLowerCase().includes(term) || v.title.toLowerCase().includes(term));
  });

  async function persist(): Promise<void> {
    try {
      await api.updateDestination(destination.id, { videoReplacements: replacements });
      onSaved();
    } catch (e) {
      error = (e as Error).message;
    }
  }

  function setSwap(from: string, to: string, toId: number): void {
    const next = replacements.filter((r) => !(r.page === selectedPage && r.from === from));
    if (to.trim() !== '') next.push({ page: selectedPage, from, to: to.trim(), toId });
    replacements = next;
    void persist();
  }

  // Local video → WP media library (videos only).
  function pick(item: VideoItem): void {
    const wp = window.wp;
    if (!wp?.media) {
      error = __('mediaUnavailable');
      return;
    }
    const frame = wp.media({ title: __('chooseReplacementVideo'), button: { text: __('useThisVideo') }, multiple: false, library: { type: 'video' } });
    frame.on('select', () => {
      const att = frame.state().get('selection').first().toJSON();
      if (att?.url) setSwap(item.url, att.url, att.id ?? 0);
    });
    frame.open();
  }

  // Embed → accept a full URL, a bare video ID, or a pasted <iframe> code, and
  // resolve to the right embed URL for the provider (matching how Bricks works).
  function commitEmbed(item: VideoItem, value: string): void {
    setSwap(item.url, resolveEmbed(item.provider, value), 0);
  }

  function resolveEmbed(provider: string, raw: string): string {
    const value = raw.trim();
    if (value === '') return '';

    if (provider === 'youtube') {
      const id = youtubeId(value);
      return id ? `https://www.youtube.com/embed/${id}` : value;
    }
    if (provider === 'vimeo') {
      const id = vimeoId(value);
      return id ? `https://player.vimeo.com/video/${id}` : value;
    }
    // Other embeds: a URL, or the src extracted from pasted <iframe> code.
    const m = value.match(/<iframe[^>]*\ssrc\s*=\s*["']([^"']+)["']/i);
    return m ? m[1] : value;
  }

  function youtubeId(v: string): string {
    if (/^[A-Za-z0-9_-]{6,15}$/.test(v)) return v;
    const m = v.match(/(?:youtube(?:-nocookie)?\.com\/embed\/|youtu\.be\/|[?&]v=)([A-Za-z0-9_-]{6,})/i);
    return m ? m[1] : '';
  }

  function vimeoId(v: string): string {
    if (/^\d+$/.test(v)) return v;
    const m = v.match(/vimeo\.com\/(?:video\/)?(\d+)/i);
    return m ? m[1] : '';
  }

  function isLocal(item: VideoItem): boolean {
    return item.provider === 'video';
  }

  function shortUrl(url: string): string {
    return url.replace(/^https?:\/\//, '').split('?')[0];
  }

  function basename(url: string): string {
    const path = url.split('?')[0];
    return path.substring(path.lastIndexOf('/') + 1) || url;
  }
</script>

<div class="bs-vids">
  <div class="bs-vids__head">
    <span>{__('videoReplacer')}</span>
  </div>
  <!-- eslint-disable-next-line svelte/no-at-html-tags -->
  <p class="bs-vids__hint">{@html __('videosHint')}</p>

  {#if loadingPages}
    <ListSkeleton rows={3} label={__('loadingVideos')} />
  {:else if pages.length === 0}
    <p class="bs-vids__note">{__('noRenderForMedia')}</p>
  {:else}
    {#if pagesWithSwaps.length > 0}
      <div class="bs-vids__pagebadges">
        <span class="bs-vids__pagebadges-label">{__('mediaPagesWithSwaps')}</span>
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

    <div class="bs-vids__picker">
      <PageSelect
        label={__('videoSelectPage')}
        placeholder={__('mediaSelectPagePh')}
        {pages}
        {excluded}
        value={selectedPage}
        onchange={(v) => (selectedPage = v)}
      />
    </div>

    {#if selectedPage === ''}
      <p class="bs-vids__note">{__('videoPickPageFirst')}</p>
    {:else if loadingVideos}
      <ListSkeleton rows={4} label={__('loadingVideos')} />
    {:else if error}
      <p class="bs-vids__note bs-vids__note--err">{error}</p>
    {:else if videos.length === 0}
      <p class="bs-vids__note">{__('noVideosOnPage')}</p>
    {:else}
      <div class="bs-vids__list">
        {#each filtered as item (item.url)}
          {@const swap = pageSwaps[item.url]}
          <div class="bs-vids__row" class:is-swapped={swap}>
            {#if item.thumb}
              <img class="bs-vids__thumb" src={item.thumb} alt="" loading="lazy" />
            {:else}
              <span class="bs-vids__thumb bs-vids__thumb--placeholder">▶</span>
            {/if}
            <div class="bs-vids__meta">
              <span class="bs-vids__title">{item.title || item.provider}</span>
              <span class="bs-vids__url" title={item.url}>{shortUrl(item.url)}</span>
              <span class="bs-vids__tag">{isLocal(item) ? __('localFile') : item.provider}</span>
            </div>

            {#if isLocal(item)}
              <div class="bs-vids__control">
                {#if swap}
                  <span class="bs-vids__swapped" title={swap.to}>↳ {basename(swap.to)}</span>
                  <Button variant="ghost" size="sm" onclick={() => setSwap(item.url, '', 0)}>{__('btnRemove')}</Button>
                {:else}
                  <Button variant="secondary" size="sm" onclick={() => pick(item)}>{__('btnReplace')}</Button>
                {/if}
              </div>
            {:else}
              <Input
                type="text"
                class="bs-vids__input"
                placeholder={__('phReplUrlOrId')}
                value={swap?.to ?? ''}
                onchange={(e: Event) => commitEmbed(item, (e.currentTarget as HTMLInputElement).value)}
                onblur={(e: FocusEvent) => commitEmbed(item, (e.currentTarget as HTMLInputElement).value)}
              />
            {/if}
          </div>
        {/each}
        {#if filtered.length === 0}
          <p class="bs-vids__note">{__('noVideosMatch')}</p>
        {/if}
      </div>
    {/if}
  {/if}
</div>

<style>
  .bs-vids {
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-3);
  }

  .bs-vids__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--ab-space-3);
    font-size: var(--ab-text-sm);
    font-weight: var(--ab-weight-medium);
    color: var(--ab-color-text-muted);
  }

  .bs-vids__hint {
    margin: 0;
    font-size: var(--ab-text-xs);
    color: var(--ab-color-text-muted);
  }

  .bs-vids__pagebadges {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--ab-space-2);
  }

  .bs-vids__pagebadges-label {
    font-size: var(--ab-text-xs);
    color: var(--ab-color-text-muted);
  }

  .bs-vids__picker {
    max-width: 22rem;
  }

  .bs-vids__list {
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-2);
    max-height: 32rem;
    overflow: auto;
  }

  .bs-vids__row {
    display: flex;
    align-items: center;
    gap: var(--ab-space-3);
    padding: var(--ab-space-2) var(--ab-space-3);
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-md);
    background: var(--ab-color-surface);
  }

  .bs-vids__row.is-swapped {
    border-color: var(--ab-color-primary);
  }

  .bs-vids__thumb {
    width: 4rem;
    height: 2.5rem;
    flex: 0 0 auto;
    object-fit: cover;
    border-radius: var(--ab-radius-sm);
    background: var(--ab-color-bg);
  }

  .bs-vids__thumb--placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ab-color-text-muted);
  }

  .bs-vids__meta {
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-1);
    min-width: 0;
    flex: 1;
  }

  .bs-vids__title {
    font-size: var(--ab-text-sm);
    font-weight: var(--ab-weight-medium);
    color: var(--ab-color-text);
    text-transform: capitalize;
  }

  .bs-vids__url {
    font-size: var(--ab-text-xs);
    color: var(--ab-color-text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .bs-vids__tag {
    font-size: var(--ab-text-xs);
    color: var(--ab-color-text-muted);
    text-transform: capitalize;
  }

  .bs-vids__control {
    display: flex;
    align-items: center;
    gap: var(--ab-space-2);
    flex: 0 0 auto;
  }

  .bs-vids__swapped {
    font-size: var(--ab-text-xs);
    color: var(--ab-color-primary);
    max-width: 10rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* Layout only — the field itself is styled by ab-ui. The class is passed to the
     ab-ui Input child, so it needs :global to match (it carries no scope). */
  :global(.bs-vids__input) {
    flex: 0 0 16rem;
    max-width: 48%;
  }

  .bs-vids__note {
    margin: 0;
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
  }

  .bs-vids__note--err {
    color: var(--ab-color-danger);
  }
</style>
