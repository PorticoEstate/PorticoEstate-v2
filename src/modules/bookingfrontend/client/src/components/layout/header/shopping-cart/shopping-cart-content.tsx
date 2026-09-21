import React, {Dispatch, FC} from 'react';
import {usePartialApplications} from "@/service/hooks/api-hooks";
import {Spinner} from "@digdir/designsystemet-react";
import {IApplication} from "@/service/types/api/application.types";
import {DateTime} from "luxon";
import {VENUE_TIMEZONE} from "@/utils/venue-timezone";
import {deletePartialApplication} from "@/service/api/api-utils";
import CartC from "./cart-c/CartC";
import {useRouter} from "next/navigation";

interface ShoppingCartContentProps {
    setOpen: Dispatch<boolean>;
    setCurrentApplication: Dispatch<{ application_id: number, date_id: number, building_id: number } | undefined>;
}

// setZone only changes which zone's wall-clock the DateTime reports (its .hour/.day/
// .toFormat() etc.) — it does not change the underlying instant, so callers that end
// with .toJSDate() (calendar-context.tsx, application-crud.tsx — feeding FullCalendar's
// start/end) get the exact same Date/epoch either way. Safe to fix here for every caller.
export const applicationTimeToLux = (timeStamp: string) => {
    return DateTime.fromISO(timeStamp).setZone(VENUE_TIMEZONE);
}

const ShoppingCartContent: FC<ShoppingCartContentProps> = (props) => {
    const {data: basketData, isLoading} = usePartialApplications();
    const router = useRouter();

    const handleEdit = (id: number) => {
        const app = basketData?.list.find((a) => a.id === id);
        if (!app) return;
        props.setCurrentApplication({
            application_id: app.id,
            date_id: app.dates[0]?.id,
            building_id: app.building_id,
        });
        props.setOpen(false);
    };

    const handleRemove = (id: number) => {
        deletePartialApplication(id);
    };

    // Navigation only -- the application is submitted from the checkout page itself.
    const handleGoToSubmission = () => {
        props.setOpen(false);
        router.push('/checkout');
    };

    const handleClose = () => {
        props.setOpen(false);
    };

    if (isLoading) {
        return (
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%' }}>
                <Spinner aria-label="Laster handlekurv" />
            </div>
        );
    }

    return (
        <CartC
            applications={basketData?.list ?? []}
            onEditApplication={handleEdit}
            onRemoveApplication={handleRemove}
            onGoToSubmission={handleGoToSubmission}
            onClose={handleClose}
        />
    );
}

export default ShoppingCartContent
