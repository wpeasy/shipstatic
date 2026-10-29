<script lang="ts">
  import { Switch } from '@wpeasy/ab-ui';
  import { __ } from '../shared/i18n';

  // Shared by Settings and the setup wizard.
  let {
    searchEnabled,
    onSetSearchEnabled,
  }: {
    searchEnabled: boolean;
    onSetSearchEnabled: (enabled: boolean) => void;
  } = $props();
</script>

<div class="bs-stack bs-stack--sm">
  <Switch label={__('siteSearch')} checked={searchEnabled ? 1 : 0} onchange={(c) => onSetSearchEnabled(c === 1)} />
  <p class="bs-help">{__('siteSearchHelp')}</p>
</div>
