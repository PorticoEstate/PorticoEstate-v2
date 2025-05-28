import React, {Dispatch, FC, useState} from 'react';
import PopperContentSharedWrapper
    from "@/components/building-calendar/modules/event/popper/content/popper-content-shared-wrapper";
import {useClientTranslation} from "@/app/i18n/ClientTranslationProvider";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {usePartialApplications} from "@/service/hooks/api-hooks";
import styles from "./shopping-cart-content.module.scss";
import {Button, Spinner} from "@digdir/designsystemet-react";
import {ArrowRightIcon} from "@navikt/aksel-icons";
import Link from "next/link";
import {IApplication} from "@/service/types/api/application.types";
import {DateTime} from "luxon";
import ShoppingCartCardList from "@/components/layout/header/shopping-cart/shopping-cart-card-list";

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
                        {t('bookingfrontend.applications_ready_for_submission')}
                    </h2>
                </div>
                <div>
                <span>
                    {t('bookingfrontend.applications_overview')}
                </span>
                </div>
                {!isLoading && (basketData?.list.length || 0) === 0 && (<div>{t('bookingfrontend.empty_cart')}</div>)}
                {isLoading && (
                    <Spinner aria-label={t('bookingfrontend.loading_cart')}/>
                )}

                {!isLoading && (basketData?.list.length || 0) > 0 && (
                    <ShoppingCartCardList
                        basketData={basketData!.list}
                        openEdit={openEdit}
                        onLinkClick={() => props.setOpen(false)}
                    />
                )}

                <div className={styles.eventPopperFooter}>
                    <Button 
                        onClick={() => props.setOpen(false)} 
                        variant="tertiary" 
                        className={'default'}
                        data-size={'sm'}
                    >
                        {t('booking.close')}
                    </Button>

                    <Button 
                        variant="primary" 
                        className={'default'} 
                        asChild
                        data-size={'sm'}
                    >
                        <Link
                            href={'/checkout'}
                            className={'link-text link-text-unset normal'}
                            onClick={() => props.setOpen(false)}
                        >
                            {t('bookingfrontend.submit_application')} <ArrowRightIcon fontSize="1.5rem" />
                        </Link>
                    </Button>
                </div>
            </div>
        </PopperContentSharedWrapper>
    );
}

export default ShoppingCartContent


