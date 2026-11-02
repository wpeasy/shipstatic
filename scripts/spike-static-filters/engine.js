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

export function matcher(ds, idx, f, value) {
	switch (f.element) {
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

const isActive = (f, v) => v != null && !(Array.isArray(v) && v.length === 0) && v !== '' && f.action !== 'sort';

function group(idx, fid, ids) {
	const c = {}; const d = idx.filterRows[fid].direct;
	for (const id of ids) for (const v of d[id] ?? []) c[v] = (c[v] || 0) + 1;
	return c;
}

function sortIds(ds, ids, sortFilter, sortVal) {
	const s = sortFilter?.sorts?.[sortVal]; if (!s) return ids;
	const dir = s.dir === 'DESC' ? -1 : 1; const arr = ids.map((id, i) => [id, i]);
	const keyOf = (id) => s.src === 'title' ? fold(ds.posts[id].title) : s.src === 'post_date' ? ds.posts[id].date : s.src === 'meta_value_num' ? parseFloat(ds.posts[id].meta?.[s.key]) : fold(ds.posts[id].meta?.[s.key]);
	arr.sort((a, b) => { const ka = keyOf(a[0]), kb = keyOf(b[0]); return ka < kb ? -dir : ka > kb ? dir : 0; });
	return arr.map((x) => x[0]);
}

export function run(ds, idx, selected) {
	const fs = Object.values(ds.filters);
	const active = fs.filter((f) => isActive(f, selected[f.id])).map((f) => ({ f, m: matcher(ds, idx, f, selected[f.id]) }));
	const resultsFor = (list) => ds.order.filter((id) => list.every((a) => a.m(id)));
	const all = resultsFor(active);
	const sortF = fs.find((f) => f.action === 'sort'); const sortV = sortF ? selected[sortF.id] : undefined;
	const ordered = sortV ? sortIds(ds, all, sortF, sortV) : all;
	const page = Math.max(1, parseInt(selected.pag001 || 1, 10));
	const pages = Math.ceil(all.length / ds.perPage) || 0;
	const slice = ordered.slice((page - 1) * ds.perPage, page * ds.perPage);
	const out = { ids: slice, count: all.length, pages, start: all.length ? (page - 1) * ds.perPage + 1 : 0, end: Math.min(page * ds.perPage, all.length), opts: {} };
	for (const f of fs) {
		if (f.action === 'sort' || f.element === 'filter-search') continue;
		const self = active.find((a) => a.f.id === f.id);
		const others = active.filter((a) => a.f.id !== f.id || (f.element === 'filter-checkbox' && f.logic === 'AND' && a.f.id === f.id));
		if (f.element === 'filter-range') {
			let pool = resultsFor(active.filter((a) => a.f.id !== f.id)).map((id) => parseFloat(ds.posts[id].meta?.[f.metaKey]));
			if (!pool.length) pool = ds.order.map((id) => parseFloat(ds.posts[id].meta?.[f.metaKey]));
			out.opts[f.id + '_range'] = [Math.min(...pool), Math.max(...pool), selected[f.id] ? selected[f.id][0] : Math.min(...pool)];
			continue;
		}
		const base = group(idx, f.id, ds.order);
		const multi = f.element === 'filter-checkbox';
		const opts = {};
		const nonSelfOthers = active.filter((a) => a.f.id !== f.id);
		let src, notFoundZero = false;
		if (self && others.length > 0) { src = group(idx, f.id, resultsFor(others)); notFoundZero = true; }
		else if (!self && others.length > 0) { src = group(idx, f.id, all); notFoundZero = true; }
		else if (multi) { src = group(idx, f.id, all); notFoundZero = f.logic === 'AND'; }
		else if (!self) { src = group(idx, f.id, all); }
		for (const v of Object.keys(base)) {
			let c = base[v];
			if (src) { if (v in src) c = src[v]; else if (notFoundZero) c = 0; }
			if (all.length === 0 && !self) c = 0;
			opts[v] = c;
		}
		out.opts[f.id] = opts;
	}
	return out;
}
