import React, { FC, useState } from 'react';
import { Button, Table, List, Badge, Radio } from "@digdir/designsystemet-react";
import { IApplication } from "@/service/types/api/application.types";
import { deletePartialApplication } from "@/service/api/api-utils";
import ResourceCircles from "@/components/resource-circles/resource-circles";
import { PencilIcon, TrashIcon } from "@navikt/aksel-icons";
import styles from "./shopping-cart-content.module.scss";
import {applicationTimeToLux} from "@/components/layout/header/shopping-cart/shopping-cart-content";
import {DateTime} from "luxon";
import {useClientTranslation} from "@/app/i18n/ClientTranslationProvider";
import { calculateApplicationCost, formatCurrency, getApplicationCurrency } from "@/utils/cost-utils";
import { RecurringInfoUtils, calculateRecurringInstances } from '@/utils/recurring-utils';
import { useBuildingSeasons } from "@/service/hooks/api-hooks";
import { useIsMobile } from "@/service/hooks/is-mobile";

interface ShoppingCartTableProps {
    basketData: IApplication[];
    openEdit: (item: IApplication) => void;
    showParentSelection?: boolean;
    selectedParentId?: number;
    onParentIdChange?: (parentId: number) => void;
    buildingId?: number;
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


const ShoppingCartTable: FC<ShoppingCartTableProps> = ({ basketData, openEdit, showParentSelection, selectedParentId, onParentIdChange, buildingId }) => {
    const {i18n} = useClientTranslation();
    const [expandedId, setExpandedId] = useState<number>();
    const isMobile = useIsMobile();

    // Fetch seasons for all unique buildings
    const buildingIds = [...new Set(basketData.map(item => item.building_id))];
    const seasonsQueries = buildingIds.map(id => {
        // eslint-disable-next-line react-hooks/rules-of-hooks
        return useBuildingSeasons(id);
    });

    // Create a map of building_id to seasons for easy lookup
    const seasonsMap = new Map();
    buildingIds.forEach((id, index) => {
        seasonsMap.set(id, seasonsQueries[index]?.data);
    });

    const getStartTime = (application: IApplication) => {
        if ((application.dates?.length || 0) === 1) {
            const from = applicationTimeToLux(application.dates[0].from_);
            const to = applicationTimeToLux(application.dates[0].to_);
            return formatDateRange(from, to, i18n).join(' | ');
        }
        if (expandedId === application.id) {
            return <List.Unordered
                style={{
                    listStyle: 'none',
                    padding: 0
                }}>
                {application.dates?.map((date) => {
                    const from = applicationTimeToLux(date.from_);
                    const to = applicationTimeToLux(date.to_);
                    return <List.Item key={date.id}>{formatDateRange(from, to, i18n).join(' | ')}</List.Item>
                })}
            </List.Unordered>
        }
        return <span><Badge count={application.dates?.length || 0} color={'neutral'}/> {i18n.t('bookingfrontend.multiple_time_slots')}</span>
    }

    // Mobile card view
    if (isMobile) {
        return (
            <div className={styles.mobileTableContainer}>
                {basketData.map((item) => {
                    const cost = calculateApplicationCost(item);
                    const currency = getApplicationCurrency(item);
                    const isRecurring = RecurringInfoUtils.isRecurring(item);
                    const seasons = seasonsMap.get(item.building_id);
                    const recurringInstances = isRecurring ? calculateRecurringInstances(item, seasons) : [];
                    const occurrenceCount = recurringInstances.length;

                    return (
                        <div key={item.id} className={styles.mobileTableCard}>
                            {showParentSelection && (
                                <div className={styles.mobileCardRadio}>
                                    <Radio
                                        name={`parent-application-building-${buildingId || 'default'}`}
                                        value={item.id.toString()}
                                        checked={selectedParentId === item.id}
                                        onChange={() => onParentIdChange?.(item.id)}
                                        aria-label={`Select application ${item.id} as main application`}
                                    />
                                    <span className={styles.mobileCardTitle}>{item.name}</span>
                                </div>
                            )}
                            {!showParentSelection && (
                                <div className={styles.mobileCardTitle}>{item.name}</div>
                            )}

                            <div className={styles.mobileCardRow}>
                                <span>{i18n.t('bookingfrontend.start_time')}:</span>
                                <span>{getStartTime(item)}</span>
                            </div>

                            <div className={styles.mobileCardRow}>
                                <span>{i18n.t('bookingfrontend.where')}:</span>
                                <span>{item.building_name}</span>
                            </div>

                            <div className={styles.mobileCardRow}>
                                <span>{i18n.t('bookingfrontend.what')}:</span>
                                <ResourceCircles
                                    resources={item.resources || []}
                                    maxCircles={4}
                                    size={'small'}
                                    isExpanded={expandedId === item.id}
                                />
                            </div>

                            {cost > 0 && (
                                <div className={`${styles.mobileCardRow} ${styles.mobileCardPrice}`}>
                                    <span>{i18n.t('bookingfrontend.price')}:</span>
                                    {isRecurring ? (
                                        <div className={styles.mobilePriceBreakdown}>
                                            <div>{formatCurrency(cost, currency)} {i18n.t('bookingfrontend.per_occurrence_short')}</div>
                                            <div style={{ fontWeight: 'bold' }}>
                                                {formatCurrency(cost * occurrenceCount, currency)} ({occurrenceCount} {i18n.t('bookingfrontend.occurrences')})
                                            </div>
                                        </div>
                                    ) : (
                                        <span>{formatCurrency(cost, currency)}</span>
                                    )}
                                </div>
                            )}

                            <div className={styles.mobileCardActions}>
                                <Button variant="tertiary" data-size="sm" onClick={() => openEdit(item)} aria-label={i18n.t('bookingfrontend.edit')}>
                                    <PencilIcon /> {i18n.t('bookingfrontend.edit')}
                                </Button>
                                <Button variant="tertiary" data-size="sm" onClick={() => deletePartialApplication(item.id)} aria-label={i18n.t('bookingfrontend.delete')}>
                                    <TrashIcon /> {i18n.t('common.delete')}
                                </Button>
                            </div>
                        </div>
                    );
                })}
            </div>
        );
    }

    return (
        <Table
            hover
            data-size="md"
            zebra
            className={styles.shoppingBasketTable}
        >
            <Table.Head>
                <Table.Row>
                    {showParentSelection && <Table.HeaderCell></Table.HeaderCell>}
                    <Table.HeaderCell>{i18n.t('bookingfrontend.title')}</Table.HeaderCell>
                    <Table.HeaderCell>{i18n.t('bookingfrontend.start_time')}</Table.HeaderCell>
                    <Table.HeaderCell>{i18n.t('bookingfrontend.where')}</Table.HeaderCell>
                    <Table.HeaderCell>{i18n.t('bookingfrontend.what')}</Table.HeaderCell>
                    <Table.HeaderCell>{i18n.t('bookingfrontend.price')}</Table.HeaderCell>
                    <Table.HeaderCell></Table.HeaderCell>
                </Table.Row>
            </Table.Head>
            <Table.Body>
                {basketData.map((item) => (
                    <Table.Row key={item.id} onClick={() => {
                        if (expandedId === item.id) {
                            setExpandedId(undefined);
                            return;
                        }
                        setExpandedId(item.id);
                    }}>
                        {showParentSelection && (
                            <Table.Cell>
                                <Radio
                                    name={`parent-application-building-${buildingId || 'default'}`}
                                    value={item.id.toString()}
                                    checked={selectedParentId === item.id}
                                    onChange={(e) => {
                                        onParentIdChange?.(item.id);
                                        e.stopPropagation();
                                    }}
                                    onClick={(e) => e.stopPropagation()}
                                    aria-label={`Select application ${item.id} as main application`}
                                />
                            </Table.Cell>
                        )}
                        <Table.Cell>{item.name}</Table.Cell>
                        <Table.Cell>{getStartTime(item)}</Table.Cell>
                        <Table.Cell>{item.building_name}</Table.Cell>
                        <Table.Cell>
                            <ResourceCircles
                                resources={item.resources || []}
                                maxCircles={4}
                                size={'small'}
                                isExpanded={expandedId === item.id}
                            />
                        </Table.Cell>
                        <Table.Cell>
                            {(() => {
                                const cost = calculateApplicationCost(item);
                                const currency = getApplicationCurrency(item);
                                const isRecurring = RecurringInfoUtils.isRecurring(item);

                                if (cost === 0) return '-';

                                if (isRecurring) {
                                    const seasons = seasonsMap.get(item.building_id);
                                    const recurringInstances = calculateRecurringInstances(item, seasons);
                                    const occurrenceCount = recurringInstances.length;
                                    const totalCost = cost * occurrenceCount;

                                    return (
                                        <div style={{ fontSize: '0.875rem' }}>
                                            <div>{formatCurrency(cost, currency)} {i18n.t('bookingfrontend.per_occurrence_short')}</div>
                                            <div style={{ fontWeight: 'bold', marginTop: '0.25rem' }}>
                                                {formatCurrency(totalCost, currency)} ({occurrenceCount} {i18n.t('bookingfrontend.occurrences')})
                                            </div>
                                        </div>
                                    );
                                }

                                return formatCurrency(cost, currency);
                            })()}
                        </Table.Cell>
                        <Table.Cell>
                            <div style={{ display: 'flex', gap: '0.5rem' }}>
                                <Button variant="tertiary" className={'link-text link-text-unset normal'} onClick={(e) => {
                                    openEdit(item);
                                    e.stopPropagation();
                                }} aria-label={i18n.t('bookingfrontend.edit')}>
                                    <PencilIcon />
                                </Button>
                                <Button variant="tertiary" onClick={(e) => {
                                    deletePartialApplication(item.id);
                                    e.stopPropagation();
                                }} aria-label={i18n.t('bookingfrontend.remove_application')}>
                                    <TrashIcon />
                                </Button>
                            </div>
                        </Table.Cell>
                    </Table.Row>
                ))}
            </Table.Body>
        </Table>
    );
}

export default ShoppingCartTable;