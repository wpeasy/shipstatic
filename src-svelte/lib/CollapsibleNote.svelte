<script lang="ts">
  import type { Snippet } from 'svelte';
  import { Collapsible } from '@wpeasy/ab-ui';

  // A note that takes one line until opened: for the long "Experimental" warnings and lists in Settings.
  let {
    title,
    tone = 'neutral',
    open = false,
    children,
  }: {
    title: string;
    /** `warning` tints the left edge and the title. */
    tone?: 'neutral' | 'warning';
    open?: boolean;
    children: Snippet;
  } = $props();

  // Local state, seeded from `open`: the parent only chooses the starting state.
  // svelte-ignore state_referenced_locally
  let isOpen = $state(open);
</script>

<div class="bs-cn bs-cn--{tone}">
  <Collapsible {title} bind:open={isOpen}>
    <div class="bs-cn__body bs-stack bs-stack--sm">
      {@render children()}
    </div>
  </Collapsible>
</div>

<style>
  .bs-cn {
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-md, 6px);
    padding: 0 0.75rem;
  }

  .bs-cn--warning {
    border-left: 3px solid var(--ab-color-warning);
  }

  .bs-cn :global(.ab-collapsible__trigger) {
    padding-block: 0.4rem;
    font-size: 0.875em;
  }

  .bs-cn--warning :global(.ab-collapsible__title) {
    color: var(--ab-color-warning);
    font-weight: 600;
  }

  .bs-cn__body {
    padding-bottom: 0.6rem;
  }
</style>
