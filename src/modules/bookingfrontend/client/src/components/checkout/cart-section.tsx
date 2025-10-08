// app/[lang]/(public)/checkout/components/cart-section.tsx
import {Dispatch, FC, useMemo} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {IApplication} from "@/service/types/api/application.types";
import styles from './checkout.module.scss';
import ShoppingCartTable from "@/components/layout/header/shopping-cart/shopping-cart-table";

interface CartSectionProps {
    applications: IApplication[];
    setCurrentApplication: Dispatch<{ application_id: number, date_id: number, building_id: number } | undefined>;
    buildingParentIds?: Record<number, number>;
    onBuildingParentIdChange?: (buildingId: number, parentId: number) => void;
}

const CartSection: FC<CartSectionProps> = ({applications, setCurrentApplication, buildingParentIds, onBuildingParentIdChange}) => {
    const t = useTrans();
    const openEdit = (item: IApplication) =>  {
        setCurrentApplication({
            application_id: item.id,
            date_id: item.dates[0].id,
            building_id: item.building_id
        })
    }

    // Separate recurring and regular applications, then group regular applications by building
    const {regularApplicationsByBuilding, recurringApplications} = useMemo(() => {
        const regular: IApplication[] = [];
        const recurring: IApplication[] = [];
        
        applications.forEach(app => {
            if (app.recurring_info && app.recurring_info.trim() !== '') {
                recurring.push(app);
            } else {
                regular.push(app);
            }
        });
        
        // Group regular applications by building_id
        const groupedByBuilding = new Map<number, IApplication[]>();
        regular.forEach(app => {
            const buildingId = app.building_id;
            if (!groupedByBuilding.has(buildingId)) {
                groupedByBuilding.set(buildingId, []);
            }
            groupedByBuilding.get(buildingId)!.push(app);
        });
        
        return {
            regularApplicationsByBuilding: Array.from(groupedByBuilding.entries()).map(([buildingId, apps]) => ({
                buildingId,
                buildingName: apps[0].building_name,
                applications: apps
            })),
            recurringApplications: recurring
        };
    }, [applications]);

    return (
        <div>
            {/* Regular Applications Sections - Grouped by Building */}
            {regularApplicationsByBuilding.map((buildingGroup, index) => (
                <section key={buildingGroup.buildingId} className={styles.cartSection} style={{marginTop: index > 0 ? '2rem' : '0'}}>
                    <h2>{buildingGroup.buildingName}</h2>
                    <p style={{marginBottom: '1rem', fontSize: '0.9rem', color: '#666'}}>
                        {t('bookingfrontend.select_main_application_note')} {t('bookingfrontend.building_parent_constraint_note')}
                    </p>
                    <ShoppingCartTable 
                        basketData={buildingGroup.applications} 
                        openEdit={openEdit}
                        showParentSelection={true}
                        selectedParentId={buildingParentIds?.[buildingGroup.buildingId]}
                        onParentIdChange={onBuildingParentIdChange ? (parentId) => onBuildingParentIdChange(buildingGroup.buildingId, parentId) : undefined}
                        buildingId={buildingGroup.buildingId}
                    />
                </section>
            ))}
            
            {/* Recurring Applications Section */}
            {recurringApplications.length > 0 && (
                <section className={styles.cartSection} style={{marginTop: regularApplicationsByBuilding.length > 0 ? '2rem' : '0'}}>
                    <h2>{t('bookingfrontend.recurring_applications')}</h2>
                    <p style={{marginBottom: '1rem', fontSize: '0.9rem', color: '#666'}}>
                        {t('bookingfrontend.recurring_applications_note')}
                    </p>
                    <ShoppingCartTable 
                        basketData={recurringApplications} 
                        openEdit={openEdit}
                        showParentSelection={false}
                        selectedParentId={undefined}
                        onParentIdChange={undefined}
                    />
                </section>
            )}
        </div>
    );
};

export default CartSection;