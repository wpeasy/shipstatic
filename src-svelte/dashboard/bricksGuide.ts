/**
 * The Bricks guide's decision logic: which of the two Bricks settings its answers lead to, and why.
 * Pure (no DOM, no state) so the guide shows exactly what it will apply, and so it can be tested on its own
 * (scripts/static-filters/guide.test.js).
 */
import type { BricksGuideInfo } from '../shared/types.ts';

export type Answer = 'yes' | 'no' | 'unsure';
export type Freshness = 'live' | 'snapshot';
export type Independence = 'needed' | 'notNeeded' | 'unsure';

export interface Answers {
  forms: Answer;
  filters: Answer;
  /** Must filters keep working without WordPress reachable? */
  independence: Independence;
  freshness: Freshness;
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

/** Destinations that have no WordPress to ask (or sit on another domain). */
const needsStaticFilters = (info: BricksGuideInfo): boolean => info.destinations.some((d) => d.crossDomain || d.transport === 'cloudflare' || d.transport === 'github');

/** What the page really uses, when the person isn't sure, from the last Process. */
function resolve(answer: Answer, foundCount: number, rendered: boolean): boolean {
  if (answer === 'yes') return true;
  if (answer === 'no') return false;
  return rendered && foundCount > 0; // "not sure": trust the scan, else assume nothing
}

export function recommend(answers: Answers, info: BricksGuideInfo): Recommendation {
  const hasForms = resolve(answers.forms, info.found.formPages, info.found.rendered);
  const hasFilters = resolve(answers.filters, info.found.filterPages, info.found.rendered);
  const reasons: string[] = [];

  if (!hasForms && !hasFilters) {
    reasons.push('bricksGuideWhyNone');
    return { relay: false, staticFilters: false, hasForms, hasFilters, reasons };
  }

  if (hasForms) reasons.push('bricksGuideWhyForms');

  // Filters answered in the browser: wanted without WordPress, fine as a snapshot, or forced by where the site goes.
  const wantStatic = hasFilters && (answers.independence === 'needed' || answers.freshness === 'snapshot' || (answers.independence === 'unsure' && needsStaticFilters(info)));
  if (hasFilters) reasons.push(wantStatic ? 'bricksGuideWhyStatic' : 'bricksGuideWhyWp');
  if (hasFilters && info.woo) reasons.push('bricksGuideWoo');

  // The connection to WordPress stays on for forms, and as the fallback for a loop that can't run statically —
  // unless the person said WordPress will not be reachable, when it could not answer anyway.
  const relay = hasForms || (hasFilters && (!wantStatic || answers.independence !== 'needed'));

  return { relay, staticFilters: wantStatic, hasForms, hasFilters, reasons };
}
