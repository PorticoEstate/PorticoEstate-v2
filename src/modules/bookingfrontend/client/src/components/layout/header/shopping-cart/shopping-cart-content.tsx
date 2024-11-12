import React, {Dispatch, FC, useState} from 'react';
import PopperContentSharedWrapper
    from "@/components/building-calendar/modules/event/popper/content/popper-content-shared-wrapper";
import {useClientTranslation, useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {usePartialApplications} from "@/service/hooks/api-hooks";
import styles from "./shopping-cart-content.module.scss";
import {Badge, Button, Spinner, Table, List} from "@digdir/designsystemet-react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faArrowRightLong, faArrowUpRightFromSquare, faTrashCan} from "@fortawesome/free-solid-svg-icons";
import {phpGWLink} from "@/service/util";
import Link from "next/link";
import {IApplication} from "@/service/types/api/application.types";
import {DateTime} from "luxon";
import {deletePartialApplication} from "@/service/api/api-utils";
import ResourceCircles from "@/components/resource-circles/resource-circles";

interface ShoppingCartContentProps {
    setOpen: Dispatch<boolean>;
}


const timeToLux = (timeStamp: string) => {
    return DateTime.fromFormat(timeStamp, "yyyy-MM-dd HH:mm:ss");

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

const ShoppingCartContent: FC<ShoppingCartContentProps> = (props) => {
    const {t, i18n} = useClientTranslation();
    const isMobile = useIsMobile();
    const {data: basketData, isLoading} = usePartialApplications();

    const [expandedId, setExpandedId] = useState<number>();
    const getStartTime = (application: IApplication) => {
        if (application.dates.length === 1) {
            const from = timeToLux(application.dates[0].from_);
            const to = timeToLux(application.dates[0].to_);
            return formatDateRange(from, to, i18n).join(' | ');
        }
        if (expandedId === application.id) {
            return <List.Unordered
                style={{
                    listStyle: 'none',
                    padding: 0
                }}>

                {application.dates.map((date) => {
                    const from = timeToLux(date.from_);
                    const to = timeToLux(date.to_);

                    return <List.Item key={date.id}>{formatDateRange(from, to, i18n).join(' | ')}</List.Item>
                })}
            </List.Unordered>
        }
        return <span><Badge count={application.dates.length} color={'neutral'}/> Flere tidspunkt</span>
    }



    console.log(basketData)
    return (
        <PopperContentSharedWrapper onClose={() => props.setOpen(false)} header={!isMobile}>
            <div className={styles.shoppingBasket}>
                <div>
                    <h2>
                        Søknader klar for innsending
                    </h2>
                </div>
                <div>
                <span>
                    Her er en oversikt over dine søknader som er klar for innsending og godkjennelse.
                </span>
                </div>
                {!isLoading && (basketData?.list.length || 0) === 0 && (<div>Du har ingenting i handlekurven</div>)}
                {isLoading && (
                    <Spinner title={'Laster handlekurv'}/>
                )}
                {!isLoading && (basketData?.list.length || 0) > 0 && (
                    <Table
                        hover
                        size="md"
                        zebra
                        className={styles.shoppingBasketTable}
                    >
                        <Table.Head>
                            <Table.Row>
                                <Table.HeaderCell>
                                    Start tidspunkt
                                </Table.HeaderCell>
                                <Table.HeaderCell>
                                    Hvor
                                </Table.HeaderCell>
                                <Table.HeaderCell>
                                    Hva
                                </Table.HeaderCell>
                                <Table.HeaderCell>
                                    Vis søknad
                                </Table.HeaderCell>
                                <Table.HeaderCell>
                                    Fjern søknad
                                </Table.HeaderCell>
                            </Table.Row>
                        </Table.Head>
                        <Table.Body>
                            {basketData?.list.map((item) => (
                                <Table.Row key={item.id} onClick={() => {
                                    if (expandedId === item.id) {
                                        setExpandedId(undefined);
                                        return;
                                    }
                                    setExpandedId(item.id);
                                }}>
                                    <Table.Cell>
                                        {getStartTime(item)}
                                    </Table.Cell>
                                    <Table.Cell>
                                        {item.building_name}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <ResourceCircles resources={item.resources} maxCircles={4} size={'small'} isExpanded={expandedId === item.id} />
                                    </Table.Cell>
                                    <Table.Cell>

                                        <Button variant="tertiary" asChild>
                                            <Link
                                                href={phpGWLink('bookingfrontend/', {menuaction: 'bookingfrontend.uiapplication.show', id: item.id, secret: item.secret || ''}, false)}
                                                className={'link-text link-text-unset normal'} target={'_blank'}>
                                                <FontAwesomeIcon icon={faArrowUpRightFromSquare}/>
                                            </Link>
                                        </Button>

                                    </Table.Cell>
                                    <Table.Cell>
                                        <Button variant="tertiary" onClick={() => deletePartialApplication(item.id)}>
                                            <FontAwesomeIcon icon={faTrashCan}/>
                                        </Button>
                                    </Table.Cell>
                                </Table.Row>
                            ))}

                        </Table.Body>
                    </Table>)}
                <div className={styles.eventPopperFooter}>
                    <Button onClick={() => props.setOpen(false)} variant="tertiary" className={'default'}
                            size={'sm'}>{t('booking.close')}</Button>

                    <Button variant="primary" className={'default'} asChild
                            size={'sm'}><Link

                        href={phpGWLink('bookingfrontend/', {menuaction: 'bookingfrontend.uiapplication.add_contact'}, false)}
                        className={'link-text link-text-unset normal'}>
                        {t('bookingfrontend.submit_application')} <FontAwesomeIcon icon={faArrowRightLong}/>
                    </Link>
                    </Button>
                </div>
            </div>
        </PopperContentSharedWrapper>
    );
}

export default ShoppingCartContent


