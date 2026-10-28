// Link-integrity REST helpers for manual-mode include/exclude, shared by the
// sync modal (SyncPanel), the editor metabox and the list column. Each takes the caller's
// restUrl + nonce so neither component depends on a particular data global.

export interface LinkPage {
  postId: number;
  title: string;
}

async function postJson<T>(restUrl: string, nonce: string, path: string, body: unknown): Promise<T> {
  const res = await fetch(restUrl + path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    body: JSON.stringify(body),
  });
  return res.json();
}

async function getJson<T>(restUrl: string, nonce: string, path: string): Promise<T> {
  const res = await fetch(restUrl + path, { headers: { 'X-WP-Nonce': nonce } });
  return res.json();
}

/** Pages this post links to that aren't included yet (offered on enable). */
export function outboundExcluded(
  restUrl: string,
  nonce: string,
  postId: number,
): Promise<{ pages: LinkPage[]; busy?: boolean }> {
  return postJson(restUrl, nonce, '/editor/links', { postId });
}

/** Included pages that link to this post (warned on disable). */
export function inboundIncluded(restUrl: string, nonce: string, postId: number): Promise<{ pages: LinkPage[] }> {
  return getJson(restUrl, nonce, '/editor/inbound?postId=' + postId);
}

/** Include a batch of posts (the "Include all" cascade). */
export function includeBulk(
  restUrl: string,
  nonce: string,
  postIds: number[],
): Promise<{ included: number[]; skipped: number[]; includedCount: number; savedCount: number }> {
  return postJson(restUrl, nonce, '/editor/include-bulk', { postIds });
}

/** Include or exclude one post (the list's check icon). Throws when refused. */
export async function setInclude(
  restUrl: string,
  nonce: string,
  postId: number,
  include: boolean,
): Promise<{ included: boolean; includedCount: number; savedCount: number }> {
  const r = await postJson<{ included?: boolean; includedCount: number; savedCount: number; message?: string }>(
    restUrl,
    nonce,
    '/editor/include',
    { postId, include },
  );
  if (typeof r.included !== 'boolean') {
    throw new Error(r.message || 'Request failed');
  }
  return r as { included: boolean; includedCount: number; savedCount: number };
}
