<script lang="ts">
  import { Alert, Button, ConfirmButton } from '@wpeasy/ab-ui';
  import { api } from '../shared/api';
  import type { WpMoveStatus } from '../shared/types';
  import { __, __f } from '../shared/i18n';

  // After "Move WordPress into /wp" (Deploy\WordPressMove): what happened, and
  // the way back while the move record exists.
  let { move, onChanged }: { move: WpMoveStatus; onChanged: () => void } = $props();

  let busy = $state(false);
  let message = $state('');

  async function undo(): Promise<void> {
    busy = true;
    message = '';
    try {
      const r = await api.undoWordPressMove();
      message = r.message;
      if (r.ok && r.redirect) {
        const to = r.redirect;
        setTimeout(() => (window.location.href = to), 2500);
      } else {
        busy = false;
      }
    } catch (e) {
      message = (e as Error).message;
      busy = false;
    }
  }

  async function forget(): Promise<void> {
    busy = true;
    try {
      await api.forgetWordPressMove();
      onChanged();
    } finally {
      busy = false;
    }
  }
</script>

{#if move.state === 'moved'}
  <Alert tone="success" title={__('wmMovedTitle')}>
    <div class="bs-stack bs-stack--xs">
      <p>{__f('wmMovedBody', move.to, move.from)}</p>
      <div class="bs-row bs-row--wrap">
        <Button variant="secondary" size="sm" onclick={forget} disabled={busy}>{__('wmKeep')}</Button>
        <ConfirmButton variant="ghost" size="sm" label={__('wmUndo')} confirmLabel={__('wmUndoConfirm')} onconfirm={undo} disabled={busy} />
      </div>
      {#if message}<p>{message}</p>{/if}
    </div>
  </Alert>
{:else if move.state === 'failed'}
  <Alert tone="danger" title={__('wmFailedTitle')}>
    <div class="bs-stack bs-stack--xs">
      <p>{__f('wmFailedBody', move.error)}</p>
      <div class="bs-row"><Button variant="secondary" size="sm" onclick={forget} disabled={busy}>{__('wmDismiss')}</Button></div>
    </div>
  </Alert>
{:else if move.state === 'files' || move.state === 'undoing'}
  <Alert tone="warning" description={__('wmInProgress')} />
{/if}
