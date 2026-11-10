'use client';
import React, {FC} from 'react';
import {Heading, Paragraph, Table} from '@digdir/designsystemet-react';
import {InformationSquareIcon} from '@navikt/aksel-icons';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {IApplication, IOrderLine} from '@/service/types/api/application.types';
import {useHospitalityOrders} from '@/components/checkout/hooks/hospitality-hooks';
import {useServerSettings} from '@/service/hooks/api-hooks';
import {formatCurrency} from '@/utils/cost-utils';
import {
    applicationTotals,
    CateringState,
    formatMinutes,
    formatOreOrDash,
    formatTotalWithHourlyRate,
    hourlyRateOfOrderLine,
    isMinuteUnit,
    orderLineTotal,
    splitStoredLines,
    storedLinesAreFree,
    storedLinesInclOre,
    unitPriceInclOfOrderLine,
} from '@/utils/article-pricing';
import styles from './application-details.module.scss';
import linesStyles from './application-price-lines.module.scss';

type Translate = (key: string, options?: any) => string;

/** The API sends a stored quantity as a decimal string: "2.00" -> "2", "2.50" -> "2.5" (the catering lines read the same way). */
const formatQuantity = (quantity: number | string): string => String(Number(quantity));

/**
 * One stored order line: its name, «N × unit price incl. VAT» and its amount incl. VAT. The VAT is the line's own
 * (read back from its stored tax, never a rate assumed here); a unit price that cannot be read back is «-». A minute
 * line shows its minutes and, with the total, the hourly price that explains it, never the per-minute price.
 */
const PriceLineRow: FC<{line: IOrderLine; t: Translate}> = ({line, t}) => {
    const total = orderLineTotal(line);
    if (isMinuteUnit(line.unit)) {
        return (
            <Table.Row>
                <Table.Cell>{line.name}</Table.Cell>
                <Table.Cell>{formatMinutes(Number(line.quantity), t)}</Table.Cell>
                <Table.Cell>{formatTotalWithHourlyRate(total, hourlyRateOfOrderLine(line), line.currency, t)}</Table.Cell>
            </Table.Row>
        );
    }
    const unitIncl = unitPriceInclOfOrderLine(line);
    return (
        <Table.Row>
            <Table.Cell>{line.name}</Table.Cell>
            <Table.Cell>{formatQuantity(line.quantity)} × {unitIncl === null ? '-' : formatCurrency(unitIncl, line.currency)}</Table.Cell>
            <Table.Cell>{formatCurrency(total, line.currency)}</Table.Cell>
        </Table.Row>
    );
};

interface PriceLinesSectionProps {
    heading: string;
    /** The first column's head: «Rom» / «Tillegg». */
    itemLabel: string;
    lines: IOrderLine[];
    /** «Utstyr og tjenester total inkl. mva.»; with `totalOre` undefined there is no total row. */
    totalLabel: string;
    /** Whole øre incl. VAT; null = unknown («-»); undefined = not shown. */
    totalOre: number | null | undefined;
    t: Translate;
    testId: string;
}

/** One section of the application's price surface (design A): the heading, the lines and the section's total. */
const PriceLinesSection: FC<PriceLinesSectionProps> = ({heading, itemLabel, lines, totalLabel, totalOre, t, testId}) => (
    <section className={styles.priceSection} data-testid={testId}>
        <Heading level={2} data-size="sm" className={styles.sectionHeading}>{heading}</Heading>
        <Table data-size="sm" data-color="neutral" className={linesStyles.lines}>
            <Table.Head>
                <Table.Row>
                    <Table.HeaderCell>{itemLabel}</Table.HeaderCell>
                    <Table.HeaderCell>{t('bookingfrontend.catering_overview_col_quantity')}</Table.HeaderCell>
                    <Table.HeaderCell>{t('booking.amount')}</Table.HeaderCell>
                </Table.Row>
            </Table.Head>
            <Table.Body>
                {lines.map((line, index) => (
                    <PriceLineRow key={`${line.order_id}-${line.article_mapping_id}-${index}`} line={line} t={t}/>
                ))}
            </Table.Body>
        </Table>
        {totalOre !== undefined && (
            <Paragraph data-size="sm" className={linesStyles.total}>
                <span>{totalLabel}</span>
                <span data-testid={`${testId}-total`}>{formatOreOrDash(totalOre)}</span>
            </Paragraph>
        )}
    </section>
);

/** The row that closes the price surface when the price summary does not: the application's one total, under a rule. */
const ClosingTotalRow: FC<{label: string; ore: number | null; testId: string}> = ({label, ore, testId}) => (
    <Paragraph className={linesStyles.closing}>
        <span>{label}</span>
        <span data-testid={testId}>{formatOreOrDash(ore)}</span>
    </Paragraph>
);

interface ApplicationPriceLinesProps {
    application: IApplication;
}

/**
 * What the price cards of an application show and total (GH #1423), read once for both components below: the stored
 * lines split as the price summary splits them, and the totals decided by applicationTotals from what is known of
 * the catering. The catering orders are the catering section's own query (react-query shares it).
 */
const useApplicationPriceLines = (application: IApplication) => {
    const {data: serverSettings} = useServerSettings();
    const hospitalityOn = serverSettings === undefined ? undefined : !!serverSettings.booking_config?.enable_hospitality;
    const ordersQuery = useHospitalityOrders(hospitalityOn ? application.id : undefined);

    const {rental, services} = splitStoredLines(application.orders ?? []);
    const showRental = rental.length > 0 && !storedLinesAreFree(rental);
    const showServices = services.length > 0;

    const cateringOrders = (ordersQuery.data ?? []).filter(order => order.status !== 'cancelled');
    // What is known of the catering: off (so none was billed) or read, unreadable, or still being read.
    const catering: CateringState = hospitalityOn === false || ordersQuery.isSuccess ? 'read' : ordersQuery.isError ? 'unreadable' : 'pending';
    const {showServicesTotal, closing} = applicationTotals(application.orders ?? [], cateringOrders, catering);
    return {rental, services, showRental, showServices, showServicesTotal, closing};
};

/**
 * What the application costs in stored lines, as the first sections of the page's price surface (Min side design A,
 * GH #1423): «Romleie» (the resource lines) above «Utstyr og tjenester» (every other line), each line with its own VAT
 * and each section with its total incl. VAT. The split is the price summary's own (splitStoredLines).
 *
 *  - «Romleie» is left out when every rental line is known to cost nothing, as the summary leaves out a 0 kr part.
 *  - A BILLED catering order is also a stored line (the billing job turns it into a purchase order) and nothing the
 *    client holds tells it from an add-on, so the lines are listed but the «Utstyr og tjenester» total is only shown
 *    once the catering orders are known not to include a billed one, and not before they are read. When they cannot
 *    be read at all (a visitor with only the application's secret link) nothing is known about billing, and the
 *    total is shown as it always has been.
 *
 * The row that closes the surface, and the note under it, are ApplicationPriceLinesTotal, drawn after the catering.
 * Renders nothing when the application has no stored lines.
 */
const ApplicationPriceLines: FC<ApplicationPriceLinesProps> = ({application}) => {
    const t = useTrans();
    const {rental, services, showRental, showServices, showServicesTotal} = useApplicationPriceLines(application);
    if (!showRental && !showServices) return null;

    const rentalOre = storedLinesInclOre(rental);
    const servicesOre = storedLinesInclOre(services);

    return (
        <>
            {showRental && (
                <PriceLinesSection
                    heading={t('bookingfrontend.catering_overview_total_rental')}
                    itemLabel={t('bookingfrontend.resource_title')}
                    lines={rental}
                    totalLabel={t('bookingfrontend.application_rental_total')}
                    totalOre={rentalOre}
                    t={t}
                    testId="application-rental"
                />
            )}
            {showServices && (
                <PriceLinesSection
                    heading={t('bookingfrontend.catering_overview_total_services')}
                    itemLabel={t('bookingfrontend.application_col_addon')}
                    lines={services}
                    totalLabel={t('bookingfrontend.application_services_total')}
                    totalOre={showServicesTotal ? servicesOre : undefined}
                    t={t}
                    testId="application-services"
                />
            )}
        </>
    );
};

/**
 * The end of the application's price surface, after the catering section and its price summary (design A puts the
 * application's total last):
 *  - the row that closes the prices is the application's total, there when nothing else gives it (the price summary,
 *    the Romleie total or the add-ons' own total). With no catering it is «Total inkl. mva»; with catering whose
 *    billing cannot be ruled out, or one that could not be read, it is the REGISTERED total, every stored line added
 *    up. Which of them, and whether at all, is decided in ONE place, applicationTotals, which uses the price summary's
 *    own test: this component only draws;
 *  - the note (invoice after the event / price confirmed on approval) is kept, last.
 *
 * Renders nothing when the application has no stored lines, as ApplicationPriceLines.
 */
export const ApplicationPriceLinesTotal: FC<ApplicationPriceLinesProps> = ({application}) => {
    const t = useTrans();
    const {showRental, showServices, closing} = useApplicationPriceLines(application);
    if (!showRental && !showServices) return null;

    return (
        <>
            {closing && (
                <ClosingTotalRow
                    label={t(closing.kind === 'combined' ? 'bookingfrontend.total_incl_vat' : 'bookingfrontend.application_registered_total')}
                    ore={closing.ore}
                    testId={`application-price-lines-${closing.kind}`}
                />
            )}
            <div className={styles.infoNote}>
                <InformationSquareIcon fontSize="0.9rem"/>
                <span>
                    {application.status === 'ACCEPTED'
                        ? t('bookingfrontend.invoice_after_event')
                        : t('bookingfrontend.price_confirmed_on_approval')}
                </span>
            </div>
        </>
    );
};

export default ApplicationPriceLines;
