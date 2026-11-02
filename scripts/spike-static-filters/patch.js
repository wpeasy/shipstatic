// Spike: rebuild the pieces of a Bricks query_result reply that are markup, from the engine's numbers.
// DOM-agnostic: pass in a `document` (the browser's, or linkedom's in tests).
import { run } from './engine.js';

const phpFloat = (n) => String(Number(Number(n).toPrecision(14)));
const parse = (doc, html) => { const t = doc.createElement('template'); t.innerHTML = html.trim(); return t.content.firstElementChild; };

function patchCheckbox(el, f, sel, opts) {
	const chosen = new Set(Array.isArray(sel) ? sel : sel ? [sel] : []);
	el.querySelectorAll('li').forEach((li) => {
		const input = li.querySelector('input'); if (!input) return;
		const v = input.getAttribute('value'); const n = opts[v];
		const on = chosen.has(v); const off = n === 0;
		const cls = (node, name, flag) => { if (!node) return; node.classList.toggle(name, flag); if (!node.getAttribute('class')) node.removeAttribute('class'); };
		cls(li, 'brx-option-active', on); cls(li.querySelector('label'), 'brx-option-active', on); cls(li.querySelector('.brx-option-text'), 'brx-option-active', on);
		cls(li.querySelector('label'), 'brx-option-disabled', off);
		if (on) input.setAttribute('checked', 'checked'); else input.removeAttribute('checked');
		if (off && !on) input.setAttribute('disabled', 'disabled'); else input.removeAttribute('disabled');
		const c = li.querySelector('.brx-option-count'); if (c && n !== undefined) c.textContent = `(${n})`;
	});
}

// Radio buttons share the checkbox list markup; "All" is the radio with an empty value and is active when nothing else is.
function patchRadio(el, f, sel, opts) {
	const chosen = sel == null || sel === '' ? '' : String(Array.isArray(sel) ? sel[0] : sel);
	const keepEmpty = f.action !== 'filter'; // a sort/per-page radio prints `<label class>`, a filter prints no attribute
	const cls = (node, name, flag) => { if (!node) return; node.classList.toggle(name, flag); if (!node.getAttribute('class') && !(keepEmpty && node.tagName === 'LABEL')) node.removeAttribute('class'); else if (!node.getAttribute('class')) node.setAttribute('class', ''); };
	el.querySelectorAll('li').forEach((li) => {
		const input = li.querySelector('input'); if (!input) return;
		const v = input.getAttribute('value') ?? ''; const on = v === chosen; const n = v === '' ? undefined : opts?.[v];
		const off = n === 0; const label = li.querySelector('label');
		cls(li, 'brx-option-active', on); cls(label, 'brx-option-active', on); cls(li.querySelector('.brx-option-text'), 'brx-option-active', on);
		cls(label, 'brx-option-disabled', off);
		if (on) input.setAttribute('checked', 'checked'); else input.removeAttribute('checked');
		if (off) input.setAttribute('disabled', 'disabled'); else input.removeAttribute('disabled'); // unlike a checkbox, a selected radio is disabled too
		const c = li.querySelector('.brx-option-count'); if (c && n !== undefined) c.textContent = `(${n})`;
	});
}

// <select>: keep the selected attribute right and, for a filter, the "(count)" Bricks adds to each option's text.
function patchSelect(el, sel, opts) {
	const chosen = sel == null ? '' : String(sel);
	el.querySelectorAll('option').forEach((o) => {
		const v = o.getAttribute('value') ?? '';
		if (v === chosen) o.setAttribute('selected', 'selected'); else o.removeAttribute('selected');
		if (opts && v !== '' && opts[v] !== undefined) {
			o.textContent = o.textContent.replace(/\(\d+\)\s*$/, `(${opts[v]})`);
			const off = opts[v] === 0;
			o.classList.toggle('brx-option-disabled', off);
			if (off) o.setAttribute('disabled', ''); else o.removeAttribute('disabled');
		}
	});
}

function patchRange(el, f, sel, range) {
	const [min, max, lo0] = range; const [lo, hi] = sel ? [Math.max(sel[0], min), Math.min(sel[1], max)] : [lo0, max];
	el.querySelectorAll('input.min, input.max').forEach((i) => { i.setAttribute('min', phpFloat(min)); i.setAttribute('max', phpFloat(max)); });
	el.querySelectorAll('input.min').forEach((i) => i.setAttribute('value', phpFloat(lo)));
	el.querySelectorAll('input.max').forEach((i) => i.setAttribute('value', phpFloat(hi)));
	const span = max - min || 1; const track = el.querySelector('.slider-track');
	if (track) track.setAttribute('style', `width:${phpFloat(((hi - lo) / span) * 100)}%; left:${phpFloat(((lo - min) / span) * 100)}%;${hi <= lo ? ' visibility: hidden;' : ''}`);
	const lower = el.querySelector('.value-wrap .lower .value'), upper = el.querySelector('.value-wrap .upper .value');
	if (lower) lower.textContent = phpFloat(lo); if (upper) upper.textContent = phpFloat(hi);
}

const esc = (t) => String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

// The chip text Bricks shows for one selected value.
function chips(ds, f, v) {
	if (f.action === 'sort') return [[v, f.sorts?.[v]?.label ?? v]];
	if (f.element === 'filter-range') return [[String(v[0]), ` ${phpFloat(v[0])} -  ${phpFloat(v[1])}`]];
	if (f.element === 'filter-datepicker') return [[String(v), String(v).split(',').join(' - ')]];
	if (f.action === 'per_page') return [[String(v), String(v)]];
	if (f.element === 'filter-search') return [[v, v]];
	const label = (val) => f.labels?.[val] ?? val;
	return (Array.isArray(v) ? v : [v]).map((x) => [x, label(x)]);
}

function patchActive(ds, doc, el, sel) {
	const ul = el; ul.innerHTML = '';
	for (const [fid, v] of Object.entries(sel)) {
		const f = ds.filters[fid]; if (!f || v == null || v === '' || (Array.isArray(v) && !v.length)) continue;
		if (f.action === 'per_page' && !String(f.perPageOptions ?? '10, 20, 50, 100').split(',').map((x) => x.trim()).includes(String(v))) continue; // an unknown page size is ignored
		for (const [val, text] of chips(ds, f, v)) {
			const li = doc.createElement('li');
			li.innerHTML = `<button aria-label="Clear filter: ${esc(text)}" class="bricks-button" data-filter-id="${fid}" data-filter-value="${esc(val)}" data-filter-url-param="${esc(f.nice)}">${esc(text)}</button>`;
			ul.appendChild(li);
		}
	}
}

function patchPagination(ds, doc, el, page, total) {
	const base = ds.baseUrl; const href = (n) => (n <= 1 ? base : `${base}page/${n}/`);
	const rows = [];
	if (page > 1) rows.push(`<li><a class="prev page-numbers" aria-label="Previous page" href="${href(page - 1)}">&larr;</a></li>`);
	let dots = false;
	for (let n = 1; n <= total; n++) {
		if (n === page) { rows.push(`<li><span aria-current="page" class="page-numbers current">${n}</span></li>`); dots = true; }
		else if (n <= 1 || (n >= page - 2 && n <= page + 2) || n > total - 1) { rows.push(`<li><a class="page-numbers" href="${href(n)}">${n}</a></li>`); dots = true; }
		else if (dots) { rows.push('<li><span class="page-numbers dots">&hellip;</span></li>'); dots = false; }
	}
	if (page < total) rows.push(`<li><a class="next page-numbers" aria-label="Next page" href="${href(page + 1)}">&rarr;</a></li>`);
	const nav = el.querySelector('.bricks-pagination'); if (!nav) return;
	if (total > 1) el.removeAttribute('style'); else el.setAttribute('style', 'display: none;');
	nav.innerHTML = total > 1 ? `<ul class='page-numbers'>\n\t${rows.join('\n\t')}\n</ul>\n` : '';
}

function patchDatepicker(el, sel) {
	const input = el.querySelector('input'); if (!input) return;
	const o = JSON.parse(input.getAttribute('data-bricks-datepicker-options') || '{}');
	if (sel) o.defaultDate = String(sel).split(','); else delete o.defaultDate;
	input.setAttribute('data-bricks-datepicker-options', JSON.stringify(o));
}

function patchSort(el, sel) {
	el.querySelectorAll('option').forEach((o) => { if (o.getAttribute('value') === (sel ?? '')) o.setAttribute('selected', 'selected'); else o.removeAttribute('selected'); });
}

export function buildReply(ds, idx, doc, req) {
	const sel = req.selectedFilters || {};
	if (req.infinitePage > 1) sel._infinite = req.infinitePage;
	const r = run(ds, idx, sel);
	const page = Math.max(1, parseInt(sel._infinite ?? (ds.paginationId ? sel[ds.paginationId] : 1) ?? 1, 10));
	const uq = JSON.parse(JSON.stringify(ds.updatedQuery));
	Object.assign(uq, { count: r.count, max_num_pages: r.pages, start: r.start, end: r.end }); uq.query_vars.paged = page; if (r.perPage) uq.query_vars.posts_per_page = r.perPage;
	const filters = {};
	for (const id of req.filters || []) {
		const f = ds.filters[id]; const tpl = ds.filterHtml[id]; if (!tpl) continue;
		const el = parse(doc, tpl);
		if (f?.element === 'filter-checkbox' && f.action === 'filter') patchCheckbox(el, f, sel[id], r.opts[id] || {});
		else if (f?.element === 'filter-radio') patchRadio(el, f, sel[id], f.action === 'filter' ? r.opts[id] || {} : null);
		else if (f?.element === 'filter-select') patchSelect(el, sel[id], f.action === 'filter' ? r.opts[id] || {} : null);
		else if (f?.element === 'filter-range') patchRange(el, f, sel[id], r.opts[id + '_range']);
		else if (f?.element === 'filter-datepicker') patchDatepicker(el, sel[id]);
		else if (f?.element === 'filter-search') el.querySelector('input').setAttribute('value', sel[id] ?? '');
		else if (f?.element === 'filter-active-filters') patchActive(ds, doc, el, sel);
		else if (/brx-ajax-pagination/.test(tpl)) patchPagination(ds, doc, el, page, r.pages);
		filters[id] = el.outerHTML;
	}
	return { html: r.ids.map((i) => ds.items[i]).join('') + ds.trail, styles: '', popups: [], updated_filters: filters, updated_query: uq, parsed_af_tags: [] };
}
