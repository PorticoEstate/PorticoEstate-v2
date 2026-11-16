import {inclOre} from '@/utils/article-pricing';
import {IHospitalityArticle} from '@/service/types/api/hospitality.types';

/*
 * The price summary of a catering order: the sum EX VAT, the VAT per rate and the total INCL. VAT.
 *
 * Same basis as billing (create_purchase_orders_from_hospitality): a line's ex-VAT amount is its price x
 * quantity in whole øre, its VAT is that amount x the VAT % rounded ONCE to the øre per line, and the
 * line's incl. total is the two added. The per-line rounding is `inclOre` (utils/article-pricing), the one
 * place the rule lives, so the summary cannot drift from the item rows and totals that go through it.
 * Sums are in øre. ex + VAT = incl holds EXACTLY because every figure is the sum of the same per-line
 * parts, never a rounding of a sum.
 *
 * A VAT % of NULL means «the tax code has no fm_ecomva row» and is NOT 0 %: the summary is then not
 * `known`, and the caller shows «-» for the VAT and the total instead of a figure that would hide the gap.
 */

/** One ordered line: its ex-VAT amount in whole øre and its VAT % (null = unknown, not 0 %). */
export interface VatSummaryLine {
    /** Null when the line's price is unknown. */
    exOre: number | null;
    percent: number | null;
}

/** The lines of one VAT rate. */
export interface VatRateBreakdown {
    percent: number;
    exOre: number;
    vatOre: number;
}

export type VatSummary =
    | {
    known: true;
    exOre: number;
    vatOre: number;
    inclOre: number;
    /** One entry per rate present, highest rate first. A 0 % rate is an entry with vatOre 0. */
    rates: VatRateBreakdown[];
}
    /** A line's price or VAT % is unknown. `exOre` is still the exact ex-VAT sum when every price is known. */
    | { known: false; exOre: number | null };

const knownNumber = (value: number | string | null | undefined): number | null => {
    if (value === null || value === undefined || value === '') return null;
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
};

/**
 * A menu article ordered `quantity` times, as a summary line. The ex-VAT amount is rounded to whole øre,
 * as the writer stores it (numeric(10,2)); with a whole quantity it is exact.
 */
export const hospitalityMenuLineForSummary = (
    article: Pick<IHospitalityArticle, 'effective_price' | 'effective_tax_percent'>,
    quantity: number
): VatSummaryLine => {
    const price = knownNumber(article.effective_price);
    return {
        exOre: price === null ? null : Math.round(Math.round(price * 100) * quantity),
        percent: knownNumber(article.effective_tax_percent),
    };
};

export const summarizeHospitalityVat = (lines: VatSummaryLine[]): VatSummary => {
    let exOre: number | null = 0;
    for (const line of lines) {
        exOre = exOre === null || line.exOre === null ? null : exOre + line.exOre;
    }
    if (exOre === null || lines.some(line => line.percent === null)) {
        return {known: false, exOre};
    }

    const byRate = new Map<number, VatRateBreakdown>();
    let vatOre = 0;
    for (const line of lines) {
        const percent = line.percent as number;
        const lineEx = line.exOre as number;
        const lineVat = inclOre(lineEx, percent) - lineEx;
        const rate = byRate.get(percent) ?? {percent, exOre: 0, vatOre: 0};
        rate.exOre += lineEx;
        rate.vatOre += lineVat;
        byRate.set(percent, rate);
        vatOre += lineVat;
    }

    return {
        known: true,
        exOre,
        vatOre,
        inclOre: exOre + vatOre,
        rates: [...byRate.values()].sort((a, b) => b.percent - a.percent),
    };
};
