<script lang="ts">
  import { Button, Drawer, Switch, ThemeCustomizer, Segmented, Select, Slider } from '@wpeasy/ab-ui';
  import { Sun, Moon } from '@wpeasy/ab-ui/icons';
  import DiscoveryToggle from './DiscoveryToggle.svelte';
  import AiToolsPanel from './AiToolsPanel.svelte';
  import SiteSearchSettings from './SiteSearchSettings.svelte';
  import BricksFormsSettings from './BricksFormsSettings.svelte';
  import { uiPrefs, persistPrefs, type ColorScheme, type Accent } from '../shared/uiPrefs.svelte';
  import type { DiscoveryMode, Status } from '../shared/types';
  import { __ } from '../shared/i18n';

  let {
    open = $bindable(false),
    status,
    syncing = false,
    onSetDiscoveryMode,
    onProcess,
    onSetFabEnabled,
    onSetSearchEnabled,
    onSetRelayEnabled,
    onSetAiToggles,
    onSetConcurrentSyncs,
    onOpenWizard,
  }: {
    open: boolean;
    status: Status | null;
    syncing?: boolean;
    onSetDiscoveryMode: (mode: DiscoveryMode) => void;
    onProcess: () => void;
    onSetFabEnabled: (enabled: boolean) => void;
    onSetSearchEnabled: (enabled: boolean) => void;
    onSetRelayEnabled: (enabled: boolean) => void;
    onSetAiToggles: (toggles: { aiAllowChanges?: boolean; aiAllowSync?: boolean }) => void;
    onSetConcurrentSyncs: (n: number) => void;
    /** Re-run the first-run setup wizard (closes this drawer). */
    onOpenWizard: () => void;
  } = $props();

  function setScheme(v: string): void {
    uiPrefs.colorScheme = v as ColorScheme;
    persistPrefs();
  }

  function setCompact(v: boolean): void {
    uiPrefs.compact = v;
    persistPrefs();
  }

  function setAccent(v: string): void {
    uiPrefs.accent = v as Accent;
    persistPrefs();
  }

  const accentOptions = [
    { value: 'blue', label: __('accentBlue') },
    { value: 'green', label: __('accentGreen') },
    { value: 'yellow', label: __('accentYellow') },
    { value: 'purple', label: __('accentPurple') },
    { value: 'custom', label: __('accentCustom') },
  ];

  // ThemeCustomizer (Custom preset only) binds straight to the store; persist
  // whenever a seed changes.
  $effect(() => {
    void [uiPrefs.customPrimary, uiPrefs.customAccent, uiPrefs.distance, uiPrefs.duration];
    persistPrefs();
  });
</script>

{#snippet sunIcon()}<Sun />{/snippet}
{#snippet moonIcon()}<Moon />{/snippet}

{#snippet drawerTitle()}
  <span class="bs-set__titlerow">
    {__('settings')}
    <Button variant="ghost" size="sm" onclick={onOpenWizard}>{__('wizardBtn')}</Button>
  </span>
{/snippet}

<!-- portal={false}: stay inside App's .ab-ui[data-theme][data-accent] root so the
     drawer inherits tokens + the live colour scheme/accent. -->
<Drawer bind:open side="right" title={drawerTitle} size={640} portal={false}>
  <div class="bs-set bs-stack bs-stack--lg">
    <!-- Style (colour scheme + accent theme) -->
    <section class="bs-set__group bs-stack bs-stack--sm">
      <h3 class="bs-set__h">{__('styleGroup')}</h3>
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
      <div class="bs-set__theme">
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
      <Switch label={__('compactMode')} checked={uiPrefs.compact ? 1 : 0} onchange={(c) => setCompact(c === 1)} />
    </section>

    {#if status}
      <!-- Pages to include (discovery mode only — the actions live on the toolbar) -->
      <section class="bs-set__group bs-stack bs-stack--sm">
        <DiscoveryToggle
          section="mode"
          mode={status.discoveryMode}
          disabled={syncing}
          renderCurrent={status.renderCurrent}
          excludedPublished={status.excludedPublished}
          onChange={onSetDiscoveryMode}
          onProcess={onProcess}
        />
        <p class="bs-help">{__('pagesToIncludeHelp')}</p>
      </section>

      <!-- Enable sync single button (FAB) -->
      <section class="bs-set__group bs-stack bs-stack--sm">
        <Switch
          label={__('enableSyncButton')}
          checked={status.fabEnabled ? 1 : 0}
          onchange={(c) => onSetFabEnabled(c === 1)}
        />
        <p class="bs-help">{__('enableSyncButtonHelp')}</p>
      </section>

      <!-- Site search (index + script in the export) -->
      <section class="bs-set__group">
        <SiteSearchSettings
          searchEnabled={status.searchEnabled}
          {onSetSearchEnabled}
        />
      </section>

      <!-- Bricks forms and filters through to WordPress (experimental; Bricks only) -->
      {#if status.bricksActive}
        <section class="bs-set__group">
          <BricksFormsSettings relayEnabled={status.relayEnabled} {onSetRelayEnabled} />
        </section>
      {/if}

      <!-- Sync (concurrent destination uploads) -->
      <section class="bs-set__group bs-stack bs-stack--sm">
        <h3 class="bs-set__h">{__('syncGroup')}</h3>
        <Slider
          label={__('concurrentSyncs')}
          min={1}
          max={10}
          step={1}
          showValue
          value={status.concurrentSyncs}
          onchange={(e) => onSetConcurrentSyncs(Number((e.currentTarget as HTMLInputElement).value))}
        />
        <p class="bs-help">{__('concurrentSyncsHelp')}</p>
      </section>

      <!-- AI abilities -->
      <section class="bs-set__group">
        <AiToolsPanel
          available={status.aiAvailable}
          allowChanges={status.aiAllowChanges}
          allowSync={status.aiAllowSync}
          onChange={onSetAiToggles}
        />
      </section>
    {/if}
  </div>
</Drawer>

<style>
  .bs-set {
    padding: var(--ab-space-4);
  }

  /* Visual separation between setting groups. */
  .bs-set__group + .bs-set__group {
    border-top: 1px solid var(--ab-color-border);
    padding-top: var(--ab-space-4);
  }

  /* Sits inside the Drawer's own <h2>; the button carries its own (normal)
     weight so it doesn't inherit the heading's semibold. */
  .bs-set__titlerow {
    display: inline-flex;
    align-items: center;
    gap: var(--ab-space-3);
  }

  .bs-set__h {
    margin: 0;
    font-size: var(--ab-text-md);
    font-weight: var(--ab-weight-semibold);
  }

  /* Constrain the Theme preset select so it doesn't span the whole drawer. */
  .bs-set__theme {
    max-width: 320px;
  }

</style>
