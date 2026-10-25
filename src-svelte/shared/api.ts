import type {
  CheckPreview,
  ConnectionInput,
  ConnectionResponse,
  ConnectorPairResponse,
  DestinationsResponse,
  DiscoveryMode,
  ExportSnapshot,
  LinkItem,
  MediaItem,
  PagesOverview,
  ScheduleSettings,
  LockdownProbe,
  LockdownSettings,
  Preflight,
  ReplacementPage,
  ExcludedPage,
  VideoItem,
  ServerCheck,
  ServerConfig,
  Status,
  SyncSnapshot,
  TestResult,
} from './types';

interface BsData {
  restUrl: string;
  nonce: string;
}

declare global {
  interface Window {
    shipStaticData?: BsData;
  }
}

function config(): BsData {
  const data = window.shipStaticData;
  if (!data) {
    throw new Error('ShipStatic: shipStaticData was not localised.');
  }
  return data;
}

async function request<T>(path: string, method = 'GET', body?: unknown): Promise<T> {
  const { restUrl, nonce } = config();
  const res = await fetch(restUrl.replace(/\/$/, '') + path, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  if (!res.ok) {
    let message = `Request failed (${res.status}).`;
    try {
      const data = (await res.json()) as { message?: string };
      if (data?.message) {
        message = data.message;
      }
    } catch {
      /* non-JSON error body */
    }
    throw new Error(message);
  }

  return (await res.json()) as T;
}

export const api = {
  getConnection: () => request<ConnectionResponse>('/connection'),
  saveConnection: (data: ConnectionInput) => request<ConnectionResponse>('/connection', 'POST', data),
  testConnection: (data: ConnectionInput) => request<TestResult>('/connection/test', 'POST', data),
  getStatus: () => request<Status>('/status'),
  getPagesOverview: () => request<PagesOverview>('/pages-overview'),
  setDiscoveryMode: (mode: DiscoveryMode) =>
    request<{ discoveryMode: DiscoveryMode }>('/settings', 'POST', { discoveryMode: mode }),
  setFabEnabled: (enabled: boolean) =>
    request<{ fabEnabled: boolean }>('/settings', 'POST', { fabEnabled: enabled }),
  setSearchEnabled: (enabled: boolean) =>
    request<{ searchEnabled: boolean; renderCurrent?: boolean }>('/settings', 'POST', { searchEnabled: enabled }),
  setSearchLive: (enabled: boolean) =>
    request<{ searchLive: boolean }>('/settings', 'POST', { searchLive: enabled }),
  setAiToggles: (toggles: { aiAllowChanges?: boolean; aiAllowSync?: boolean }) =>
    request<{ aiAllowChanges: boolean; aiAllowSync: boolean }>('/settings', 'POST', toggles),
  setConcurrentSyncs: (n: number) =>
    request<{ concurrentSyncs: number }>('/settings', 'POST', { concurrentSyncs: n }),
  setWizardSeen: () => request<{ wizardSeen: boolean }>('/settings', 'POST', { wizardSeen: true }),
  getDestinations: () => request<DestinationsResponse>('/destinations'),
  addDestination: (data: ConnectionInput) => request<DestinationsResponse>('/destinations', 'POST', data),
  updateDestination: (id: string, data: ConnectionInput) => request<DestinationsResponse>(`/destinations/${id}`, 'POST', data),
  removeDestination: (id: string) => request<DestinationsResponse>(`/destinations/${id}`, 'DELETE'),
  testDestination: (id: string, data: ConnectionInput) => request<TestResult>(`/destinations/${id}/test`, 'POST', data),
  packageTest: (id: string) => request<TestResult>(`/destinations/${id}/package-test`, 'POST', {}),
  syncStart: (type: 'check' | 'sync', opts: { prune?: boolean; dest?: string; changes?: boolean; rerender?: boolean } = {}) =>
    request<SyncSnapshot>('/sync/start', 'POST', { type, ...opts }),
  checkDestination: (id: string) => request<CheckPreview>(`/sync/check?dest=${encodeURIComponent(id)}`),
  syncTick: () => request<SyncSnapshot>('/sync/tick', 'POST', {}),
  syncStatus: () => request<SyncSnapshot>('/sync'),
  deployDispatch: () => request<{ ok: boolean; spawned: number; phase: string }>('/sync/deploy-dispatch', 'POST'),
  syncCancel: () => request<SyncSnapshot>('/sync/cancel', 'POST', {}),
  syncRetry: () => request<SyncSnapshot>('/sync/retry', 'POST', {}),
  syncClaim: () => request<{ owner: 'cli' | 'browser' }>('/sync/claim', 'POST', {}),
  syncReset: () => request<{ ok: boolean }>('/sync/reset', 'POST', {}),
  preflight: () => request<Preflight>('/sync/preflight', 'POST', {}),
  connectorPair: (id: string) => request<ConnectorPairResponse>(`/destinations/${id}/connector/pair`, 'POST'),
  connectorRevoke: (id: string) => request<TestResult>(`/destinations/${id}/connector/revoke`, 'POST'),
  connectorRebuild: (id: string) => request<TestResult & { needsProcess?: boolean }>(`/destinations/${id}/connector/rebuild`, 'POST'),
  serverConfig: () => request<ServerConfig>('/sync/server-config'),
  getSchedule: () => request<ScheduleSettings>('/schedule'),
  saveSchedule: (data: Partial<Pick<ScheduleSettings, 'enabled' | 'period' | 'all' | 'destinations'>>) =>
    request<ScheduleSettings>('/schedule', 'POST', data),
  getLockdown: () => request<LockdownSettings>('/lockdown'),
  saveLockdown: (data: { enabled?: boolean; add?: { cidr: string; label: string }; remove?: string; redirect?: string; notify?: boolean }) =>
    request<LockdownSettings>('/lockdown', 'POST', data),
  newLockdownLink: () => request<LockdownSettings>('/lockdown/key', 'POST', {}),
  probeLockdown: () => request<LockdownProbe>('/lockdown/probe', 'POST', {}),
  serverCheck: (dest: string) => request<ServerCheck>(`/sync/server-check?dest=${encodeURIComponent(dest)}`),
  getMedia: (page = '') =>
    request<{ pages: ReplacementPage[]; excluded: ExcludedPage[]; page: string; media: MediaItem[] }>(
      `/media${page ? `?page=${encodeURIComponent(page)}` : ''}`,
    ),
  getLinks: () => request<{ links: LinkItem[] }>('/links'),
  getVideos: (page = '') =>
    request<{ pages: ReplacementPage[]; excluded: ExcludedPage[]; page: string; videos: VideoItem[] }>(
      `/videos${page ? `?page=${encodeURIComponent(page)}` : ''}`,
    ),
  exportStart: (dest: string) => request<ExportSnapshot>('/export/start', 'POST', { dest }),
  exportTick: () => request<ExportSnapshot>('/export/tick', 'POST', {}),
  exportStatus: () => request<ExportSnapshot>('/export'),
  exportCancel: () => request<ExportSnapshot>('/export/cancel', 'POST', {}),
};

/**
 * Build a one-time download URL for a finished export. Not a JSON `request()`
 * call — meant for a plain `<a href>` navigation, so it carries the `wp_rest`
 * nonce as a query param (WP core's REST cookie auth accepts `?_wpnonce=` as
 * a fallback to the `X-WP-Nonce` header a `fetch()` would normally send).
 */
/** One-time download URL for a freshly paired connector file (see exportDownloadUrl). */
export function connectorDownloadUrl(token: string): string {
  const { restUrl, nonce } = config();
  return `${restUrl.replace(/\/$/, '')}/connector/download?token=${encodeURIComponent(token)}&_wpnonce=${encodeURIComponent(nonce)}`;
}

export function exportDownloadUrl(token: string): string {
  const { restUrl, nonce } = config();
  return `${restUrl.replace(/\/$/, '')}/export/download?token=${encodeURIComponent(token)}&_wpnonce=${encodeURIComponent(nonce)}`;
}
