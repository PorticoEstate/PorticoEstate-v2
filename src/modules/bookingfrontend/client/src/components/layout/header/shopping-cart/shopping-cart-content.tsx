import React, {Dispatch, FC, useState} from 'react';
import PopperContentSharedWrapper
    from "@/components/building-calendar/modules/event/popper/content/popper-content-shared-wrapper";
import {useClientTranslation} from "@/app/i18n/ClientTranslationProvider";
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
import {PencilIcon} from "@navikt/aksel-icons";
import ShoppingCartTable from "@/components/layout/header/shopping-cart/shopping-cart-table";

interface ShoppingCartContentProps {
    setOpen: Dispatch<boolean>;
    setCurrentApplication: Dispatch<{ application_id: number, date_id: number, building_id: number } | undefined>;
}
export const applicationTimeToLux = (timeStamp: string) => {
    return DateTime.fromISO(timeStamp);

}
const ShoppingCartContent: FC<ShoppingCartContentProps> = (props) => {
    const {t, i18n} = useClientTranslation();
    const isMobile = useIsMobile();
    const {data: basketData, isLoading} = usePartialApplications();

    const openEdit = (item: IApplication) =>  {
        props.setCurrentApplication({
            application_id: item.id,
            date_id: item.dates[0].id,
            building_id: item.building_id
        })
        props.setOpen(false);
    }

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
                    <Spinner aria-label={'Laster handlekurv'}/>
                )}

                {!isLoading && (basketData?.list.length || 0) > 0 && (
                    <ShoppingCartTable
                        basketData={basketData!.list}
                        openEdit={openEdit}
                    />
                )}

                <div className={styles.eventPopperFooter}>
                    <Button onClick={() => props.setOpen(false)} variant="tertiary" className={'default'}
                            data-size={'sm'}>{t('booking.close')}</Button>

                    <Button variant="primary" className={'default'} asChild
                            data-size={'sm'}><Link

                        href={'/checkout'}
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


