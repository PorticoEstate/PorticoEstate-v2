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
import { calculateApplicationCost, formatCurrency } from "@/utils/cost-utils";
import { RecurringInfoUtils, calculateRecurringInstances } from '@/utils/recurring-utils';
import { useBuildingSeasons } from "@/service/hooks/api-hooks";

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

    // Fetch seasons for all unique buildings in the cart
    const buildingIds = [...new Set((basketData?.list || []).map(item => item.building_id))];
    const seasonsQueries = buildingIds.map(buildingId => {
        // eslint-disable-next-line react-hooks/rules-of-hooks
        return useBuildingSeasons(buildingId);
    });

    // Create a map of building_id to seasons for easy lookup
    const seasonsMap = new Map();
    buildingIds.forEach((buildingId, index) => {
        seasonsMap.set(buildingId, seasonsQueries[index]?.data);
    });

    // Calculate total cost including recurring applications
    const calculateTotalCost = (): number => {
        if (!basketData?.list) return 0;

        return basketData.list.reduce((total, app) => {
            const appCost = calculateApplicationCost(app);

            // Check if recurring
            if (RecurringInfoUtils.isRecurring(app)) {
                const seasons = seasonsMap.get(app.building_id);
                const recurringInstances = calculateRecurringInstances(app, seasons);
                return total + (appCost * recurringInstances.length);
            }

            return total + appCost;
        }, 0);
    };

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

                {!isLoading && (basketData?.list.length || 0) > 0 && calculateTotalCost() > 0 && (
                    <div className={styles.totalCost}>
                        <span className={styles.totalLabel}>{t('bookingfrontend.total')}:</span>
                        <span className={styles.totalAmount}>
                            {formatCurrency(calculateTotalCost())}
                        </span>
                    </div>
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


