<script lang="ts">
  import { Alert, Switch } from '@wpeasy/ab-ui';
  import CollapsibleNote from '../lib/CollapsibleNote.svelte';
  import StaticFiltersLoops from './StaticFiltersLoops.svelte';
  import { __ } from '../shared/i18n';
  import type { StaticFiltersReport } from '../shared/types';

  // Shared by Settings and the setup wizard. Only rendered when Bricks is active.
  let {
    relayEnabled,
    onSetRelayEnabled,
    relayWpImages,
    onSetRelayWpImages,
    staticFilters,
    onSetStaticFiltersEnabled,
  }: {
    relayEnabled: boolean;
    onSetRelayEnabled: (enabled: boolean) => void;
    relayWpImages: boolean;
    onSetRelayWpImages: (enabled: boolean) => void;
    staticFilters: StaticFiltersReport;
    onSetStaticFiltersEnabled: (enabled: boolean) => void;
  } = $props();
</script>

<div class="bs-stack bs-stack--sm">
  <Switch label={__('relayEnabled')} checked={relayEnabled ? 1 : 0} onchange={(c) => onSetRelayEnabled(c === 1)} />
  <p class="bs-help">{__('relayEnabledHelp')}</p>
  <Alert tone="info" description={__('relayFirewallNote')} />
  <CollapsibleNote tone="warning" title={__('experimental')}>
    <p class="bs-help">{__('relayExperimental')}</p>
  </CollapsibleNote>
  {#if relayEnabled}
    <Switch label={__('relayWpImages')} checked={relayWpImages ? 1 : 0} onchange={(c) => onSetRelayWpImages(c === 1)} />
    <p class="bs-help">{__('relayWpImagesHelp')}</p>
  {/if}
</div>

<div class="bs-stack bs-stack--sm bs-sf">
  <Switch label={__('staticFilters')} checked={staticFilters.enabled ? 1 : 0} onchange={(c) => onSetStaticFiltersEnabled(c === 1)} />
  <p class="bs-help">{__('staticFiltersHelp')}</p>
  {#if staticFilters.enabled}
    <CollapsibleNote tone="warning" title={__('experimental')}>
      <p class="bs-help">{__('staticFiltersCaveats')}</p>
    </CollapsibleNote>
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
