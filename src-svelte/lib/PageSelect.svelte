<script lang="ts">
  // Page picker for the per-page replacers (Images, Videos). A filterable
  // ab-ui Combobox rather than a plain Select: a site can export hundreds of
  // pages, so the list needs typing to narrow it.
  import { Combobox } from '@wpeasy/ab-ui';
  import type { ExcludedPage, ReplacementPage } from '../shared/types';
  import { __, __f } from '../shared/i18n';

  let {
    pages = [],
    excluded = [],
    value = '',
    label = '',
    placeholder = '',
    onchange,
  }: {
    pages?: ReplacementPage[];
    /** Published pages missing from the export: listed greyed out, not selectable. */
    excluded?: ExcludedPage[];
    value?: string;
    label?: string;
    placeholder?: string;
    onchange: (value: string) => void;
  } = $props();

  const options = $derived([
    ...pages.map((p) => ({ value: p.value, label: p.label })),
    ...excluded.map((p) => ({ value: `excluded:${p.label}`, label: __f('pageNotInExport', p.label), disabled: true })),
  ]);
</script>

<Combobox {label} {placeholder} {options} {value} onchange={(v) => onchange(Array.isArray(v) ? (v[0] ?? '') : v)} />
{#if excluded.length}
  <p class="bs-pagesel__hint">{__f('pagesNotInExportHint', excluded.length)}</p>
{/if}

<style>
  .bs-pagesel__hint {
    margin: var(--ab-space-1) 0 0;
    color: var(--ab-color-text-muted);
    font-size: var(--ab-text-sm);
  }
</style>
