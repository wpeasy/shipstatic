/**
 * The markup half of a Bricks `query_result` reply: Bricks answers with the re-rendered HTML of every filter
 * element, so a static page has to rebuild it too. Each filter's markup (captured once, with nothing selected)
 * is patched to the new state — counts, checked/disabled, chips, pagination — exactly as Bricks prints it
 * (verified as DOM against real replies).
 *
 * DOM-agnostic: pass the `document` (the browser's, or a test DOM).
 */
import { run } from './engine.ts';
import type { Index } from './engine.ts';
import type { Dataset, FilterDef, Reply, Selected } from './types.ts';

/** PHP prints floats with 14 significant digits. */
const phpFloat = (n: number): string => String(Number(Number(n).toPrecision(14)));
const parse = (doc: Document, html: string): Element => {
  const t = doc.createElement('template');
  t.innerHTML = html.trim();
  return t.content.firstElementChild as Element;
};
const esc = (t: unknown): string => String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const pageSizes = (f: FilterDef): string[] => String(f.perPageOptions ?? '10, 20, 50, 100').split(',').map((x) => x.trim());

function toggle(node: Element | null, name: string, on: boolean, keepEmptyClass = false): void {
  if (!node) return;
  node.classList.toggle(name, on);
  if (!node.getAttribute('class') && !(keepEmptyClass && node.tagName === 'LABEL')) node.removeAttribute('class');
  else if (!node.getAttribute('class')) node.setAttribute('class', '');
}

function setCount(li: Element, n: number | undefined): void {
  const c = li.querySelector('.brx-option-count');
  if (c && n !== undefined) c.textContent = `(${n})`;
}

function patchCheckbox(el: Element, sel: unknown, opts: Record<string, number>): void {
  const chosen = new Set((Array.isArray(sel) ? sel : sel ? [sel] : []).map(String));
  el.querySelectorAll('li').forEach((li) => {
    const input = li.querySelector('input');
    if (!input) return;
    const v = input.getAttribute('value') ?? '';
    const n = opts[v];
    const on = chosen.has(v);
    const off = n === 0;
    toggle(li, 'brx-option-active', on);
    toggle(li.querySelector('label'), 'brx-option-active', on);
    toggle(li.querySelector('.brx-option-text'), 'brx-option-active', on);
    toggle(li.querySelector('label'), 'brx-option-disabled', off);
    if (on) input.setAttribute('checked', 'checked');
    else input.removeAttribute('checked');
    // A selected checkbox stays enabled even at 0, so it can be unticked.
    if (off && !on) input.setAttribute('disabled', 'disabled');
    else input.removeAttribute('disabled');
    setCount(li, n);
  });
}

/** Radio buttons share the checkbox list markup; "All" is the radio with an empty value, active when nothing else is. */
function patchRadio(el: Element, f: FilterDef, sel: unknown, opts: Record<string, number> | null): void {
  const chosen = sel == null || sel === '' ? '' : String(Array.isArray(sel) ? sel[0] : sel);
  const keepEmpty = f.action !== 'filter'; // a sort/per-page radio prints `<label class>`, a filter prints no attribute
  el.querySelectorAll('li').forEach((li) => {
    const input = li.querySelector('input');
    if (!input) return;
    const v = input.getAttribute('value') ?? '';
    const on = v === chosen;
    const n = v === '' ? undefined : opts?.[v];
    const off = n === 0;
    const label = li.querySelector('label');
    toggle(li, 'brx-option-active', on);
    toggle(label, 'brx-option-active', on, keepEmpty);
    toggle(li.querySelector('.brx-option-text'), 'brx-option-active', on);
    toggle(label, 'brx-option-disabled', off, keepEmpty);
    if (on) input.setAttribute('checked', 'checked');
    else input.removeAttribute('checked');
    // Unlike a checkbox, a selected radio is disabled too.
    if (off) input.setAttribute('disabled', 'disabled');
    else input.removeAttribute('disabled');
    setCount(li, n);
  });
}

/** `<select>`: the selected attribute and, for a filter, the "(count)" Bricks adds to each option's text. */
function patchSelect(el: Element, sel: unknown, opts: Record<string, number> | null): void {
  const chosen = sel == null ? '' : String(sel);
  el.querySelectorAll('option').forEach((o) => {
    const v = o.getAttribute('value') ?? '';
    if (v === chosen) o.setAttribute('selected', 'selected');
    else o.removeAttribute('selected');
    if (opts && v !== '' && opts[v] !== undefined) {
      o.textContent = (o.textContent ?? '').replace(/\(\d+\)\s*$/, `(${opts[v]})`);
      const off = opts[v] === 0;
      o.classList.toggle('brx-option-disabled', off);
      if (off) o.setAttribute('disabled', '');
      else o.removeAttribute('disabled');
    }
  });
}

function patchRange(el: Element, sel: unknown, range: [number, number, number]): void {
  const [min, max, lo0] = range;
  // The handles clamp to the available bounds (low up, high down) and are never swapped; the track hides when high <= low.
  const [lo, hi] = Array.isArray(sel) ? [Math.max(Number(sel[0]), min), Math.min(Number(sel[1]), max)] : [lo0, max];
  el.querySelectorAll('input.min, input.max').forEach((i) => {
    i.setAttribute('min', phpFloat(min));
    i.setAttribute('max', phpFloat(max));
  });
  el.querySelectorAll('input.min').forEach((i) => i.setAttribute('value', phpFloat(lo)));
  el.querySelectorAll('input.max').forEach((i) => i.setAttribute('value', phpFloat(hi)));
  const span = max - min || 1;
  el.querySelector('.slider-track')?.setAttribute(
    'style',
    `width:${phpFloat(((hi - lo) / span) * 100)}%; left:${phpFloat(((lo - min) / span) * 100)}%;${hi <= lo ? ' visibility: hidden;' : ''}`,
  );
  const lower = el.querySelector('.value-wrap .lower .value');
  const upper = el.querySelector('.value-wrap .upper .value');
  if (lower) lower.textContent = phpFloat(lo);
  if (upper) upper.textContent = phpFloat(hi);
}

/** The chip text Bricks shows for a selected value. */
function chips(f: FilterDef, v: unknown): [string, string][] {
  if (f.action === 'sort') return [[String(v), f.sorts?.[String(v)]?.label ?? String(v)]];
  if (f.element === 'filter-range') {
    const r = v as [number, number];
    return [[String(r[0]), ` ${phpFloat(r[0])} -  ${phpFloat(r[1])}`]];
  }
  if (f.element === 'filter-datepicker') return [[String(v), String(v).split(',').join(' - ')]];
  if (f.action === 'per_page' || f.element === 'filter-search') return [[String(v), String(v)]];
  return (Array.isArray(v) ? v : [v]).map((x) => [String(x), f.labels?.[String(x)] ?? String(x)] as [string, string]);
}

function patchActive(ds: Dataset, doc: Document, el: Element, sel: Selected): void {
  el.innerHTML = '';
  for (const [fid, v] of Object.entries(sel)) {
    const f = ds.filters[fid];
    if (!f || v == null || v === '' || (Array.isArray(v) && !v.length)) continue;
    if (f.action === 'per_page' && !pageSizes(f).includes(String(v))) continue; // an unknown page size is ignored
    for (const [val, text] of chips(f, v)) {
      const li = doc.createElement('li');
      li.innerHTML = `<button aria-label="Clear filter: ${esc(text)}" class="bricks-button" data-filter-id="${fid}" data-filter-value="${esc(val)}" data-filter-url-param="${esc(f.nice)}">${esc(text)}</button>`;
      el.appendChild(li);
    }
  }
}

/** WordPress's paginate_links(): end size 1, mid size 2, `/page/N/` addresses. */
function patchPagination(ds: Dataset, el: Element, page: number, total: number): void {
  const base = ds.baseUrl;
  const href = (n: number): string => (n <= 1 ? base : `${base}page/${n}/`);
  const rows: string[] = [];
  if (page > 1) rows.push(`<li><a class="prev page-numbers" aria-label="Previous page" href="${href(page - 1)}">&larr;</a></li>`);
  let dots = false;
  for (let n = 1; n <= total; n++) {
    if (n === page) {
      rows.push(`<li><span aria-current="page" class="page-numbers current">${n}</span></li>`);
      dots = true;
    } else if (n <= 1 || (n >= page - 2 && n <= page + 2) || n > total - 1) {
      rows.push(`<li><a class="page-numbers" href="${href(n)}">${n}</a></li>`);
      dots = true;
    } else if (dots) {
      rows.push('<li><span class="page-numbers dots">&hellip;</span></li>');
      dots = false;
    }
  }
  if (page < total) rows.push(`<li><a class="next page-numbers" aria-label="Next page" href="${href(page + 1)}">&rarr;</a></li>`);
  const nav = el.querySelector('.bricks-pagination');
  if (!nav) return;
  if (total > 1) el.removeAttribute('style');
  else el.setAttribute('style', 'display: none;');
  nav.innerHTML = total > 1 ? `<ul class='page-numbers'>\n\t${rows.join('\n\t')}\n</ul>\n` : '';
}

function patchDatepicker(el: Element, sel: unknown): void {
  const input = el.querySelector('input');
  if (!input) return;
  const o = JSON.parse(input.getAttribute('data-bricks-datepicker-options') || '{}') as Record<string, unknown>;
  if (sel) o.defaultDate = String(sel).split(',');
  else delete o.defaultDate;
  input.setAttribute('data-bricks-datepicker-options', JSON.stringify(o));
}

export interface QueryRequest {
  selectedFilters?: Selected;
  filters?: string[];
  infinitePage?: number;
}

/** What Bricks' `query_result` would answer for this request. */
export function buildReply(ds: Dataset, idx: Index, doc: Document, req: QueryRequest): Reply {
  const sel: Selected = { ...(req.selectedFilters || {}) };
  if ((req.infinitePage ?? 0) > 1) sel._infinite = req.infinitePage;
  const r = run(ds, idx, sel);
  const page = Math.max(1, parseInt(String(sel._infinite ?? (ds.paginationId ? sel[ds.paginationId] : 1) ?? 1), 10));

  const uq = JSON.parse(JSON.stringify(ds.updatedQuery)) as Record<string, unknown> & { query_vars: Record<string, unknown> };
  Object.assign(uq, { count: r.count, max_num_pages: r.pages, start: r.start, end: r.end });
  uq.query_vars.paged = page;
  if (r.perPage) uq.query_vars.posts_per_page = r.perPage;

  const filters: Record<string, string> = {};
  for (const id of req.filters || []) {
    const f = ds.filters[id];
    const tpl = ds.filterHtml[id];
    if (!tpl) continue;
    const el = parse(doc, tpl);
    if (f?.element === 'filter-checkbox' && f.action === 'filter') patchCheckbox(el, sel[id], r.opts[id] || {});
    else if (f?.element === 'filter-radio') patchRadio(el, f, sel[id], f.action === 'filter' ? r.opts[id] || {} : null);
    else if (f?.element === 'filter-select') patchSelect(el, sel[id], f.action === 'filter' ? r.opts[id] || {} : null);
    else if (f?.element === 'filter-range') patchRange(el, sel[id], r.opts[id + '_range']);
    else if (f?.element === 'filter-datepicker') patchDatepicker(el, sel[id]);
    else if (f?.element === 'filter-search') el.querySelector('input')?.setAttribute('value', String(sel[id] ?? ''));
    else if (f?.element === 'filter-active-filters') patchActive(ds, doc, el, sel);
    else if (/brx-ajax-pagination/.test(tpl)) patchPagination(ds, el, page, r.pages);
    filters[id] = el.outerHTML;
  }
  return { html: r.ids.map((i) => ds.items[i]).join(''), styles: '', popups: [], updated_filters: filters, updated_query: uq, parsed_af_tags: [] };
}
