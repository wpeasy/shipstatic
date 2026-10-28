// Editor entry: drives two surfaces (both enqueued by src/Admin/Editor.php).
// On a post-edit screen it mounts the "ShipStatic" metabox (manual mode) from
// window.shipStaticEditorData. On a Pages/Posts list screen it mounts the "Static"
// column controller from window.shipStaticListData, which wires the server-rendered
// cells to the shared sync modal.
import '@wpeasy/ab-ui/styles';
import '@wpeasy/ab-ui/styles/wp-admin.css';
import '../shared/app.css';
import { mount } from 'svelte';
import EditorMetabox from './EditorMetabox.svelte';
import ListController from './ListController.svelte';

interface EditorData {
  restUrl: string;
  nonce: string;
  postId: number;
  pageUrl: string;
  published: boolean | string;
  included: boolean | string;
  includedCount?: number;
  i18n?: Record<string, string>;
}

interface ListData {
  restUrl: string;
  nonce: string;
  manualMode?: boolean | string;
  fabEnabled?: boolean | string;
  includedCount?: number;
  labels?: { included?: string; excluded?: string; failed?: string };
}

const win = window as unknown as { shipStaticEditorData?: EditorData; shipStaticListData?: ListData };

// --- Pages/Posts list-table "Static" column -------------------------------
const list = win.shipStaticListData;
if (list && list.restUrl) {
  const host = document.createElement('div');
  document.body.appendChild(host);
  mount(ListController, {
    target: host,
    props: {
      restUrl: list.restUrl,
      nonce: list.nonce,
      manualMode: !!list.manualMode,
      fabEnabled: !!list.fabEnabled,
      includedCount: Number(list.includedCount) || 0,
      labels: list.labels ?? {},
    },
  });
}

// --- Single-post metabox --------------------------------------------------
const data = win.shipStaticEditorData;
if (data && data.restUrl) {
  // shared/i18n.ts reads window.shipStaticData.i18n — seed it from the editor payload.
  const w = window as unknown as { shipStaticData?: { i18n?: Record<string, string> } };
  if (!w.shipStaticData) {
    w.shipStaticData = { i18n: data.i18n ?? {} };
  }

  const target = document.getElementById('bs-editor-root');
  if (target) {
    mount(EditorMetabox, {
      target,
      props: {
        restUrl: data.restUrl,
        nonce: data.nonce,
        postId: Number(data.postId) || 0,
        pageUrl: data.pageUrl,
        published: !!data.published,
        included: !!data.included,
        includedCount: Number(data.includedCount) || 0,
      },
    });
  }
}
