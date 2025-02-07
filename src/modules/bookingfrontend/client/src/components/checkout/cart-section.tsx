// app/[lang]/(public)/checkout/components/cart-section.tsx
import {Dispatch, FC} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {DateTime} from "luxon";
import ResourceCircles from "@/components/resource-circles/resource-circles";
import {IApplication} from "@/service/types/api/application.types";
import styles from './checkout.module.scss';
import ShoppingCartTable from "@/components/layout/header/shopping-cart/shopping-cart-table";

interface CartSectionProps {
    applications: IApplication[];
    setCurrentApplication: Dispatch<{ application_id: number, date_id: number, building_id: number } | undefined>;
}

const CartSection: FC<CartSectionProps> = ({applications, setCurrentApplication}) => {
    const t = useTrans();
    const openEdit = (item: IApplication) =>  {
        setCurrentApplication({
            application_id: item.id,
            date_id: item.dates[0].id,
            building_id: item.building_id
        })
    }

    const columns = [
        {
            header: t('bookingfrontend.where'),
            accessorKey: 'building_name',
        },
        {
            header: t('bookingfrontend.resources'),
            accessorKey: 'resources',
            cell: ({row}: any) => (
                <ResourceCircles resources={row.original.resources} maxCircles={4} size={'small'} />
            ),
        },
        {
            header: t('bookingfrontend.when'),
            accessorKey: 'dates',
            cell: ({row}: any) => (
                <div>
                    {row.original.dates.map((date: any) => (
                        <div key={date.id}>
                            {DateTime.fromISO(date.from_).toFormat('dd.MM.yyyy HH:mm')} -
                            {DateTime.fromISO(date.to_).toFormat('HH:mm')}
                        </div>
                    ))}
                </div>
            ),
        },
    ];

    return (
        <section className={styles.cartSection}>
            <h2>{t('bookingfrontend.your_applications')}</h2>
            <ShoppingCartTable basketData={applications} openEdit={openEdit} />
        </section>
    );
};

export default CartSection;