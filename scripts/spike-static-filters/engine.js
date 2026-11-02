// Spike: a static re-implementation of Bricks' query_result filtering, from exported data.
// Pure functions, no DOM: runs in Node (differential tests) and in the browser.
const fold = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export function prepare(ds) {
	const idx = { termById: {}, parentOf: {}, direct: {}, values: {}, filterRows: {} };
	for (const [fid, rows] of Object.entries(ds.rows)) {
		const direct = {}; const vals = {};
		for (const [obj, value, display, vid, parent] of rows) {
			(direct[obj] ||= new Set()).add(value);
			vals[value] ||= { value, display, id: vid, parent };
			if (vid) { idx.termById[fid + ':' + vid] = value; }
		}
		idx.filterRows[fid] = { direct, vals };
		if (ds.filters[fid]) ds.filters[fid].labels = Object.fromEntries(Object.values(vals).map((v) => [v.value, v.display]));
	}
	// ancestors per taxonomy filter (value -> parent value)
	idx.parentVal = {};
	for (const fid of Object.keys(ds.rows)) {
		const m = {}; const { vals } = idx.filterRows[fid];
		for (const v of Object.values(vals)) { if (v.parent) m[v.value] = idx.termById[fid + ':' + v.parent] ?? null; }
		idx.parentVal[fid] = m;
	}
	return idx;
}

const withAncestors = (set, parentVal) => { const out = new Set(set); for (const v of set) { let p = parentVal[v]; while (p && !out.has(p)) { out.add(p); p = parentVal[p]; } } return out; };

function searchMatch(post, q) {
	const terms = fold(q).split(/\s+/).filter(Boolean);
	const hay = fold(post.title) + '\n' + fold(post.excerpt) + '\n' + fold(post.content);
	return terms.every((t) => (t[0] === '-' ? !hay.includes(t.slice(1)) : hay.includes(t)));
}

// WordPress date_query on post_date, as WP_Date_Query builds it: single "is" = that calendar day, "before" = strictly before the
// day, "after" = strictly after the whole day, a range = both end days included.
const day = (d) => String(d).slice(0, 10);
function dateMatch(f, value) {
	const [a, b] = String(value).split(',');
	if (f.range) return b ? (p) => day(p.date) >= a && day(p.date) <= b : () => true;
	if (f.operator === 'before') return (p) => day(p.date) < a;
	if (f.operator === 'after') return (p) => day(p.date) > a;
	return (p) => day(p.date) === a;
}

export function matcher(ds, idx, f, value) {
	switch (f.element) {
		case 'filter-datepicker': { const m = dateMatch(f, value); return (id) => m(ds.posts[id]); }
		case 'filter-search': return (id) => searchMatch(ds.posts[id], value);
		case 'filter-range': { const [lo, hi] = value; return (id) => { const n = parseFloat(ds.posts[id].meta?.[f.metaKey]); return n >= lo && n <= hi; }; }
		case 'filter-checkbox': case 'filter-radio': case 'filter-select': {
			const sel = Array.isArray(value) ? value : [value];
			const pv = idx.parentVal[f.id];
			const has = (id) => withAncestors(idx.filterRows[f.id].direct[id] ?? new Set(), f.source === 'taxonomy' ? pv : {});
			return f.logic === 'AND' ? (id) => { const h = has(id); return sel.every((v) => h.has(v)); } : (id) => { const h = has(id); return sel.some((v) => h.has(v)); };
		}
	}
	return () => true;
}

const isActive = (f, v) => v != null && !(Array.isArray(v) && v.length === 0) && v !== '' && f.action !== 'sort' && f.action !== 'per_page';

function group(idx, fid, ids, everything) {
	// Bricks builds `WHERE object_id IN (...)` only when the id list is non-empty: an empty list counts everything.
	if (!ids.length && everything) ids = everything;
	const c = {}; const d = idx.filterRows[fid].direct;
	for (const id of ids) for (const v of d[id] ?? []) c[v] = (c[v] || 0) + 1;
	return c;
}

function sortIds(ds, ids, sortFilter, sortVal) {
	const s = sortFilter?.sorts?.[sortVal]; if (!s) return ids;
	const dir = s.dir === 'DESC' ? -1 : 1; const arr = ids.map((id, i) => [id, i]);
	const keyOf = (id) => s.src === 'title' ? fold(ds.posts[id].title) : s.src === 'post_date' ? ds.posts[id].date : s.src === 'meta_value_num' ? parseFloat(ds.posts[id].meta?.[s.key]) : fold(ds.posts[id].meta?.[s.key]);
	arr.sort((a, b) => { const ka = keyOf(a[0]), kb = keyOf(b[0]); return ka < kb ? -dir : ka > kb ? dir : (a[0] - b[0]) * dir; });
	return arr.map((x) => x[0]);
}

export function run(ds, idx, selected) {
	const fs = Object.values(ds.filters);
	const active = fs.filter((f) => isActive(f, selected[f.id])).map((f) => ({ f, m: matcher(ds, idx, f, selected[f.id]) }));
	const resultsFor = (list) => ds.order.filter((id) => list.every((a) => a.m(id)));
	const all = resultsFor(active);
	const ppF = fs.find((f) => f.action === 'per_page'); const ppV = ppF ? String(selected[ppF.id] ?? '') : '';
	const perPage = ppF && ppV && String(ppF.perPageOptions ?? '10, 20, 50, 100').split(',').map((x) => x.trim()).includes(ppV) ? parseInt(ppV, 10) : ds.perPage;
	const pageParam = selected._infinite ?? (ds.paginationId ? selected[ds.paginationId] : undefined);
	const wantPage = Math.max(1, parseInt(pageParam || 1, 10));
	const pastEnd = wantPage > (Math.ceil(all.length / perPage) || 1); // Bricks reports 0 results, but still counts a selected filter's options over the real ones
	const sortF = fs.find((f) => f.action === 'sort' && selected[f.id]) || fs.find((f) => f.action === 'sort'); const sortV = sortF ? selected[sortF.id] : undefined;
	const ordered = sortV ? sortIds(ds, all, sortF, sortV) : all;
	const page = wantPage;
	const pages = Math.ceil(all.length / perPage) || 0;
	const slice = pastEnd ? [] : ordered.slice((page - 1) * perPage, page * perPage);
	const out = pastEnd ? { ids: [], count: 0, pages: 0, start: 0, end: 0, perPage, opts: {} } : { ids: slice, count: all.length, pages, start: all.length ? (page - 1) * perPage + 1 : 0, end: Math.min(page * perPage, all.length), perPage, opts: {} };
	for (const f of fs) {
		if (f.action === 'sort' || f.action === 'per_page' || f.element === 'filter-search' || f.element === 'filter-datepicker' || f.element === 'filter-active-filters') continue;
		const self = active.find((a) => a.f.id === f.id);
		const others = active.filter((a) => a.f.id !== f.id || (f.element === 'filter-checkbox' && f.logic === 'AND' && a.f.id === f.id));
		if (f.element === 'filter-range') {
			let pool = resultsFor(active.filter((a) => a.f.id !== f.id)).map((id) => parseFloat(ds.posts[id].meta?.[f.metaKey]));
			if (!pool.length) pool = ds.order.map((id) => parseFloat(ds.posts[id].meta?.[f.metaKey]));
			const lo = Math.min(...pool); let hi = Math.max(...pool); if (hi === lo) hi = lo + 1;
			out.opts[f.id + '_range'] = [lo, hi, selected[f.id] ? Math.max(selected[f.id][0], lo) : lo];
			continue;
		}
		const base = group(idx, f.id, ds.order);
		const multi = f.element === 'filter-checkbox';
		const opts = {};
		const nonSelfOthers = active.filter((a) => a.f.id !== f.id);
		let src, notFoundZero = false;
		// Bricks counts an active filter's options over the OTHER filters that give it query variables; a WordPress-field date filter
		// becomes a date_query, which that code ignores — with nothing left, every count is 0.
		if (self && others.length > 0) {
			const contributing = others.filter((a) => !(a.f.element === 'filter-datepicker' && a.f.source === 'wpField'));
			src = contributing.length ? group(idx, f.id, resultsFor(contributing), ds.order) : {}; notFoundZero = true;
		}
		else if (!self && others.length > 0) { src = group(idx, f.id, all, ds.order); notFoundZero = true; }
		else if (multi) { src = group(idx, f.id, all, ds.order); notFoundZero = f.logic === 'AND'; }
		else if (!self) { src = group(idx, f.id, all, ds.order); }
		for (const v of Object.keys(base)) {
			let c = base[v];
			if (src) { if (v in src) c = src[v]; else if (notFoundZero) c = 0; }
			if ((all.length === 0 || pastEnd) && !self) c = 0;
			opts[v] = c;
		}
		out.opts[f.id] = opts;
	}
	return out;
}
