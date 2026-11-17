/**
 * The change / cancel RULE of catering orders, stated once on Min side (GH #1423) instead of a time per order.
 *
 * It is built from the same fields HospitalityOrderGuard reads (see its header, and hospitalityActionWindow
 * in hospitality-deadline.ts), per OFFER (bb_hospitality):
 *   C = the order cutoff            order_by_time_*                         (per offer)
 *   X = the cancellation deadline   resource_cancellation_deadline_*        (of the offer's kitchen resource)
 *   change closes at the EARLIER of C and X, cancel closes at X, and nothing is open after the serving time.
 * The EARLIER deadline is the one counted back the LONGER lead, so the lead that closes a change is the
 * longer of the two. A lead that is not configured (null, 0, an unknown unit) closes nothing.
 *
 * This is EXPLANATION only. Which buttons are offered is decided by the server's `actions` and nothing here
 * ever shows or hides one: where the two could disagree the server wins.
 *
 * Pure (no React, no DOM, no translation), so tests/hospitality-deadline/run_deadline_rule.mts can load it.
 * The wording lives in the lang files; this module only says WHICH sentence and with which leads.
 */
import {isWorkingDaysMode} from './hospitality-deadline';

export type LeadUnit = 'hours' | 'days' | 'weeks';

/** «24 hours», «2 days»: how long before the serving time an action closes. */
export interface Lead {
    value: number;
    unit: LeadUnit;
}

/** The fields of an offer (IHospitality) the rule is built from. */
export interface DeadlineConfig {
    id: number;
    order_by_time_value: number | null;
    order_by_time_unit: string | null;
    resource_cancellation_deadline_value: number | null;
    resource_cancellation_deadline_unit: string | null;
    /** Decoded open weekdays (ISO 1=Mon..7=Sun); null / empty / all seven = every day counts. */
    open_days_list?: number[] | null;
}

export interface CateringDeadlineRule {
    /** How long before serving a change closes; null = not before the serving time itself. */
    change: Lead | null;
    /** How long before serving a cancellation closes; null = not before the serving time itself. */
    cancel: Lead | null;
    /** Days or hours are counted over the kitchen's open days only (the server rolls over closed days). */
    workingDays: boolean;
}

const HOURS_PER_UNIT: Record<LeadUnit, number> = {hours: 1, days: 24, weeks: 24 * 7};

/** The same reading of «value + unit» as computeHospitalityDeadline: no value, 0 or an unknown unit = none. */
export function leadOf(value: number | null | undefined, unit: string | null | undefined): Lead | null {
    if (!value || value <= 0 || !unit) return null;
    const normalised = unit.toLowerCase();
    if (normalised !== 'hours' && normalised !== 'days' && normalised !== 'weeks') return null;
    return {value, unit: normalised};
}

/** Only to tell which of two leads is the longer; a day is 24 hours here, the server counts calendar days. */
const nominalHours = (lead: Lead): number => lead.value * HOURS_PER_UNIT[lead.unit];

/**
 * On closed days the three units count back differently: hours skip the closed hours, days count open days
 * only, weeks ignore the open days. So of two leads of the same nominal length the one in the unit that rolls
 * furthest back closes FIRST (measured against hospitalityActionWindow: 1 day and 24 hours on a Saturday in a
 * Monday-to-Friday kitchen close at different instants, the 24 hours earlier).
 */
const ROLL_RANK: Record<LeadUnit, number> = {hours: 3, days: 2, weeks: 1};

/** Does a close first (or at the same time as) b? */
const closesNoLaterThan = (a: Lead, b: Lead): boolean =>
    nominalHours(a) !== nominalHours(b) ? nominalHours(a) > nominalHours(b) : ROLL_RANK[a.unit] >= ROLL_RANK[b.unit];

export const sameLead = (a: Lead | null, b: Lead | null): boolean =>
    a === null || b === null ? a === b : a.value === b.value && a.unit === b.unit;

export function deriveRule(config: DeadlineConfig): CateringDeadlineRule {
    const cutoff = leadOf(config.order_by_time_value, config.order_by_time_unit);
    const cancel = leadOf(config.resource_cancellation_deadline_value, config.resource_cancellation_deadline_unit);

    // A change needs BOTH deadlines open, so it closes with the earlier one = the longer lead.
    const change = cutoff !== null && (cancel === null || closesNoLaterThan(cutoff, cancel)) ? cutoff : cancel;

    // 'weeks' ignores the open days (computeHospitalityDeadline); hours and days do not.
    const countsOpenDays = [change, cancel].some(lead => lead !== null && lead.unit !== 'weeks');
    return {change, cancel, workingDays: countsOpenDays && isWorkingDaysMode(config.open_days_list)};
}

export type RuleSentence =
    /** Change and cancel close at the same lead. */
    | {kind: 'both'; lead: Lead}
    /** Change closes `change` before serving, cancel `cancel` before: change is the earlier. */
    | {kind: 'split'; change: Lead; cancel: Lead}
    /** Change closes `change` before serving; cancel stays open until the serving starts. */
    | {kind: 'split_open'; change: Lead}
    /** Neither closes before the serving time. */
    | {kind: 'none'};

export function sentenceOf(rule: CateringDeadlineRule): RuleSentence {
    const {change, cancel} = rule;
    if (change === null && cancel === null) return {kind: 'none'};
    if (change === null) return {kind: 'both', lead: cancel as Lead}; // cannot happen: change is never shorter than cancel
    if (cancel === null) return {kind: 'split_open', change};
    if (sameLead(change, cancel)) return {kind: 'both', lead: cancel};
    return {kind: 'split', change, cancel};
}

export interface RuleGroup {
    rule: CateringDeadlineRule;
    /** The offers (hospitality_name) the rule applies to, in the order their orders are first met. */
    offerNames: string[];
}

/**
 * The distinct rules of the offers the orders were placed on. An order whose offer is not in `offers` (it has
 * been switched off, so its configuration is not sent) says nothing here: a rule that cannot be derived is
 * left out, never guessed. Empty = nothing to state.
 */
export function ruleGroups(
    orders: ReadonlyArray<{hospitality_id: number; hospitality_name: string}>,
    offers: ReadonlyArray<DeadlineConfig>,
): RuleGroup[] {
    const groups = new Map<string, RuleGroup>();
    for (const order of orders) {
        const offer = offers.find(candidate => candidate.id === order.hospitality_id);
        if (!offer) continue;
        const rule = deriveRule(offer);
        const key = JSON.stringify(rule);
        const group = groups.get(key) ?? {rule, offerNames: []};
        if (!group.offerNames.includes(order.hospitality_name)) group.offerNames.push(order.hospitality_name);
        groups.set(key, group);
    }
    return [...groups.values()];
}
