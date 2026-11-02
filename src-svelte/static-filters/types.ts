/** What the PHP builder (Bricks\StaticFiltersBuilder) writes for one Bricks query loop. */

export interface Post {
  title: string;
  date: string;
  content: string;
  excerpt: string;
  author: number;
  meta?: Record<string, string>;
}

/** One Bricks index row: object id, value, display text, term id, parent term id. */
export type Row = [number, string, string, number, number];

export interface SortDef {
  label: string;
  src: string;
  key: string;
  dir: string;
}

export interface FilterDef {
  id: string;
  /** Bricks element name, e.g. `filter-checkbox`. */
  element: string;
  /** `filter`, `sort` or `per_page`. */
  action: string;
  source: string;
  nice: string;
  logic: string;
  metaKey: string;
  range: boolean;
  operator: string;
  perPageOptions: string | null;
  sorts?: Record<string, SortDef>;
  /** Filled in by prepare(): value => display text. */
  labels?: Record<string, string>;
}

export interface Dataset {
  queryId: string;
  perPage: number;
  /** Object ids in the loop's own order. */
  order: number[];
  posts: Record<string, Post>;
  filters: Record<string, FilterDef>;
  rows: Record<string, Row[]>;
  /** Object id => the loop item's markup. */
  items: Record<string, string>;
  /** Filter element id => its markup with nothing selected. */
  filterHtml: Record<string, string>;
  paginationId: string | null;
  /** Bricks' `updated_query` for the unfiltered first page. */
  updatedQuery: { query_vars: Record<string, unknown>; [key: string]: unknown };
  /** The page's own address (pagination links are built from it). */
  baseUrl: string;
}

/** `selectedFilters` as Bricks sends it, plus `_infinite` (the infinitePage parameter). */
export type Selected = Record<string, unknown> & { _infinite?: number };

export interface Result {
  ids: number[];
  count: number;
  pages: number;
  start: number;
  end: number;
  perPage: number;
  /** filter id => option value => count; `<id>_range` => [min, max, lowHandle]. */
  opts: Record<string, any>;
}

export interface Reply {
  html: string;
  styles: string;
  popups: unknown[];
  updated_filters: Record<string, string>;
  updated_query: Record<string, unknown>;
  parsed_af_tags: unknown[];
}
