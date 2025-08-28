// app/[lang]/(public)/checkout/components/cart-section.tsx
import {Dispatch, FC} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {IApplication} from "@/service/types/api/application.types";
import styles from './checkout.module.scss';
import ShoppingCartTable from "@/components/layout/header/shopping-cart/shopping-cart-table";

interface CartSectionProps {
    applications: IApplication[];
    setCurrentApplication: Dispatch<{ application_id: number, date_id: number, building_id: number } | undefined>;
    selectedParentId?: number;
    onParentIdChange?: (parentId: number) => void;
}

const CartSection: FC<CartSectionProps> = ({applications, setCurrentApplication, selectedParentId, onParentIdChange}) => {
    const t = useTrans();
    const openEdit = (item: IApplication) =>  {
        setCurrentApplication({
            application_id: item.id,
            date_id: item.dates[0].id,
            building_id: item.building_id
        })
    }

    return (
        <section className={styles.cartSection}>
            <h2>{t('bookingfrontend.your_applications')}</h2>
            <p style={{marginBottom: '1rem', fontSize: '0.9rem', color: '#666'}}>
                {t('bookingfrontend.select_main_application_note')}
            </p>
            <ShoppingCartTable 
                basketData={applications} 
                openEdit={openEdit}
                showParentSelection={true}
                selectedParentId={selectedParentId}
                onParentIdChange={onParentIdChange}
            />
        </section>
    );
};

export default CartSection;