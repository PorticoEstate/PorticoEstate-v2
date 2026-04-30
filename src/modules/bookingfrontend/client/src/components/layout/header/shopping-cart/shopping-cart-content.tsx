import React, {Dispatch, FC} from 'react';
import {usePartialApplications} from "@/service/hooks/api-hooks";
import {Spinner} from "@digdir/designsystemet-react";
import {IApplication} from "@/service/types/api/application.types";
import {DateTime} from "luxon";
import {deletePartialApplication} from "@/service/api/api-utils";
import CartC from "./cart-c/CartC";
import {useRouter} from "next/navigation";

interface ShoppingCartContentProps {
    setOpen: Dispatch<boolean>;
    setCurrentApplication: Dispatch<{ application_id: number, date_id: number, building_id: number } | undefined>;
}

export const applicationTimeToLux = (timeStamp: string) => {
    return DateTime.fromISO(timeStamp);
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

    const handleSubmit = () => {
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
            onSubmit={handleSubmit}
            onClose={handleClose}
        />
    );
}

export default ShoppingCartContent
