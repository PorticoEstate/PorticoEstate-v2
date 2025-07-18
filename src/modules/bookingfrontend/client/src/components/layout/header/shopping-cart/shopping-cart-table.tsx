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

interface ShoppingCartTableProps {
    basketData: IApplication[];
    openEdit: (item: IApplication) => void;
    showParentSelection?: boolean;
    selectedParentId?: number;
    onParentIdChange?: (parentId: number) => void;
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


const ShoppingCartTable: FC<ShoppingCartTableProps> = ({ basketData, openEdit, showParentSelection, selectedParentId, onParentIdChange }) => {
    const {i18n} = useClientTranslation();
    const [expandedId, setExpandedId] = useState<number>();

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
                    <Table.HeaderCell>{i18n.t('bookingfrontend.edit')}</Table.HeaderCell>
                    <Table.HeaderCell>{i18n.t('bookingfrontend.remove_application')}</Table.HeaderCell>
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
                                    name="parent-application"
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
                            <Button variant="tertiary" className={'link-text link-text-unset normal'} onClick={(e) => {
                                openEdit(item);
                                e.stopPropagation();
                            }}>
                                <PencilIcon />
                            </Button>
                        </Table.Cell>
                        <Table.Cell>
                            <Button variant="tertiary" onClick={() => deletePartialApplication(item.id)}>
                                <TrashIcon />
                            </Button>
                        </Table.Cell>
                    </Table.Row>
                ))}
            </Table.Body>
        </Table>
    );
}

export default ShoppingCartTable;