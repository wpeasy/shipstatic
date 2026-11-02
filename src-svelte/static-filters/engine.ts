/**
 * Bricks' query-filter logic (query-filters.php, filter-base.php), re-implemented over exported data, so a
 * static page can answer its own `query_result` requests. Pure functions, no DOM: the same file runs in the
 * browser and in the differential tests (scripts/static-filters), which hold it to Bricks' own answers.
 *
 * Every rule here was read from the Bricks source and confirmed against real Bricks replies; the comments say
 * which Bricks behaviour a line copies.
 */
import type { Dataset, FilterDef, Post, Result, Row, Selected } from './types.ts';

type Matcher = (id: number) => boolean;
interface Active {
  f: FilterDef;
  m: Matcher;
}
interface Rows {
  direct: Record<string, Set<string>>;
  vals: Record<string, { value: string; display: string; id: number; parent: number }>;
}
export interface Index {
  termById: Record<string, string>;
  filterRows: Record<string, Rows>;
  parentVal: Record<string, Record<string, string | null>>;
}

/** MySQL's default collation compares case- and accent-insensitively. */
const fold = (s: unknown): string => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export function prepare(ds: Dataset): Index {
  const idx: Index = { termById: {}, filterRows: {}, parentVal: {} };
  for (const [fid, rows] of Object.entries(ds.rows)) {
    const direct: Rows['direct'] = {};
    const vals: Rows['vals'] = {};
    for (const [obj, value, display, vid, parent] of rows as Row[]) {
      (direct[obj] ||= new Set()).add(value);
      vals[value] ||= { value, display, id: vid, parent };
      if (vid) idx.termById[fid + ':' + vid] = value;
    }
    idx.filterRows[fid] = { direct, vals };
    if (ds.filters[fid]) ds.filters[fid].labels = Object.fromEntries(Object.values(vals).map((v) => [v.value, v.display]));
  }
  // Each taxonomy value's parent value (selecting a parent also matches its children).
  for (const fid of Object.keys(ds.rows)) {
    const m: Record<string, string | null> = {};
    for (const v of Object.values(idx.filterRows[fid].vals)) if (v.parent) m[v.value] = idx.termById[fid + ':' + v.parent] ?? null;
    idx.parentVal[fid] = m;
  }
  return idx;
}

const withAncestors = (set: Set<string>, parentVal: Record<string, string | null>): Set<string> => {
  const out = new Set(set);
  for (const v of set) {
    let p = parentVal[v];
    while (p && !out.has(p)) {
      out.add(p);
      p = parentVal[p];
    }
  }
  return out;
};

/** WordPress search: every word must be in the title, excerpt or content; `-word` excludes. */
function searchMatch(post: Post, q: string): boolean {
  const terms = fold(q).split(/\s+/).filter(Boolean);
  const hay = fold(post.title) + '\n' + fold(post.excerpt) + '\n' + fold(post.content);
  return terms.every((t) => (t[0] === '-' ? !hay.includes(t.slice(1)) : hay.includes(t)));
}

/**
 * WordPress date_query on post_date, as WP_Date_Query builds it: single "is" = that calendar day, "before" =
 * strictly before the day, "after" = strictly after the whole day, a range = both end days included.
 */
const day = (d: string): string => String(d).slice(0, 10);
function dateMatch(f: FilterDef, value: unknown): (p: Post) => boolean {
  const [a, b] = String(value).split(',');
  if (f.range) return b ? (p) => day(p.date) >= a && day(p.date) <= b : () => true;
  if (f.operator === 'before') return (p) => day(p.date) < a;
  if (f.operator === 'after') return (p) => day(p.date) > a;
  return (p) => day(p.date) === a;
}

export function matcher(ds: Dataset, idx: Index, f: FilterDef, value: unknown): Matcher {
  switch (f.element) {
    case 'filter-datepicker': {
      const m = dateMatch(f, value);
      return (id) => m(ds.posts[id]);
    }
    case 'filter-search':
      return (id) => searchMatch(ds.posts[id], String(value));
    case 'filter-range': {
      const [lo, hi] = value as [number, number];
      return (id) => {
        const n = parseFloat(ds.posts[id].meta?.[f.metaKey] ?? '');
        return n >= lo && n <= hi;
      };
    }
    case 'filter-checkbox':
    case 'filter-radio':
    case 'filter-select': {
      const sel = (Array.isArray(value) ? value : [value]).map(String);
      const pv = idx.parentVal[f.id];
      const has = (id: number): Set<string> => withAncestors(idx.filterRows[f.id].direct[id] ?? new Set(), f.source === 'taxonomy' ? pv : {});
      return f.logic === 'AND'
        ? (id) => {
            const h = has(id);
            return sel.every((v) => h.has(v));
          }
        : (id) => {
            const h = has(id);
            return sel.some((v) => h.has(v));
          };
    }
  }
  return () => true;
}

const isActive = (f: FilterDef, v: unknown): boolean => v != null && !(Array.isArray(v) && v.length === 0) && v !== '' && f.action !== 'sort' && f.action !== 'per_page';

/** Option => number of objects, over a list of ids. */
function group(idx: Index, fid: string, ids: number[], everything?: number[]): Record<string, number> {
  // Bricks builds `WHERE object_id IN (...)` only when the id list is non-empty: an empty list counts everything.
  if (!ids.length && everything) ids = everything;
  const c: Record<string, number> = {};
  const d = idx.filterRows[fid].direct;
  for (const id of ids) for (const v of d[id] ?? []) c[v] = (c[v] || 0) + 1;
  return c;
}

function sortIds(ds: Dataset, ids: number[], sortFilter: FilterDef | undefined, sortVal: unknown): number[] {
  const s = sortFilter?.sorts?.[String(sortVal)];
  if (!s) return ids;
  const dir = s.dir === 'DESC' ? -1 : 1;
  const keyOf = (id: number): string | number => {
    const p = ds.posts[id];
    if (s.src === 'title') return fold(p.title);
    if (s.src === 'post_date') return p.date;
    if (s.src === 'meta_value_num') return parseFloat(p.meta?.[s.key] ?? '');
    return fold(p.meta?.[s.key]);
  };
  // Equal keys: MySQL leaves their order undefined; the object id in the sort direction is the closest guess.
  return [...ids].sort((a, b) => {
    const ka = keyOf(a),
      kb = keyOf(b);
    return ka < kb ? -dir : ka > kb ? dir : (a - b) * dir;
  });
}

export function run(ds: Dataset, idx: Index, selected: Selected): Result {
  const fs = Object.values(ds.filters);
  const active: Active[] = fs.filter((f) => isActive(f, selected[f.id])).map((f) => ({ f, m: matcher(ds, idx, f, selected[f.id]) }));
  const resultsFor = (list: Active[]): number[] => ds.order.filter((id) => list.every((a) => a.m(id)));
  const all = resultsFor(active);

  const ppF = fs.find((f) => f.action === 'per_page');
  const ppV = ppF ? String(selected[ppF.id] ?? '') : '';
  const perPage = ppF && ppV && String(ppF.perPageOptions ?? '10, 20, 50, 100').split(',').map((x) => x.trim()).includes(ppV) ? parseInt(ppV, 10) : ds.perPage;
  const pageParam = selected._infinite ?? (ds.paginationId ? selected[ds.paginationId] : undefined);
  const wantPage = Math.max(1, parseInt(String(pageParam || 1), 10));
  // Past the last page Bricks reports 0 results, but still counts a selected filter's options over the real ones.
  const pastEnd = wantPage > (Math.ceil(all.length / perPage) || 1);

  const sortF = fs.find((f) => f.action === 'sort' && selected[f.id]) || fs.find((f) => f.action === 'sort');
  const sortV = sortF ? selected[sortF.id] : undefined;
  const ordered = sortV ? sortIds(ds, all, sortF, sortV) : all;
  const pages = Math.ceil(all.length / perPage) || 0;
  const slice = pastEnd ? [] : ordered.slice((wantPage - 1) * perPage, wantPage * perPage);
  const out: Result = pastEnd
    ? { ids: [], count: 0, pages: 0, start: 0, end: 0, perPage, opts: {} }
    : { ids: slice, count: all.length, pages, start: all.length ? (wantPage - 1) * perPage + 1 : 0, end: Math.min(wantPage * perPage, all.length), perPage, opts: {} };

  for (const f of fs) {
    if (f.action === 'sort' || f.action === 'per_page' || f.element === 'filter-search' || f.element === 'filter-datepicker' || f.element === 'filter-active-filters') continue;
    const self = active.find((a) => a.f.id === f.id);
    const others = active.filter((a) => a.f.id !== f.id || (f.element === 'filter-checkbox' && f.logic === 'AND' && a.f.id === f.id));

    if (f.element === 'filter-range') {
      // Min/max come from the results of the OTHER filters (the whole population when that is empty).
      const num = (id: number): number => parseFloat(ds.posts[id].meta?.[f.metaKey] ?? '');
      let pool = resultsFor(active.filter((a) => a.f.id !== f.id)).map(num);
      if (!pool.length) pool = ds.order.map(num);
      const lo = Math.min(...pool);
      let hi = Math.max(...pool);
      if (hi === lo) hi = lo + 1;
      const chosen = selected[f.id] as [number, number] | undefined;
      out.opts[f.id + '_range'] = [lo, hi, chosen ? Math.max(chosen[0], lo) : lo];
      continue;
    }

    // The count rule in Bricks' filter-base.php (get_populated_options):
    const base = group(idx, f.id, ds.order);
    const multi = f.element === 'filter-checkbox';
    const opts: Record<string, number> = {};
    let src: Record<string, number> | undefined;
    let notFoundZero = false;
    if (self && others.length > 0) {
      // An active filter is counted over the OTHER filters that give it query variables; a WordPress-field date
      // filter becomes a date_query, which that code ignores — with nothing left, every count is 0.
      const contributing = others.filter((a) => !(a.f.element === 'filter-datepicker' && a.f.source === 'wpField'));
      src = contributing.length ? group(idx, f.id, resultsFor(contributing), ds.order) : {};
      notFoundZero = true;
    } else if (!self && others.length > 0) {
      src = group(idx, f.id, all, ds.order);
      notFoundZero = true;
    } else if (multi) {
      src = group(idx, f.id, all, ds.order);
      notFoundZero = f.logic === 'AND';
    } else if (!self) {
      src = group(idx, f.id, all, ds.order);
    }
    for (const v of Object.keys(base)) {
      let c = base[v];
      if (src) {
        if (v in src) c = src[v];
        else if (notFoundZero) c = 0;
      }
      if ((all.length === 0 || pastEnd) && !self) c = 0;
      opts[v] = c;
    }
    out.opts[f.id] = opts;
  }
  return out;
}
