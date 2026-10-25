<script lang="ts">
  import { Alert, Checkbox, Segmented, Select, Switch, ThemeCustomizer, Wizard } from '@wpeasy/ab-ui';
  import type { WizardStep } from '@wpeasy/ab-ui';
  import { Sun, Moon } from '@wpeasy/ab-ui/icons';
  import Modal from '../lib/Modal.svelte';
  import DiscoveryToggle from './DiscoveryToggle.svelte';
  import { uiPrefs, persistPrefs, type ColorScheme, type Accent } from '../shared/uiPrefs.svelte';
  import type { DiscoveryMode, Status } from '../shared/types';
  import { __ } from '../shared/i18n';

  let {
    open = $bindable(false),
    status,
    syncing = false,
    onSetDiscoveryMode,
    onSetFabEnabled,
    onFinish,
  }: {
    open: boolean;
    status: Status | null;
    syncing?: boolean;
    onSetDiscoveryMode: (mode: DiscoveryMode) => void;
    onSetFabEnabled: (enabled: boolean) => void;
    /** Closes the wizard; runs Process when `process` is true (parent owns `open`). */
    onFinish: (process: boolean) => void;
  } = $props();

  // Same accent list + handlers as the Style section in SettingsDrawer.svelte —
  // this step reuses that exact control, just without the Compact-mode switch
  // (a density preference, not part of "theme colour").
  const accentOptions = [
    { value: 'blue', label: __('accentBlue') },
    { value: 'green', label: __('accentGreen') },
    { value: 'yellow', label: __('accentYellow') },
    { value: 'purple', label: __('accentPurple') },
    { value: 'custom', label: __('accentCustom') },
  ];

  function setScheme(v: string): void {
    uiPrefs.colorScheme = v as ColorScheme;
    persistPrefs();
  }

  function setAccent(v: string): void {
    uiPrefs.accent = v as Accent;
    persistPrefs();
  }

  $effect(() => {
    void [uiPrefs.customPrimary, uiPrefs.customAccent, uiPrefs.distance, uiPrefs.duration];
    persistPrefs();
  });

  let wizardStep = $state(0);
  // Finish runs a full Process only when the person says so on the last step,
  // which explains what that does (tester report: it ran without warning).
  let processNow = $state(true);

  function handleComplete(): void {
    onFinish(processNow);
  }

  // Reset to step 0 each time the wizard is (re)opened, so a manual re-run from
  // Settings doesn't resume wherever the first run left off.
  $effect(() => {
    if (open) {
      wizardStep = 0;
      processNow = true;
    }
  });
</script>

{#snippet sunIcon()}<Sun />{/snippet}
{#snippet moonIcon()}<Moon />{/snippet}

{#snippet themeStep()}
  <div class="bs-wiz-step bs-stack bs-stack--sm">
    <Segmented
      label={__('colorScheme')}
      value={uiPrefs.colorScheme}
      onchange={setScheme}
      options={[
        { value: 'auto', label: __('csAuto') },
        { value: 'light', label: __('csLight'), icon: sunIcon },
        { value: 'dark', label: __('csDark'), icon: moonIcon },
      ]}
    />
    <div class="bs-wiz-step__theme">
      <Select
        label={__('themeAccent')}
        value={uiPrefs.accent}
        options={accentOptions}
        onchange={(v) => setAccent(v as string)}
      />
    </div>
    {#if uiPrefs.accent === 'custom'}
      <ThemeCustomizer
        io={false}
        bind:primary={uiPrefs.customPrimary}
        bind:accent={uiPrefs.customAccent}
        bind:distance={uiPrefs.distance}
        bind:duration={uiPrefs.duration}
      />
    {/if}
  </div>
{/snippet}

{#snippet pagesStep()}
  <div class="bs-wiz-step">
    {#if status}
      <DiscoveryToggle
        section="mode"
        mode={status.discoveryMode}
        disabled={syncing}
        renderCurrent={status.renderCurrent}
        excludedPublished={status.excludedPublished}
        onChange={onSetDiscoveryMode}
        onProcess={() => {}}
      />
    {/if}
  </div>
{/snippet}

{#snippet syncStep()}
  <div class="bs-wiz-step">
    {#if status}
      <Switch
        label={__('enableSyncButton')}
        checked={status.fabEnabled ? 1 : 0}
        onchange={(c) => onSetFabEnabled(c === 1)}
      />
    {/if}
  </div>
{/snippet}

{#snippet processStep()}
  <div class="bs-wiz-step bs-stack bs-stack--sm">
    <p>{__('wizardProcessHelp')}</p>
    <Alert tone="info" description={__('wizardProcessTime')} />
    <Checkbox label={__('wizardProcessNow')} checked={processNow ? 1 : 0} onchange={(c) => (processNow = c === 1)} />
  </div>
{/snippet}

<Modal bind:open title={__('wizardTitle')} size="lg">
  <div class="bs-wiz">
    <p class="bs-wiz__intro">{__('wizardIntro')}</p>
    <Wizard
      steps={[
        { label: __('styleGroup'), content: themeStep },
        { label: __('pagesToInclude'), content: pagesStep },
        { label: __('enableSyncButton'), content: syncStep },
        { label: __('wizardProcessStep'), content: processStep },
      ] as WizardStep[]}
      bind:current={wizardStep}
      orientation="vertical"
      backLabel={__('wizardBack')}
      nextLabel={__('wizardNext')}
      finishLabel={processNow ? __('wizardFinish') : __('wizardFinishOnly')}
      oncomplete={handleComplete}
    />
  </div>
</Modal>

<style>
  .bs-wiz {
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-4);
  }

  .bs-wiz__intro {
    margin: 0;
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
  }

  .bs-wiz-step {
    min-height: 6rem;
  }

  /* Constrain the accent Select so it doesn't span the whole modal. */
  .bs-wiz-step__theme {
    max-width: 320px;
  }
</style>
