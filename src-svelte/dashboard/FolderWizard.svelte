<script lang="ts">
  import { untrack } from 'svelte';
  import { Alert, Button, Checkbox, CopyButton, Input, RadioGroup, Wizard } from '@wpeasy/ab-ui';
  import type { WizardStep } from '@wpeasy/ab-ui';
  import Modal from '../lib/Modal.svelte';
  import ListSkeleton from '../lib/ListSkeleton.svelte';
  import { api } from '../shared/api';
  import type { FolderCheck, FolderInfo } from '../shared/types';
  import { __, __f } from '../shared/i18n';

  // "This server (folder)" setup (docs/SERVER_FOLDER_PLAN.md): where → check →
  // create & test → done. Configures the destination it was opened from.
  let {
    open = $bindable(false),
    destId,
    destUrl = '',
    onDone,
  }: {
    open: boolean;
    destId: string;
    /** The destination's current URL (pre-fills a custom folder's address). */
    destUrl?: string;
    /** The destination changed (with the address it is served at): reload it. */
    onDone: (url: string) => void;
  } = $props();

  const CUSTOM = 'custom';

  let step = $state(0);
  let check = $state<FolderCheck | null>(null);
  let loadError = $state('');
  let choice = $state('');
  let customPath = $state('');
  let url = $state('');
  let info = $state<FolderInfo | null>(null);
  let checking = $state(false);
  let existing = $state<'adopt' | 'replace'>('adopt');
  let creating = $state(false);
  let created = $state(false);
  let result = $state('');
  let resultOk = $state(true);
  let warnings = $state<string[]>([]);

  // WordPress is at the root of its domain: the only way to publish there is
  // to move WordPress into /wp first (Deploy\WordPressMove).
  let backedUp = $state(false);
  let moving = $state(false);
  let moveMessage = $state('');
  let moveOk = $state(true);
  let moveProblems = $state<string[]>([]);
  let rechecking = $state(false);
  // …or keep WordPress where it is and publish to another domain whose folder
  // sits beside it (the wizard's ordinary steps).
  let otherDomain = $state(false);

  function hostOf(u: string): string {
    try {
      return new URL(u).hostname.toLowerCase();
    } catch {
      return '';
    }
  }

  // Run the move's pre-flight again (after adding the nginx rule, say).
  async function recheck(): Promise<void> {
    rechecking = true;
    try {
      check = await api.serverFolderCheck({ id: destId, url: destUrl });
    } catch (e) {
      loadError = (e as Error).message;
    }
    rechecking = false;
  }

  async function moveWordPress(): Promise<void> {
    moving = true;
    moveMessage = '';
    moveProblems = [];
    try {
      const r = await api.moveWordPress(destId);
      moveMessage = r.message;
      moveOk = r.ok;
      moveProblems = r.problems ?? [];
      if (r.ok && r.redirect) {
        const to = r.redirect;
        // Give the server a moment to move the files after its reply.
        setTimeout(() => (window.location.href = to), 3000);
        return;
      }
    } catch (e) {
      moveMessage = (e as Error).message;
      moveOk = false;
    }
    moving = false;
  }

  let suggestion = $derived(check?.suggestions.find((s) => s.path === choice) ?? null);
  let path = $derived(choice === CUSTOM ? customPath.trim() : choice);
  let domainRoot = $derived((info?.layout ?? suggestion?.layout) === 'domainRoot');

  let options = $derived([
    ...(check?.suggestions ?? []).map((s) => ({
      value: s.path,
      label: s.layout === 'domainRoot' ? __f('fwOptDomainRoot', s.url) : __f('fwOptSibling', s.path),
    })),
    { value: CUSTOM, label: __('fwOptCustom') },
  ]);

  // Fresh start each time it opens — only then: setup saves the address
  // (onDone), which changes destUrl, and re-running on that jumped the wizard
  // back to its first step instead of showing the result.
  $effect(() => {
    if (!open) return;
    untrack(reset);
  });

  function reset(): void {
    step = 0;
    check = null;
    loadError = '';
    info = null;
    created = false;
    backedUp = false;
    moving = false;
    moveMessage = '';
    moveProblems = [];
    result = '';
    warnings = [];
    customPath = '';
    url = destUrl;
    otherDomain = false;
    api
      .serverFolderCheck({ id: destId, url: destUrl })
      .then((c) => {
        check = c;
        const first = c.suggestions[0];
        choice = first ? first.path : CUSTOM;
        if (first?.url) url = first.url;
        // A Destination URL on another domain already says which layout.
        const host = hostOf(destUrl);
        otherDomain = !!c.move && host !== '' && host !== hostOf(c.move.from);
      })
      .catch((e) => (loadError = (e as Error).message));
  }

  function pick(): void {
    if (suggestion?.url) url = suggestion.url;
  }

  async function runCheck(): Promise<void> {
    checking = true;
    info = null;
    try {
      info = (await api.serverFolderCheck({ id: destId, path })).folder;
      existing = 'adopt';
    } catch (e) {
      info = { ok: false, error: (e as Error).message } as FolderInfo;
    } finally {
      checking = false;
    }
  }

  async function create(): Promise<void> {
    creating = true;
    result = '';
    warnings = [];
    try {
      const r = await api.setupFolder(destId, {
        path,
        url: url.trim(),
        adopt: !!info?.hasIndex && existing === 'adopt',
        replaceIndex: !!info?.hasIndex && existing === 'replace',
      });
      result = r.message;
      resultOk = r.ok;
      warnings = r.warnings ?? [];
      if (r.ok) {
        created = true;
        onDone(url.trim());
      }
    } catch (e) {
      result = (e as Error).message;
      resultOk = false;
    } finally {
      creating = false;
    }
  }

  function onStep(index: number): void {
    if (index === 1) void runCheck();
  }
</script>

{#snippet whereStep()}
  <div class="bs-wiz-step bs-stack bs-stack--sm">
    {#if loadError}
      <Alert tone="danger" description={loadError} />
    {:else if !check}
      <ListSkeleton rows={3} />
    {:else}
      <p>{__('fwWhereHelp')}</p>
      <div onchange={pick} role="presentation">
        <RadioGroup options={options} bind:value={choice} />
      </div>
      {#if choice === CUSTOM}
        <Input label={__('fwPath')} bind:value={customPath} placeholder={check.account + '/public_html'} hint={__f('fwPathHint', check.wordpress)} />
      {/if}
      <Input label={__('fldDestUrl')} type="url" bind:value={url} placeholder="https://www.example.com/" hint={__('fwUrlHint')} />
      {#if suggestion?.layout === 'domainRoot'}
        <Alert tone="info" description={__('fwOneOrigin')} />
        {#if check.server === 'nginx'}
          <Alert tone="warning" description={__('fwNginxRoot')} />
        {/if}
      {:else if choice === CUSTOM || suggestion?.layout === 'separate'}
        <p class="bs-wiz__muted">{__('fwSeparateHelp')}</p>
      {/if}
    {/if}
  </div>
{/snippet}

{#snippet checkStep()}
  <div class="bs-wiz-step bs-stack bs-stack--sm">
    {#if checking || !info}
      <ListSkeleton rows={2} />
    {:else if !info.ok}
      <Alert tone="danger" description={info.error} />
    {:else}
      <p><code>{info.path}</code></p>
      <ul class="bs-steps">
        <li>{info.exists ? (info.empty ? __('fwEmpty') : __('fwHasFiles')) : __('fwWillCreate')}</li>
        {#if info.protected.length}
          <li>{__f('fwProtected', info.protected.map((p) => p + '/').join(', '))}</li>
        {/if}
        <li>{__('fwNoPhp')}</li>
      </ul>
      {#if info.hasIndex}
        <RadioGroup
          label={__('fwIndexQuestion')}
          options={[
            { value: 'adopt', label: __('fwAdopt') },
            { value: 'replace', label: __('fwReplace') },
          ]}
          bind:value={existing}
        />
      {/if}
      {#if domainRoot}
        <Alert tone="info" description={__('fwLockdownNote')} />
      {/if}
    {/if}
  </div>
{/snippet}

{#snippet createStep()}
  <div class="bs-wiz-step bs-stack bs-stack--sm">
    <p>{__('fwCreateHelp')}</p>
    <div class="bs-row">
      <Button variant="primary" onclick={create} disabled={creating || created}>{creating ? __('fwCreating') : created ? __('fwCreated') : __('fwCreate')}</Button>
    </div>
    {#if result}
      <Alert tone={resultOk ? 'success' : 'danger'} description={result} />
    {/if}
    {#each warnings as w (w)}
      <Alert tone="warning" description={w} />
    {/each}
  </div>
{/snippet}

{#snippet doneStep()}
  <div class="bs-wiz-step bs-stack bs-stack--sm">
    <p>{__('fwDoneHelp')}</p>
    <ul class="bs-steps">
      <li>{__('fwDoneSync')}</li>
      <li>{__('fwDoneLockdown')}</li>
    </ul>
  </div>
{/snippet}

{#snippet moveFlow(m: NonNullable<FolderCheck['move']>)}
  <div class="bs-stack bs-stack--sm">
    <p>{__f('wmLead', m.from, m.to)}</p>
    <ol class="bs-steps">
      <li>{__f('wmStep1', m.to)}</li>
      <li>{__f('wmStep2', m.from)}</li>
      <li>{__('wmStep3')}</li>
      <li>{__('wmStep4')}</li>
    </ol>
    <Alert tone="info" description={__('fwOneOrigin')} />
    {#each m.problems as p (p)}
      <Alert tone="danger" description={p} />
    {/each}
    {#each m.warnings as w (w)}
      <Alert tone="warning" description={w} />
    {/each}
    {#if m.nginx}
      <div class="bs-stack bs-stack--sm">
        <h4 class="bs-wiz__h">{__('wmNginxHeading')}</h4>
        {#if m.nginx.ok}
          <Alert tone="success" description={__('wmNginxOk')} />
        {:else}
          <p class="bs-wiz__muted">{__('wmNginxHelp')}</p>
          <pre class="bs-wiz__pre">{m.nginx.rule}</pre>
          <div class="bs-row">
            <CopyButton text={m.nginx.rule} variant="button" label={__('ldCopy')} copiedLabel={__('ldCopied')} />
            <Button variant="secondary" size="sm" onclick={recheck} disabled={rechecking}>{rechecking ? __('ldChecking') : __('ldCheckAgain')}</Button>
          </div>
          {#if m.nginx.detail}
            <p class="bs-wiz__muted">{__f('wmNginxDetail', m.nginx.detail)}</p>
          {/if}
        {/if}
      </div>
    {/if}
    {#if m.ok}
      <Checkbox label={__('wmBackup')} checked={backedUp ? 1 : 0} onchange={(c) => (backedUp = c === 1)} />
      <div class="bs-row">
        <Button variant="primary" onclick={moveWordPress} disabled={!backedUp || moving}>{moving ? __('wmMoving') : __('wmMove')}</Button>
      </div>
    {/if}
    {#if moveMessage}
      <Alert tone={moveOk ? 'success' : 'danger'} description={moveMessage} />
    {/if}
    {#each moveProblems as p (p)}
      <Alert tone="danger" description={p} />
    {/each}
    <div class="bs-wiz__alt bs-stack bs-stack--sm">
      <p class="bs-wiz__muted">{__('wmOtherDomainHelp')}</p>
      <div class="bs-row">
        <Button variant="secondary" size="sm" onclick={() => (otherDomain = true)} disabled={moving}>{__('wmOtherDomain')}</Button>
      </div>
    </div>
  </div>
{/snippet}

<Modal bind:open title={__('fwTitle')} size="lg">
  <div class="bs-wiz">
    {#if check?.move && !otherDomain}
      {@render moveFlow(check.move)}
    {:else}
    <p class="bs-wiz__intro">{__('fwIntro')}</p>
    {#if check?.move && step === 0}
      <div class="bs-row">
        <Button variant="ghost" size="sm" onclick={() => (otherDomain = false)}>{__('fwMoveInstead')}</Button>
      </div>
    {/if}
    <Wizard
      steps={[
        { label: __('fwStepWhere'), content: whereStep, nextDisabled: !check || !path || !/^https?:\/\//i.test(url.trim()) },
        { label: __('fwStepCheck'), content: checkStep, nextDisabled: checking || !info?.ok },
        { label: __('fwStepCreate'), content: createStep, nextDisabled: !created },
        { label: __('fwStepDone'), content: doneStep },
      ] as WizardStep[]}
      bind:current={step}
      orientation="vertical"
      backLabel={__('wizardBack')}
      nextLabel={__('wizardNext')}
      finishLabel={__('fwFinish')}
      onchange={onStep}
      oncomplete={() => (open = false)}
    />
    {/if}
  </div>
</Modal>

<style>
  .bs-wiz {
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-4);
  }

  .bs-wiz__intro,
  .bs-wiz__muted {
    margin: 0;
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
  }

  .bs-wiz-step {
    min-height: 6rem;
  }

  .bs-wiz__h {
    margin: 0;
  }

  .bs-wiz__alt {
    padding-top: var(--ab-space-3);
    border-top: 1px solid var(--ab-color-border);
  }

  .bs-wiz__pre {
    margin: 0;
    padding: var(--ab-space-3);
    overflow-x: auto;
    font-size: var(--ab-text-xs);
    background: var(--ab-color-bg);
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-md);
  }
</style>
