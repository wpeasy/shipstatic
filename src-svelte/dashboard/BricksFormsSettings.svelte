<script lang="ts">
  import { Alert, Switch } from '@wpeasy/ab-ui';
  import { __ } from '../shared/i18n';

  // Shared by Settings and the setup wizard. Only rendered when Bricks is active.
  let {
    relayEnabled,
    onSetRelayEnabled,
  }: {
    relayEnabled: boolean;
    onSetRelayEnabled: (enabled: boolean) => void;
  } = $props();
</script>

<div class="bs-stack bs-stack--sm">
  <Switch label={__('relayEnabled')} checked={relayEnabled ? 1 : 0} onchange={(c) => onSetRelayEnabled(c === 1)} />
  <p class="bs-help">{__('relayEnabledHelp')}</p>
  <Alert tone="info" description={__('relayFirewallNote')} />
  <Alert tone="warning" title={__('experimental')} description={__('relayExperimental')} />
</div>
