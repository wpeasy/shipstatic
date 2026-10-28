<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import SyncPanel from '../lib/SyncPanel.svelte';
  import { setInclude } from '../shared/linkCheck';

  // Wires the server-rendered "Static" column cells (.bs-static-cell buttons) to
  // one shared sync modal, and repaints a cell after its include/sync changes.
  // In manual mode it also drives each row's one-click include icon
  // (.bs-static-inc) and the Quick Edit "Include in static export" checkbox.
  // Clicks are delegated: Quick Edit redraws its row after saving.
  let {
    restUrl,
    nonce,
    manualMode = false,
    fabEnabled = true,
    includedCount = 0,
    labels = {},
  }: {
    restUrl: string;
    nonce: string;
    manualMode?: boolean;
    fabEnabled?: boolean;
    includedCount?: number;
    labels?: { included?: string; excluded?: string; failed?: string };
  } = $props();

  let open = $state(false);
  let count = $state(untrack(() => includedCount));
  let ctx = $state<{ postId: number; pageUrl: string; included: boolean }>({ postId: 0, pageUrl: '', included: false });
  let activeBtn: HTMLButtonElement | null = null;

  function paint(btn: HTMLButtonElement, state: string, synced: boolean): void {
    btn.dataset.state = state;
    btn.dataset.synced = synced ? '1' : '0';
    if (state === 'excluded') {
      btn.innerHTML = '<span class="bs-static-x" aria-hidden="true">✕</span>';
    } else if (state === 'unknown') {
      btn.innerHTML = '<span class="bs-static-q" aria-hidden="true">?</span>';
    } else {
      btn.innerHTML =
        '<span class="bs-static-dot bs-static-dot--' + (synced ? 'ok' : 'warn') + '" aria-hidden="true"></span>' +
        '<span class="bs-static-ico" aria-hidden="true">⟳</span>';
    }
  }

  function cellFor(postId: number): HTMLButtonElement | null {
    return document.querySelector<HTMLButtonElement>(`.bs-static-cell[data-post="${postId}"]`);
  }

  function paintToggle(postId: number, included: boolean): void {
    const toggle = document.querySelector<HTMLButtonElement>(`.bs-static-inc[data-post="${postId}"]`);
    if (!toggle) return;
    toggle.setAttribute('aria-pressed', included ? 'true' : 'false');
    toggle.setAttribute('aria-label', (included ? labels.included : labels.excluded) ?? '');
  }

  // A freshly included page is "out of date" until it's synced.
  function setIncluded(postId: number, included: boolean): void {
    paintToggle(postId, included);
    const btn = cellFor(postId);
    if (btn) {
      btn.dataset.included = included ? '1' : '0';
      paint(btn, included ? 'included' : 'excluded', false);
    }
  }

  async function toggleInclude(toggle: HTMLButtonElement): Promise<void> {
    const postId = Number(toggle.dataset.post) || 0;
    if (!postId || toggle.dataset.busy === '1') return;
    const next = toggle.getAttribute('aria-pressed') !== 'true';
    toggle.dataset.busy = '1';
    setIncluded(postId, next); // optimistic
    try {
      const r = await setInclude(restUrl, nonce, postId, next);
      setIncluded(postId, r.included);
      if (typeof r.includedCount === 'number') count = r.includedCount;
    } catch {
      setIncluded(postId, !next); // revert
      toggle.setAttribute('aria-label', labels.failed ?? '');
    } finally {
      delete toggle.dataset.busy;
    }
  }

  // Quick Edit opens with one shared form: tick its checkbox from the row.
  function fillQuickEdit(postId: number): void {
    const box = document.querySelector<HTMLInputElement>(`#edit-${postId} input[name="shipstatic_include"]`);
    if (box) box.checked = cellFor(postId)?.dataset.included === '1';
  }

  function openFor(btn: HTMLButtonElement): void {
    activeBtn = btn;
    ctx = {
      postId: Number(btn.dataset.post) || 0,
      pageUrl: btn.dataset.url || '',
      included: btn.dataset.included === '1',
    };
    open = true;
  }

  function onIncludeChange(included: boolean, newCount: number): void {
    count = newCount;
    if (!activeBtn) return;
    activeBtn.dataset.included = included ? '1' : '0';
    // In manual mode the include switch directly flips the cell; a freshly
    // included page is "out of date" until it's synced.
    if (manualMode) setIncluded(Number(activeBtn.dataset.post) || 0, included);
  }

  function onSynced(): void {
    if (activeBtn) paint(activeBtn, 'included', true);
  }

  // Repaint the cells of pages just bulk-included via the "Include all" cascade
  // (newly included → "out of date" amber until they're synced).
  function onBulkIncluded(postIds: number[]): void {
    for (const id of postIds) {
      setIncluded(id, true);
    }
  }

  onMount(() => {
    const onClick = (e: MouseEvent): void => {
      const target = e.target as Element | null;
      const toggle = target?.closest<HTMLButtonElement>('.bs-static-inc');
      if (toggle) {
        void toggleInclude(toggle);
        return;
      }
      const cell = target?.closest<HTMLButtonElement>('.bs-static-cell');
      if (cell) {
        openFor(cell);
        return;
      }
      // Quick Edit link: core builds the form on this click, so fill it after.
      const quick = target?.closest<HTMLElement>('button.editinline');
      const row = quick?.closest<HTMLElement>('tr[id^="post-"]');
      if (row) {
        const postId = Number(row.id.slice(5)) || 0;
        setTimeout(() => fillQuickEdit(postId), 0);
      }
    };
    document.addEventListener('click', onClick);
    return () => document.removeEventListener('click', onClick);
  });
</script>

<SyncPanel
  bind:open
  {restUrl}
  {nonce}
  postId={ctx.postId}
  pageUrl={ctx.pageUrl}
  {manualMode}
  included={ctx.included}
  includedCount={count}
  syncEnabled={fabEnabled}
  {onIncludeChange}
  {onBulkIncluded}
  {onSynced}
/>

<style>
  /* The cells are server-rendered into the WP list table (outside this
     component), so style them globally. Hardcoded colours read on the admin's
     light + dark schemes. */
  :global(.bs-static-cell) {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 2px 7px;
    border: 1px solid transparent;
    border-radius: 6px;
    background: none;
    cursor: pointer;
    line-height: 1;
  }
  :global(.bs-static-cell:hover) {
    background: rgba(127, 127, 127, 0.14);
  }
  :global(.bs-static-dot) {
    display: inline-block;
    width: 9px;
    height: 9px;
    border-radius: 999px;
    background: #cbd5e1;
  }
  :global(.bs-static-dot--ok) {
    background: #16a34a;
  }
  :global(.bs-static-dot--warn) {
    background: #f59e0b;
  }
  :global(.bs-static-ico) {
    font-size: 13px;
    line-height: 1;
    color: #2563eb;
  }
  :global(.bs-static-x) {
    font-size: 12px;
    color: #9aa0a6;
  }
  :global(.bs-static-q) {
    font-weight: 700;
    color: #9aa0a6;
  }
  :global(.bs-static-wrap) {
    display: inline-flex;
    align-items: center;
    gap: 2px;
  }
  /* Manual mode: the check icon says "not included", so no ✕ beside it. */
  :global(.bs-static-wrap .bs-static-cell[data-state='excluded']) {
    display: none;
  }
  :global(.bs-static-inc) {
    display: inline-flex;
    align-items: center;
    padding: 2px;
    border: 0;
    border-radius: 6px;
    background: none;
    cursor: pointer;
    color: #a7aaad;
    line-height: 1;
  }
  :global(.bs-static-inc:hover) {
    background: rgba(127, 127, 127, 0.14);
    color: #787c82;
  }
  :global(.bs-static-inc[aria-pressed='true']),
  :global(.bs-static-inc[aria-pressed='true']:hover) {
    color: #16a34a;
  }
  :global(.bs-static-inc[data-busy='1']) {
    opacity: 0.5;
    cursor: progress;
  }
  :global(.bs-static-na) {
    color: #9aa0a6;
  }
</style>
