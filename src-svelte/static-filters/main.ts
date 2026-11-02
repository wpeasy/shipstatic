/**
 * ShipStatic static Bricks filters (the PHP side is Bricks\StaticFiltersReplacer).
 *
 * In the EXPORTED site, Bricks asks `…/bricks/v1/query_result` every time a filter, sort, page size, search or
 * pagination changes (and for infinite scroll). For the loops this site exported data for, that request is
 * answered here, in the browser, from a JSON file — no WordPress needed. Everything else Bricks asks (forms,
 * other loops) goes where Bricks sent it. The page lists its loops in
 * `<script type="application/json" id="shipstatic-filters">{"<query id>": "<dataset address>"}</script>`.
 *
 * If anything is missing or a dataset can't be read, the request is passed on untouched.
 */
import { prepare } from './engine.ts';
import type { Index } from './engine.ts';
import { buildReply } from './patch.ts';
import type { QueryRequest } from './patch.ts';
import type { Dataset } from './types.ts';

interface Loaded {
  ds: Dataset;
  idx: Index;
}
type XhrWithFlag = XMLHttpRequest & { __ssRequest?: boolean };

function config(): Record<string, string> {
  try {
    const el = document.getElementById('shipstatic-filters');
    const parsed = el ? (JSON.parse(el.textContent || '{}') as unknown) : {};
    return parsed && typeof parsed === 'object' ? (parsed as Record<string, string>) : {};
  } catch {
    return {};
  }
}

function install(datasets: Record<string, string>): void {
  const loading: Record<string, Promise<Loaded | null>> = {};
  const get = (qid: string): Promise<Loaded | null> =>
    (loading[qid] ||= fetch(datasets[qid])
      .then((r) => (r.ok ? (r.json() as Promise<Dataset>) : Promise.reject(new Error(String(r.status)))))
      .then((ds) => ({ ds, idx: prepare(ds) }))
      .catch(() => null));

  const open = XMLHttpRequest.prototype.open;
  const send = XMLHttpRequest.prototype.send;

  XMLHttpRequest.prototype.open = function (this: XhrWithFlag, ...args: unknown[]): void {
    this.__ssRequest = /\/query_result(\?|$)/.test(String(args[1]));
    return (open as (...a: unknown[]) => void).apply(this, args);
  } as typeof open;

  XMLHttpRequest.prototype.send = function (this: XhrWithFlag, body?: Document | XMLHttpRequestBodyInit | null): void {
    let req: (QueryRequest & { queryElementId?: string }) | null = null;
    try {
      req = this.__ssRequest && typeof body === 'string' ? (JSON.parse(body) as QueryRequest & { queryElementId?: string }) : null;
    } catch {
      req = null;
    }
    const qid = req?.queryElementId;
    if (!req || !qid || !datasets[qid]) return send.call(this, body);

    const xhr = this;
    void get(qid).then((loaded) => {
      if (!loaded) return send.call(xhr, body); // data unreadable: let Bricks ask WordPress
      let reply: string;
      try {
        reply = JSON.stringify(buildReply(loaded.ds, loaded.idx, document, req as QueryRequest));
      } catch {
        return send.call(xhr, body);
      }
      for (const [k, v] of Object.entries({ readyState: 4, status: 200, response: reply, responseText: reply })) Object.defineProperty(xhr, k, { value: v, configurable: true });
      if (typeof xhr.onreadystatechange === 'function') xhr.onreadystatechange(new Event('readystatechange'));
      xhr.dispatchEvent(new Event('load'));
      xhr.dispatchEvent(new Event('loadend'));
    });
  } as typeof send;
}

const datasets = config();
if (Object.keys(datasets).length) install(datasets);
