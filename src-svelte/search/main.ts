/**
 * ShipStatic site search (the PHP side is Frontend\Search).
 *
 * In the EXPORTED static site, every form with an `s` field (WordPress / Bricks
 * search boxes, the ShipStatic Search element) is upgraded to search this
 * destination's search-index.json in the browser, with results listed under
 * the box as you type. On the live WordPress site (when that setting is on)
 * only the forms matching `selector` — the ShipStatic Search element — are,
 * searching the REST index, and Enter with nothing to open falls through to
 * normal WordPress search. Options come
 * from the form's data-bs-* attributes (set by Search\SearchForm); the look
 * from search.css. Self-contained on purpose — no imports, no framework — so it
 * works as one static file on any host. If the index can't load, the form is
 * left alone.
 */

interface Page {
  u: string;
  t: string;
  h: string[];
  x: string;
}

interface Prepared {
  page: Page;
  title: string;
  heads: string;
  text: string;
}

interface Config {
  index: string;
  /** Forms to upgrade (default: every form with an `s` field). */
  selector?: string;
  /** Live WordPress site: a submit with no result to open goes to /?s=. */
  live?: boolean;
  i18n: { noResults: string; results: string; oneResult: string; label: string };
}

/** Per-form options (data-bs-* attributes; defaults for plain search forms). */
interface Options {
  max: number;
  min: number;
  snippets: boolean;
  snippetLen: number;
  empty: string;
}

const cfg = (window as unknown as { shipStaticSearch?: Config }).shipStaticSearch;

let indexPromise: Promise<Prepared[] | null> | null = null;
/** The index couldn't be loaded: forms behave as if never upgraded. */
let indexFailed = false;
let uid = 0;

/** Lower-case and strip accents ("Café" → "cafe"). */
function fold(s: string): string {
  return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/** fold(), plus where each folded character came from in the original. */
function foldWithMap(s: string): { folded: string; map: number[] } {
  let folded = '';
  const map: number[] = [];
  for (let i = 0; i < s.length; i++) {
    const f = fold(s[i]);
    for (let j = 0; j < f.length; j++) {
      folded += f[j];
      map.push(i);
    }
  }
  return { folded, map };
}

function loadIndex(): Promise<Prepared[] | null> {
  if (!indexPromise) {
    // no-cache = revalidate: a fresh sync shows up despite host caching
    // (GitHub Pages sets max-age=600); an unchanged index costs a 304.
    indexPromise = fetch(cfg!.index, { cache: 'no-cache' })
      .then((r) => (r.ok ? r.json() : null))
      .then((data: { pages?: Page[] } | null) => {
        if (!data || !Array.isArray(data.pages)) return null;
        return data.pages.map((page) => ({
          page,
          title: fold(page.t || ''),
          heads: fold((page.h || []).join(' ')),
          text: fold(page.x || ''),
        }));
      })
      .catch(() => null)
      .then((pages) => {
        if (!pages) indexFailed = true;
        return pages;
      });
  }
  return indexPromise;
}

function search(pages: Prepared[], query: string, max: number): Page[] {
  const q = fold(query).trim();
  const words = q.split(/\s+/).filter((w) => w.length > 0);
  if (words.length === 0) return [];

  const scored: { page: Page; score: number }[] = [];
  for (let i = 0; i < pages.length; i++) {
    const p = pages[i];
    let score = 0;
    let all = true;
    for (let w = 0; w < words.length; w++) {
      const word = words[w];
      let s = 0;
      if (p.title.includes(word)) s += 10;
      if (p.heads.includes(word)) s += 5;
      if (p.text.includes(word)) s += 1;
      if (s === 0) {
        all = false;
        break;
      }
      score += s;
    }
    if (!all) continue;
    if (words.length > 1) {
      if (p.title.includes(q)) score += 8;
      else if (p.text.includes(q)) score += 3;
    }
    scored.push({ page: p.page, score });
  }

  scored.sort((a, b) => b.score - a.score);
  return scored.slice(0, max).map((s) => s.page);
}

/** Append `text` to `el`, wrapping every query-word match in <mark>. */
function appendHighlighted(el: HTMLElement, text: string, words: string[]): void {
  const { folded, map } = foldWithMap(text);
  const ranges: [number, number][] = [];
  for (let w = 0; w < words.length; w++) {
    let from = 0;
    let at: number;
    while (words[w] && (at = folded.indexOf(words[w], from)) !== -1) {
      const start = map[at];
      const end = map[at + words[w].length - 1] + 1;
      ranges.push([start, end]);
      from = at + words[w].length;
    }
  }
  ranges.sort((a, b) => a[0] - b[0]);

  let pos = 0;
  for (let r = 0; r < ranges.length; r++) {
    const [start, end] = ranges[r];
    if (start < pos) continue; // overlapping match
    if (start > pos) el.appendChild(document.createTextNode(text.slice(pos, start)));
    const mark = document.createElement('mark');
    mark.textContent = text.slice(start, end);
    el.appendChild(mark);
    pos = end;
  }
  if (pos < text.length) el.appendChild(document.createTextNode(text.slice(pos)));
}

/** About `len` chars of the page text around the first match (or its start). */
function snippet(page: Page, words: string[], len: number): string {
  const text = page.x || '';
  if (text.length <= len) return text;
  const { folded, map } = foldWithMap(text);
  let at = -1;
  for (let w = 0; w < words.length && at === -1; w++) at = folded.indexOf(words[w]);
  if (at === -1) return text.slice(0, len).trimEnd() + '…';
  const centre = map[at];
  const start = Math.max(0, centre - Math.round(len / 3));
  const end = Math.min(text.length, start + len);
  return (start > 0 ? '…' : '') + text.slice(start, end).trim() + (end < text.length ? '…' : '');
}

function readOptions(form: HTMLFormElement): Options {
  const d = form.dataset;
  const num = (v: string | undefined, def: number, lo: number, hi: number): number => {
    const n = parseInt(v || '', 10);
    return isNaN(n) ? def : Math.min(hi, Math.max(lo, n));
  };
  return {
    max: num(d.bsMax, 8, 1, 20),
    min: num(d.bsMin, 1, 1, 5),
    snippets: d.bsSnippets !== '0',
    snippetLen: num(d.bsSnippetLen, 160, 40, 400),
    empty: d.bsEmpty || cfg!.i18n.noResults,
  };
}

function enhance(form: HTMLFormElement, input: HTMLInputElement): void {
  const opts = readOptions(form);
  const id = 'bs-search-' + ++uid;
  const list = document.createElement('ul');
  list.className = 'bs-search';
  list.id = id;
  list.setAttribute('role', 'listbox');
  list.setAttribute('aria-label', cfg!.i18n.label);
  list.hidden = true;

  const live = document.createElement('span');
  live.className = 'bs-search-sr';
  live.setAttribute('aria-live', 'polite');

  form.classList.add('bs-search-host');
  form.appendChild(list);
  form.appendChild(live);

  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-controls', id);
  input.setAttribute('aria-expanded', 'false');
  input.setAttribute('autocomplete', 'off');

  let results: Page[] = [];
  let active = -1;
  let timer = 0;

  function close(): void {
    list.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    active = -1;
  }

  function setActive(i: number): void {
    const items = list.querySelectorAll('[role="option"]');
    for (let k = 0; k < items.length; k++) {
      items[k].setAttribute('aria-selected', k === i ? 'true' : 'false');
      items[k].classList.toggle('bs-search__item--active', k === i);
    }
    active = i;
    if (i >= 0 && items[i]) {
      input.setAttribute('aria-activedescendant', items[i].id);
      (items[i] as HTMLElement).scrollIntoView({ block: 'nearest' });
    } else {
      input.removeAttribute('aria-activedescendant');
    }
  }

  function render(query: string): void {
    const words = fold(query).trim().split(/\s+/).filter((w) => w.length > 0);
    list.textContent = '';
    active = -1;
    if (words.length === 0 || query.trim().length < opts.min) {
      close();
      live.textContent = '';
      return;
    }
    if (results.length === 0) {
      const li = document.createElement('li');
      li.className = 'bs-search__empty';
      li.textContent = opts.empty.replace('%s', query.trim());
      list.appendChild(li);
      live.textContent = li.textContent;
    } else {
      for (let i = 0; i < results.length; i++) {
        const page = results[i];
        const li = document.createElement('li');
        li.className = 'bs-search__item';
        li.id = id + '-' + i;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        const a = document.createElement('a');
        a.href = page.u;
        a.tabIndex = -1;
        const title = document.createElement('span');
        title.className = 'bs-search__title';
        appendHighlighted(title, page.t || page.u, words);
        a.appendChild(title);
        if (opts.snippets) {
          const snip = document.createElement('span');
          snip.className = 'bs-search__snippet';
          appendHighlighted(snip, snippet(page, words, opts.snippetLen), words);
          a.appendChild(snip);
        }
        li.appendChild(a);
        list.appendChild(li);
      }
      live.textContent = results.length === 1 ? cfg!.i18n.oneResult : cfg!.i18n.results.replace('%d', String(results.length));
    }
    list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    // Open towards the left when a box near the right edge would overflow.
    list.style.left = '';
    list.style.right = '';
    if (list.getBoundingClientRect().right > window.innerWidth - 8) {
      list.style.left = 'auto';
      list.style.right = '0';
    }
  }

  function run(): void {
    const query = input.value;
    loadIndex().then((pages) => {
      if (!pages || input.value !== query) return;
      results = query.trim().length < opts.min ? [] : search(pages, query, opts.max);
      render(query);
    });
  }

  input.addEventListener('focus', () => {
    loadIndex();
    if (input.value.trim() !== '') run();
  });
  input.addEventListener('input', () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(run, 120);
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' && results.length > 0) {
      e.preventDefault();
      if (list.hidden) run();
      setActive(active + 1 >= results.length ? 0 : active + 1);
    } else if (e.key === 'ArrowUp' && results.length > 0) {
      e.preventDefault();
      setActive(active - 1 < 0 ? results.length - 1 : active - 1);
    } else if (e.key === 'Escape') {
      close();
    }
  });

  // Enter opens the highlighted result, or the best one. With nothing to
  // open, the static site has no server to answer /?s= (so stay), while the
  // live WordPress site does. No index at all: submit as normal.
  form.addEventListener('submit', (e) => {
    if (indexFailed) return;
    const target = results[active >= 0 ? active : 0];
    if (target) {
      e.preventDefault();
      window.location.href = target.u;
    } else if (!cfg!.live) {
      e.preventDefault();
      run();
    }
  });

  // Close on a press outside the form (mousedown: before focus moves).
  document.addEventListener('mousedown', (e) => {
    if (!form.contains(e.target as Node)) close();
  });
}

function init(): void {
  if (!cfg || !cfg.index) return;
  const forms = document.querySelectorAll(cfg.selector || 'form');
  for (let i = 0; i < forms.length; i++) {
    const form = forms[i] as HTMLFormElement;
    const input = form.querySelector('input[name="s"]') as HTMLInputElement | null;
    if (!input || form.dataset.bsSearch) continue;
    form.dataset.bsSearch = '1';
    enhance(form, input);
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
