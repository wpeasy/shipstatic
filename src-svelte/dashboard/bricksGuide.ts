/**
 * The Bricks guide's decision logic: which of the two Bricks settings its answers lead to, and why.
 * Pure (no DOM, no state) so the guide shows exactly what it will apply, and so it can be tested on its own
 * (scripts/static-filters/guide.test.js).
 *
 * Simple assumes a locked-down site: filters run in the browser from data files, forms use the one route Lockdown
 * lets through. Advanced asks, and lets filters keep using WordPress.
 */
import type { BricksGuideInfo } from '../shared/types.ts';

export type Mode = 'simple' | 'advanced';
export type Answer = 'yes' | 'no' | 'unsure';
/** Advanced: where filters run. */
export type Where = 'static' | 'wordpress';

export interface Answers {
  mode: Mode;
  /** Advanced only; Simple reads the last Process. */
  forms: Answer;
  filters: Answer;
  where: Where;
}

/** What the last Process learned about each query loop (the relay is the fallback for one that can't run in the page). */
export interface Loops {
  known: boolean;
  /** Loops that keep using WordPress. */
  fallback: number;
}

/** Each reason is an i18n key the guide shows under the summary. */
export interface Recommendation {
  /** "Allow Bricks forms and filters" (the relay). */
  relay: boolean;
  /** "Run Bricks filters without WordPress". */
  staticFilters: boolean;
  hasForms: boolean;
  hasFilters: boolean;
  reasons: string[];
}

/**
 * What the page really uses. "Not sure" trusts the last Process; with no Process yet, Advanced assumes nothing
 * and Simple assumes both (it is the setup that is safe either way).
 */
function resolve(answer: Answer, foundCount: number, rendered: boolean, assumeWhenUnknown: boolean): boolean {
  if (answer === 'yes') return true;
  if (answer === 'no') return false;
  return rendered ? foundCount > 0 : assumeWhenUnknown;
}

export function recommend(answers: Answers, info: BricksGuideInfo, loops: Loops = { known: false, fallback: 0 }): Recommendation {
  const simple = answers.mode === 'simple';
  const rendered = info.found.rendered;
  const hasForms = resolve(simple ? 'unsure' : answers.forms, info.found.formPages, rendered, simple);
  const hasFilters = resolve(simple ? 'unsure' : answers.filters, info.found.filterPages, rendered, simple);
  const reasons: string[] = [];

  if (!hasForms && !hasFilters) {
    reasons.push('bricksGuideWhyNone');
    return { relay: false, staticFilters: false, hasForms, hasFilters, reasons };
  }

  if (hasForms) reasons.push('bricksGuideWhyForms');

  // Simple always builds the data files (harmless with no filters yet, and a filter added later just works).
  const wantStatic = simple ? true : hasFilters && answers.where === 'static';
  if (hasFilters) reasons.push(wantStatic ? 'bricksGuideWhyStatic' : 'bricksGuideWhyWp');

  // The connection to WordPress stays on for forms and for filters that ask it, and as the fallback for a loop that
  // can't run in the page — or while that isn't known yet (no Process with the option on).
  const relay = hasForms || (hasFilters && (!wantStatic || !loops.known || loops.fallback > 0));

  return { relay, staticFilters: wantStatic, hasForms, hasFilters, reasons };
}
