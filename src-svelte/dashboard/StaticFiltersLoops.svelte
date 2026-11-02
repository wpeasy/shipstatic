<script lang="ts">
  import { Badge } from '@wpeasy/ab-ui';
  import { __, __f } from '../shared/i18n';
  import type { StaticFiltersReport } from '../shared/types';

  // The query loops the last Process found and whether each runs without WordPress — shared by Settings and the Bricks guide.
  let { staticFilters }: { staticFilters: StaticFiltersReport } = $props();
</script>

{#if staticFilters.loops === 0}
  <p class="bs-help">{__('staticFiltersNeedsProcess')}</p>
{:else}
  <p class="bs-help"><strong>{__('staticFiltersLoops')}</strong></p>
  <ul class="bs-sfl">
    {#each staticFilters.list as loop (loop.page + loop.query)}
      <li>
        <code>{loop.page.replace(/\/index\.html$/, '/')}</code>
        <span class="bs-sfl__q">{loop.query}</span>
        {#if loop.static}
          <Badge tone="success" variant="soft">{__f('staticFiltersStatic', loop.items)}</Badge>
        {:else}
          <Badge tone="neutral" variant="soft">{__('staticFiltersRelay')}</Badge>
          <span class="bs-help">{loop.reason}</span>
        {/if}
      </li>
    {/each}
  </ul>
{/if}

<style>
  .bs-sfl {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
  }
  .bs-sfl li {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
  }
  .bs-sfl__q {
    opacity: 0.7;
  }
</style>
