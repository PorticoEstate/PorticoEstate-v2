import React, { FC, useState } from 'react';
import { Button, Link as DigdirLink } from "@digdir/designsystemet-react";
import { IApplication } from "@/service/types/api/application.types";
import { deletePartialApplication } from "@/service/api/api-utils";
import ResourceCircles from "@/components/resource-circles/resource-circles";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import { PencilIcon, TrashIcon, CalendarIcon } from "@navikt/aksel-icons";
import styles from "./shopping-cart-card-list.module.scss";
import { applicationTimeToLux } from "@/components/layout/header/shopping-cart/shopping-cart-content";
import { DateTime } from "luxon";
import { useClientTranslation } from "@/app/i18n/ClientTranslationProvider";
import Link from "next/link";
import { calculateApplicationCost, formatCurrency, getApplicationCurrency } from "@/utils/cost-utils";

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

const ShoppingCartCardList: FC<ShoppingCartCardListProps> = ({ basketData, openEdit, onLinkClick }) => {
    const { t, i18n } = useClientTranslation();
    const [expandedId, setExpandedId] = useState<number | null>(null);

    return (
        <div className={styles.cartListContainer}>
            {basketData.map((item) => (
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

					{calculateApplicationCost(item) > 0 && (
						<div className={styles.cardCost}>
							{/*<span className={styles.costLabel}>{t('bookingfrontend.cost')}:</span>*/}
							<span className={styles.costAmount}>
                                {formatCurrency(calculateApplicationCost(item), getApplicationCurrency(item))}
                            </span>
						</div>
					)}

					<div className={styles.cardInfo}>
						<div className={styles.infoItem}>
							{/*<BuildingIcon aria-hidden className={styles.infoIcon} />*/}
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
							{(item.dates?.length || 0) === 1 ? (
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
					</div>

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
			))}
		</div>
	);
};

export default ShoppingCartCardList;