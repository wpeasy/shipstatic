<script lang="ts">
  import { Alert, Badge, Switch } from '@wpeasy/ab-ui';
  import { __, __f } from '../shared/i18n';
  import type { StaticFiltersReport } from '../shared/types';

  // Shared by Settings and the setup wizard. Only rendered when Bricks is active.
  let {
    relayEnabled,
    onSetRelayEnabled,
    staticFilters,
    onSetStaticFiltersEnabled,
  }: {
    relayEnabled: boolean;
    onSetRelayEnabled: (enabled: boolean) => void;
    staticFilters: StaticFiltersReport;
    onSetStaticFiltersEnabled: (enabled: boolean) => void;
  } = $props();
</script>

<div class="bs-stack bs-stack--sm">
  <Switch label={__('relayEnabled')} checked={relayEnabled ? 1 : 0} onchange={(c) => onSetRelayEnabled(c === 1)} />
  <p class="bs-help">{__('relayEnabledHelp')}</p>
  <Alert tone="info" description={__('relayFirewallNote')} />
  <Alert tone="warning" title={__('experimental')} description={__('relayExperimental')} />
</div>

<div class="bs-stack bs-stack--sm bs-sf">
  <Switch label={__('staticFilters')} checked={staticFilters.enabled ? 1 : 0} onchange={(c) => onSetStaticFiltersEnabled(c === 1)} />
  <p class="bs-help">{__('staticFiltersHelp')}</p>
  {#if staticFilters.enabled}
    <Alert tone="warning" title={__('experimental')} description={__('staticFiltersCaveats')} />
    {#if !staticFilters.verified}
      <Alert tone="danger" description={__('staticFiltersNotVerified')} />
    {/if}
    {#if staticFilters.loops === 0}
      <p class="bs-help">{__('staticFiltersNeedsProcess')}</p>
    {:else}
      <p class="bs-help"><strong>{__('staticFiltersLoops')}</strong></p>
      <ul class="bs-sf__list">
        {#each staticFilters.list as loop (loop.page + loop.query)}
          <li>
            <code>{loop.page.replace(/\/index\.html$/, '/')}</code>
            <span class="bs-sf__q">{loop.query}</span>
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
  {/if}
</div>

<style>
  .bs-sf {
    margin-top: 1rem;
  }
  .bs-sf__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
  }
  .bs-sf__list li {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
  }
  .bs-sf__q {
    opacity: 0.7;
  }
</style>
