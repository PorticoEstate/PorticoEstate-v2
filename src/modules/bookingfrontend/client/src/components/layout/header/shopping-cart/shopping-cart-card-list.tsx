import React, { FC, useState } from 'react';
import { Button, Link as DigdirLink } from "@digdir/designsystemet-react";
import { IApplication } from "@/service/types/api/application.types";
import { deletePartialApplication } from "@/service/api/api-utils";
import ResourceCircles from "@/components/resource-circles/resource-circles";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import { PencilIcon, TrashIcon, CalendarIcon, ArrowsCirclepathIcon } from "@navikt/aksel-icons";
import styles from "./shopping-cart-card-list.module.scss";
import { applicationTimeToLux } from "@/components/layout/header/shopping-cart/shopping-cart-content";
import { DateTime } from "luxon";
import { useClientTranslation } from "@/app/i18n/ClientTranslationProvider";
import Link from "next/link";
import { calculateApplicationCost, formatCurrency, getApplicationCurrency } from "@/utils/cost-utils";
import { RecurringInfoUtils, calculateRecurringInstances } from '@/utils/recurring-utils';
import RecurringDescription from './recurring-description';
import { useBuildingSeasons } from "@/service/hooks/api-hooks";
import ResourceIcon from "@/icons/ResourceIcon";
import BuildingIcon from "@/icons/BuildingIcon";

interface ShoppingCartCardListProps {
    basketData: IApplication[];
    openEdit: (item: IApplication) => void;
    onLinkClick: () => void;
}


const formatDateRange = (fromDate: DateTime, toDate?: DateTime, i18n?: any): [string, string] => {
    const localizedFromDate = fromDate.setLocale(i18n.language);

    if (!toDate) {
        return [
            localizedFromDate.toFormat('dd. MMM'),
            localizedFromDate.toFormat('HH:mm')
        ];
    }

    const localizedToDate = toDate.setLocale(i18n.language);

    // Check if dates are the same
    if (localizedFromDate.hasSame(localizedToDate, 'day')) {
        return [
            localizedFromDate.toFormat('dd. MMM'),
            `${localizedFromDate.toFormat('HH:mm')} - ${localizedToDate.toFormat('HH:mm')}`
        ];
    }

    const sameMonth = localizedFromDate.hasSame(localizedToDate, 'month');

    if (sameMonth) {
        return [
            `${localizedFromDate.toFormat('dd')}. - ${localizedToDate.toFormat('dd')}. ${localizedFromDate.toFormat('MMM')}`,
            `${localizedFromDate.toFormat('HH:mm')} - ${localizedToDate.toFormat('HH:mm')}`
        ];
    } else {
        return [
            `${localizedFromDate.toFormat('dd')}. ${localizedFromDate.toFormat('MMM')} - ${localizedToDate.toFormat('dd')}. ${localizedToDate.toFormat('MMM')}`,
            `${localizedFromDate.toFormat('HH:mm')} - ${localizedToDate.toFormat('HH:mm')}`
        ];
    }
};

// Wrapper component to handle seasons query for each card
const CartCardWithSeasons: FC<{
    item: IApplication;
    expandedId: number | null;
    setExpandedId: (id: number | null) => void;
    openEdit: (item: IApplication) => void;
    onLinkClick: () => void;
    t: any;
    i18n: any;
}> = ({ item, expandedId, setExpandedId, openEdit, onLinkClick, t, i18n }) => {
    // Call the hook at the top level for this specific item
    const { data: seasons } = useBuildingSeasons(item.building_id);

    return (
				<div
					key={item.id}
					className={styles.cartCard}
					onClick={() => setExpandedId(expandedId === item.id ? null : item.id)}
				>
					<div className={styles.cardHeader}>
						<h3 className={styles.cardTitle}>
							{item.name}
						</h3>
					</div>

					<div className={styles.cardContent}>
						<div className={styles.infoItem}>
							<BuildingIcon aria-hidden className={styles.infoIcon} />
							<DigdirLink asChild>
								<Link href={`/building/${item.building_id}`} onClick={(e) => {
									e.stopPropagation();
									onLinkClick();
								}}>
									{item.building_name}
								</Link>
							</DigdirLink>
						</div>

						<div className={styles.infoItem}>
							<CalendarIcon aria-hidden className={styles.infoIcon}/>
							{RecurringInfoUtils.isRecurring(item) ? (
								<span>
									{formatDateRange(
										applicationTimeToLux(item.dates[0].from_),
										applicationTimeToLux(item.dates[0].to_),
										i18n
									).join(' | ')}
								</span>
							) : (item.dates?.length || 0) === 1 ? (
								<span>
									{formatDateRange(
										applicationTimeToLux(item.dates[0].from_),
										applicationTimeToLux(item.dates[0].to_),
										i18n
									).join(' | ')}
								</span>
							) : (
								<span className={styles.multipleDates}>
									<span className={styles.badge}>
										{item.dates?.length || 0}
									</span>
									<span>{t('bookingfrontend.multiple_time_slots')}</span>
								</span>
							)}
						</div>

						{RecurringInfoUtils.isRecurring(item) && (
							<div className={styles.infoItem}>
								<ArrowsCirclepathIcon aria-hidden className={styles.infoIcon} />
								<span className={styles.recurringPattern}>
									<RecurringDescription application={item} />
								</span>
							</div>
						)}

						<div className={styles.infoItem}>
							<ResourceIcon aria-hidden className={styles.infoIcon} />
							<div className={styles.resourcesContainer}>
								{(item.resources || []).length === 1 ? (
									<div className={styles.singleResource}>
										<DigdirLink asChild>
											<Link href={`/resource/${item.resources[0].id}`} onClick={(e) => {
												e.stopPropagation();
												onLinkClick();
											}}>
												<span><ColourCircle resourceId={item.resources[0].id}/> {item.resources[0].name}</span>
											</Link>
										</DigdirLink>
									</div>
								) : expandedId === item.id ? (
									<ul className={styles.expandedResourcesList}>
										{(item.resources || []).map((resource) => (
											<li key={resource.id} className={styles.resourceItem}>
												<DigdirLink asChild>
													<Link href={`/resource/${resource.id}`} onClick={(e) => {
														e.stopPropagation();
														onLinkClick();
													}}>
														<span><ColourCircle resourceId={resource.id}/> {resource.name}</span>
													</Link>
												</DigdirLink>
											</li>
										))}
									</ul>
								) : (
									<ResourceCircles
										resources={item.resources || []}
										maxCircles={6}
										size={'small'}
										isExpanded={false}
									/>
								)}
							</div>
						</div>

						{calculateApplicationCost(item) > 0 && (
							<div className={styles.priceBreakdown}>
								{/* Price breakdown */}
								{item.orders && item.orders.length > 0 && (() => {
									// Calculate resources and articles costs (including VAT)
									let resourcesCost = 0;
									let articlesCost = 0;

									item.orders.forEach(order => {
										order.lines.forEach(line => {
											// Include VAT in the calculation: amount + tax
											// Convert to numbers to avoid string concatenation
											const lineTotal = Number(line.amount) + Number(line.tax || 0);

											// Check if this is a resource (typically has unit 'hour' or similar resource-based pricing)
											if (line.unit === 'hour' || line.unit === 'dag' || line.unit === 'day') {
												resourcesCost += lineTotal;
											} else {
												articlesCost += lineTotal;
											}
										});
									});

									const isRecurring = RecurringInfoUtils.isRecurring(item);
									const totalCost = calculateApplicationCost(item);
									const currency = getApplicationCurrency(item);

									// For recurring applications, calculate actual number of occurrences
									let occurrenceCount = 1;
									if (isRecurring) {
										// Calculate recurring instances using the same logic as the calendar
										const recurringInstances = calculateRecurringInstances(item, seasons);
										occurrenceCount = recurringInstances.length;
									}

									return (
										<>
											{resourcesCost > 0 && (
												<div className={styles.priceItem}>
													<span>{t('bookingfrontend.resources')}:</span>
													<span>{formatCurrency(resourcesCost, currency)}</span>
												</div>
											)}
											{articlesCost > 0 && (
												<div className={styles.priceItem}>
													<span>{t('bookingfrontend.articles')}:</span>
													<span>{formatCurrency(articlesCost, currency)}</span>
												</div>
											)}
											{isRecurring && (
												<div className={styles.priceItem}>
													<span>{t('bookingfrontend.per_occurrence')}:</span>
													<span>{formatCurrency(totalCost, currency)}</span>
												</div>
											)}
											<div className={`${styles.priceItem} ${styles.totalPrice}`}>
												<span>
													{isRecurring && occurrenceCount > 1
														? t('bookingfrontend.sum_occurrences', { count: occurrenceCount })
														: t('bookingfrontend.sum')}:
												</span>
												<span>{formatCurrency(isRecurring && occurrenceCount > 1 ? totalCost * occurrenceCount : totalCost, currency)}</span>
											</div>
										</>
									);
								})()}
							</div>
						)}
					</div>
					<div className={styles.cardActions}>
						<Button
							variant="tertiary"
							data-size="sm"
							onClick={(e) => {
								openEdit(item);
								e.stopPropagation();
							}}
							aria-label={t('bookingfrontend.edit')}
						>
							<PencilIcon aria-hidden/>
						</Button>
						<Button
							variant="tertiary"
							data-size="sm"
							onClick={(e) => {
								deletePartialApplication(item.id);
								e.stopPropagation();
							}}
							aria-label={t('bookingfrontend.remove_application')}
						>
							<TrashIcon aria-hidden/>
						</Button>
					</div>
					{expandedId === item.id && (item.dates?.length || 0) > 1 && (
						<div className={styles.datesAccordion}>
							<div className={styles.datesHeader}>
								{t('bookingfrontend.all_dates')}
							</div>
							<ul className={styles.datesList}>
								{item.dates?.map((date) => {
									const [dateStr, timeStr] = formatDateRange(
										applicationTimeToLux(date.from_),
										applicationTimeToLux(date.to_),
										i18n
									);
									return (
										<li key={date.id} className={styles.dateItem}>
											<div className={styles.dateDay}>{dateStr}</div>
											<div className={styles.dateTime}>{timeStr}</div>
										</li>
									);
								})}
							</ul>
						</div>
					)}
				</div>
	);
};

const ShoppingCartCardList: FC<ShoppingCartCardListProps> = ({ basketData, openEdit, onLinkClick }) => {
    const { t, i18n } = useClientTranslation();
    const [expandedId, setExpandedId] = useState<number | null>(null);

    return (
        <div className={styles.cartListContainer}>
            {basketData.map((item) => (
				<CartCardWithSeasons
					key={item.id}
					item={item}
					expandedId={expandedId}
					setExpandedId={setExpandedId}
					openEdit={openEdit}
					onLinkClick={onLinkClick}
					t={t}
					i18n={i18n}
				/>
			))}
		</div>
	);
};

export default ShoppingCartCardList;