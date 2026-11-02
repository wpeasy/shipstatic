<script lang="ts">
  import { Alert, Switch } from '@wpeasy/ab-ui';
  import StaticFiltersLoops from './StaticFiltersLoops.svelte';
  import { __ } from '../shared/i18n';
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
    <StaticFiltersLoops {staticFilters} />
  {/if}
</div>

<style>
  .bs-sf {
    margin-top: 1rem;
  }
</style>
