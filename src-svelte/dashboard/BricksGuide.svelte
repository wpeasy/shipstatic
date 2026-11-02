<script lang="ts">
  import { untrack } from 'svelte';
  import { Alert, Checkbox, Segmented, Wizard } from '@wpeasy/ab-ui';
  import type { WizardStep } from '@wpeasy/ab-ui';
  import Modal from '../lib/Modal.svelte';
  import StaticFiltersLoops from './StaticFiltersLoops.svelte';
  import { recommend, type Answer, type Mode, type Where } from './bricksGuide';
  import type { Status } from '../shared/types';
  import { __, __f } from '../shared/i18n';

  // The Bricks guide: a few questions about forms and filters, then it applies the two Bricks settings
  // (Allow Bricks forms and filters / Run Bricks filters without WordPress). The mapping lives in bricksGuide.ts.
  let {
    open = $bindable(false),
    status,
    onSetRelayEnabled,
    onSetRelayWpImages,
    onSetStaticFiltersEnabled,
    onSeen,
    onFinish,
  }: {
    open: boolean;
    status: Status | null;
    onSetRelayEnabled: (enabled: boolean) => void;
    onSetRelayWpImages: (enabled: boolean) => void;
    onSetStaticFiltersEnabled: (enabled: boolean) => void;
    /** The guide has been completed: stop nudging. */
    onSeen: () => void;
    /** Close the guide; run Process when `process` is true (the parent owns `open`). */
    onFinish: (process: boolean) => void;
  } = $props();

  let step = $state(0);
  let mode = $state<Mode>('simple');
  let forms = $state<Answer>('unsure');
  let filters = $state<Answer>('unsure');
  let where = $state<Where>('static');
  let wpImages = $state(false);
  let processNow = $state(true);

  const info = $derived(status?.bricksGuide ?? null);
  // What the last Process learned about each loop: the connection to WordPress stays on while any is unknown or refused.
  const loops = $derived({
    known: (status?.staticFilters.loops ?? 0) > 0,
    fallback: (status?.staticFilters.list ?? []).filter((l) => !l.static).length,
  });
  const rec = $derived(info ? recommend({ mode, forms, filters, where, wpImages }, info, loops) : null);

  // Start fresh each time it opens, with the questions answered from what the last Process found. Only `open`
  // triggers this: reading the status inside would reset the answers every time it refreshes.
  $effect(() => {
    if (!open) return;
    untrack(() => {
      step = 0;
      mode = 'simple';
      where = 'static';
      wpImages = status?.relayWpImages ?? false;
      const f = info?.found;
      forms = f?.rendered ? (f.formPages > 0 ? 'yes' : 'no') : 'unsure';
      filters = f?.rendered ? (f.filterPages > 0 ? 'yes' : 'no') : 'unsure';
      processNow = true;
    });
  });

  // Running Process is what builds the data files, so it's what the person wants when filters run in the browser.
  $effect(() => {
    if (rec) processNow = rec.staticFilters;
  });

  const answerOptions = $derived([
    { value: 'yes', label: __('bricksGuideYes') },
    { value: 'no', label: __('bricksGuideNo') },
    { value: 'unsure', label: __('bricksGuideUnsure') },
  ]);

  // Simple goes straight to Apply; Advanced asks, and the "where" question only matters when there are filters.
  const steps = $derived([
    { label: __('bricksGuideStepFound'), content: foundStep },
    { label: __('bricksGuideStepSetup'), content: setupStep },
    ...(mode === 'advanced'
      ? [
          { label: __('bricksGuideStepForms'), content: formsStep },
          { label: __('bricksGuideStepFilters'), content: filtersStep },
          ...(rec?.hasFilters ? [{ label: __('bricksGuideStepWhere'), content: whereStep }] : []),
          ...(rec?.hasFilters && where === 'wordpress' ? [{ label: __('bricksGuideStepImages'), content: imagesStep }] : []),
        ]
      : []),
    { label: __('bricksGuideStepApply'), content: applyStep },
  ] as WizardStep[]);

  function handleComplete(): void {
    if (!status || !rec) return;
    if (rec.relay !== status.relayEnabled) onSetRelayEnabled(rec.relay);
    if (rec.wpImages !== status.relayWpImages) onSetRelayWpImages(rec.wpImages);
    if (rec.staticFilters !== status.staticFilters.enabled) onSetStaticFiltersEnabled(rec.staticFilters);
    onSeen();
    onFinish(processNow);
  }
</script>

{#snippet foundStep()}
  <div class="bs-bg-step bs-stack bs-stack--sm">
    <p>{__('bricksGuideIntro')}</p>
    {#if info?.found.rendered}
      <Alert tone="info" description={__f('bricksGuideFound', info.found.formPages, info.found.filterPages)} />
    {:else}
      <Alert tone="info" description={__('bricksGuideNotRendered')} />
    {/if}
    {#if info?.woo}
      <Alert tone="warning" description={__('bricksGuideWoo')} />
    {/if}
  </div>
{/snippet}

{#snippet setupStep()}
  <div class="bs-bg-step bs-stack bs-stack--sm">
    <h3 class="bs-bg-q">{__('bricksGuideSetupQ')}</h3>
    <Segmented
      value={mode}
      onchange={(v) => (mode = v as Mode)}
      options={[
        { value: 'simple', label: __('bricksGuideSimple') },
        { value: 'advanced', label: __('bricksGuideAdvanced') },
      ]}
    />
    {#if mode === 'simple'}
      <div class="bs-stack bs-stack--sm">
        <strong>{__('bricksGuideSimpleFor')}</strong>
        <ul class="bs-bg-list">
          {#each ['bricksGuideSimpleFor1', 'bricksGuideSimpleFor2', 'bricksGuideSimpleFor3'] as key (key)}
            <li>{__(key)}</li>
          {/each}
        </ul>
        <Alert tone="info" description={__('bricksGuideSimpleNote')} />
      </div>
    {:else}
      <p class="bs-help">{__('bricksGuideAdvancedHelp')}</p>
    {/if}
  </div>
{/snippet}

{#snippet formsStep()}
  <div class="bs-bg-step bs-stack bs-stack--sm">
    <h3 class="bs-bg-q">{__('bricksGuideFormsQ')}</h3>
    <Segmented value={forms} onchange={(v) => (forms = v as Answer)} options={answerOptions} />
    <p class="bs-help">{__('bricksGuideFormsHelp')}</p>
  </div>
{/snippet}

{#snippet filtersStep()}
  <div class="bs-bg-step bs-stack bs-stack--sm">
    <h3 class="bs-bg-q">{__('bricksGuideFiltersQ')}</h3>
    <Segmented value={filters} onchange={(v) => (filters = v as Answer)} options={answerOptions} />
    <p class="bs-help">{__('bricksGuideFiltersHelp')}</p>
  </div>
{/snippet}

{#snippet whereStep()}
  <div class="bs-bg-step bs-stack bs-stack--sm">
    <h3 class="bs-bg-q">{__('bricksGuideWhereQ')}</h3>
    <Segmented
      value={where}
      onchange={(v) => (where = v as Where)}
      options={[
        { value: 'static', label: __('bricksGuideWhereStatic') },
        { value: 'wordpress', label: __('bricksGuideWhereWp') },
      ]}
    />
    <div class="bs-bg-cols">
      <div>
        <strong>{__('bricksGuideProsStaticTitle')}</strong>
        <p class="bs-help">{__('bricksGuideProsStatic')}</p>
      </div>
      <div>
        <strong>{__('bricksGuideProsWpTitle')}</strong>
        <p class="bs-help">{__('bricksGuideProsWp')}</p>
      </div>
    </div>
  </div>
{/snippet}

{#snippet imagesStep()}
  <div class="bs-bg-step bs-stack bs-stack--sm">
    <h3 class="bs-bg-q">{__('bricksGuideImagesQ')}</h3>
    <Segmented
      value={wpImages ? 'wp' : 'synced'}
      onchange={(v) => (wpImages = v === 'wp')}
      options={[
        { value: 'synced', label: __('bricksGuideImagesSynced') },
        { value: 'wp', label: __('bricksGuideImagesWp') },
      ]}
    />
    <p class="bs-help">{__('bricksGuideImagesHelp')}</p>
  </div>
{/snippet}

{#snippet applyStep()}
  <div class="bs-bg-step bs-stack bs-stack--sm">
    {#if rec && status}
      {#if mode === 'simple'}
        <h3 class="bs-bg-q">{__('bricksGuideAssumeTitle')}</h3>
        <ul class="bs-bg-list">
          {#each ['bricksGuideAssume1', 'bricksGuideAssume2', 'bricksGuideAssume3', 'bricksGuideAssume4'] as key (key)}
            <li class="bs-help">{__(key)}</li>
          {/each}
        </ul>
      {/if}
      <h3 class="bs-bg-q">{__('bricksGuideSummary')}</h3>
      <ul class="bs-bg-set">
        <li><span>{__('relayEnabled')}</span> <strong>{rec.relay ? __('bricksGuideOn') : __('bricksGuideOff')}</strong></li>
        <li><span>{__('staticFilters')}</span> <strong>{rec.staticFilters ? __('bricksGuideOn') : __('bricksGuideOff')}</strong></li>
        {#if mode === 'advanced' && where === 'wordpress'}
          <li><span>{__('relayWpImages')}</span> <strong>{rec.wpImages ? __('bricksGuideOn') : __('bricksGuideOff')}</strong></li>
        {/if}
      </ul>
      {#each rec.reasons as reason (reason)}
        <p class="bs-help">{__(reason)}</p>
      {/each}
      {#if rec.staticFilters}
        <Checkbox label={__('bricksGuideProcessNow')} checked={processNow ? 1 : 0} onchange={(c) => (processNow = c === 1)} />
        <StaticFiltersLoops staticFilters={status.staticFilters} open />
      {/if}
      <p class="bs-help">{__('bricksGuideApplyNote')}</p>
    {/if}
  </div>
{/snippet}

<Modal bind:open title={__('bricksGuideTitle')} size="lg">
  <div class="bs-bg">
    <Wizard
      {steps}
      bind:current={step}
      orientation="vertical"
      backLabel={__('wizardBack')}
      nextLabel={__('wizardNext')}
      finishLabel={__('bricksGuideApply')}
      oncomplete={handleComplete}
    />
  </div>
</Modal>

<style>
  .bs-bg-step {
    min-height: 6rem;
    min-width: 0;
  }

  /* Long option labels wrap inside their button instead of pushing the dialog wider than the screen. */
  .bs-bg :global(.ab-segmented) {
    display: flex;
    flex-wrap: wrap;
    width: 100%;
    box-sizing: border-box;
  }

  .bs-bg :global(.ab-segmented__option) {
    flex: 1 1 12rem;
    min-width: 0;
    white-space: normal;
    text-align: center;
    line-height: 1.3;
  }

  .bs-bg-q {
    margin: 0;
    font-size: var(--ab-text-md, 1rem);
  }

  .bs-bg-cols {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--ab-space-4);
  }

  .bs-bg-list {
    margin: 0;
    padding-left: 1.2rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
  }

  .bs-bg-set {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
  }

  .bs-bg-set li {
    display: flex;
    gap: 0.5rem;
    align-items: baseline;
  }

  /* Same spacing and divider as the setup wizard (see SetupWizard.svelte). */
  .bs-bg :global(.ab-wizard--vertical) {
    column-gap: var(--ab-space-6);
  }

  .bs-bg :global(.ab-wizard--vertical .ab-steps) {
    gap: var(--ab-space-6);
    padding-right: var(--ab-space-6);
    border-right: 1px solid color-mix(in srgb, var(--ab-color-border) 60%, transparent);
  }
</style>
