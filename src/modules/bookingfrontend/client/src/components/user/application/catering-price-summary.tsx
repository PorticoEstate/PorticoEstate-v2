'use client';
import React, {FC} from 'react';
import {useClientTranslation} from '@/app/i18n/ClientTranslationProvider';
import {formatOreOrDash, PriceSummary, withoutZeroParts} from '@/utils/article-pricing';
import styles from './catering-overview.module.scss';

export interface PriceSummaryPart {
    key: string;
    label: string;
    /** Whole øre incl. VAT; null = unknown, shown as «-». */
    ore: number | null;
}

interface CateringPriceSummaryProps {
    summary: PriceSummary;
    /** «Bevertning total inkl. mva.» / «Totalt inkl. mva.» */
    totalLabel: string;
    /** Rows above the ex-VAT part, each already incl. VAT (the room rental, the equipment, the catering); a 0 kr row is left out. */
    parts?: PriceSummaryPart[];
    /** The application's total that closes the page's prices (design A): the total row in large type, the sum ex VAT and VAT quieter. */
    grand?: boolean;
    /** A line under the total's label, «Beløp inkl. mva.». */
    caption?: string;
    'data-testid'?: string;
}

/**
 * The price of a set of stored lines in the three figures that add up: the sum ex VAT, the VAT (one row per
 * rate) and the total incl. VAT. ex + VAT = incl to the øre, because all three come from the same whole-øre
 * lines (summarisePricedLines). An unknown VAT is «-»: never 0.
 */
const CateringPriceSummary: FC<CateringPriceSummaryProps> = ({summary, totalLabel, parts, grand, caption, 'data-testid': testId}) => {
    const {t} = useClientTranslation();
    const shownParts = withoutZeroParts(parts ?? []);

    const vatRows: Array<{key: string; label: string; ore: number | null}> =
        summary.vatOre === null
            ? [{key: 'unknown', label: t('bookingfrontend.catering_overview_vat'), ore: null}]
            : summary.rates.length === 0
                ? [{key: 'none', label: t('bookingfrontend.catering_overview_vat'), ore: summary.vatOre}]
                : summary.rates.map(rate => ({
                    key: String(rate.percent),
                    label: rate.percent === null
                        ? t('bookingfrontend.catering_overview_vat')
                        : t('bookingfrontend.catering_overview_vat_rate').replace('%1', String(rate.percent)),
                    ore: rate.vatOre,
                }));

    return (
        <dl className={`${styles.summary} ${grand ? styles.summaryGrand : ''}`} data-testid={testId}>
            {shownParts.map(part => (
                <div key={part.key} className={styles.summaryRow} data-testid={`${testId}-${part.key}`}>
                    <dt>{part.label}</dt>
                    <dd>{formatOreOrDash(part.ore)}</dd>
                </div>
            ))}
            <div className={`${styles.summaryRow} ${styles.summaryDetail} ${shownParts.length ? styles.summaryPart : ''}`} data-testid={`${testId}-ex`}>
                <dt>{t('bookingfrontend.catering_overview_sum_ex_vat')}</dt>
                <dd>{formatOreOrDash(summary.exOre)}</dd>
            </div>
            {vatRows.map(row => (
                <div key={row.key} className={`${styles.summaryRow} ${styles.summaryDetail}`} data-testid={`${testId}-vat`}>
                    <dt>{row.label}</dt>
                    <dd>{formatOreOrDash(row.ore)}</dd>
                </div>
            ))}
            <div className={`${styles.summaryRow} ${styles.summaryPart} ${grand ? styles.grandTotal : styles.summaryTotal}`} data-testid={`${testId}-incl`}>
                <dt>
                    {totalLabel}
                    {caption && <small className={styles.grandCaption}>{caption}</small>}
                </dt>
                <dd>{formatOreOrDash(summary.inclOre)}</dd>
            </div>
        </dl>
    );
};

export default CateringPriceSummary;
