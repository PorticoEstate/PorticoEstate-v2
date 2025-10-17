// app/[lang]/(public)/checkout/components/cart-section.tsx
import {Dispatch, FC, useMemo} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {IApplication} from "@/service/types/api/application.types";
import styles from './checkout.module.scss';
import ShoppingCartTable from "@/components/layout/header/shopping-cart/shopping-cart-table";
import { calculateApplicationCost, formatCurrency } from "@/utils/cost-utils";
import { RecurringInfoUtils, calculateRecurringInstances } from '@/utils/recurring-utils';
import { useBuildingSeasons } from "@/service/hooks/api-hooks";

interface CartSectionProps {
    applications: IApplication[];
    setCurrentApplication: Dispatch<{ application_id: number, date_id: number, building_id: number } | undefined>;
    buildingParentIds?: Record<number, number>;
    onBuildingParentIdChange?: (buildingId: number, parentId: number) => void;
}

const CartSection: FC<CartSectionProps> = ({applications, setCurrentApplication, buildingParentIds, onBuildingParentIdChange}) => {
    const t = useTrans();

    // Fetch seasons for all unique buildings
    const buildingIds = [...new Set(applications.map(item => item.building_id))];
    const seasonsQueries = buildingIds.map(buildingId => {
        // eslint-disable-next-line react-hooks/rules-of-hooks
        return useBuildingSeasons(buildingId);
    });

    // Create a map of building_id to seasons for easy lookup
    const seasonsMap = new Map();
    buildingIds.forEach((buildingId, index) => {
        seasonsMap.set(buildingId, seasonsQueries[index]?.data);
    });

    const openEdit = (item: IApplication) =>  {
        setCurrentApplication({
            application_id: item.id,
            date_id: item.dates[0].id,
            building_id: item.building_id
        })
    }

    // Calculate total cost for a list of applications
    const calculateTotal = (apps: IApplication[]): number => {
        return apps.reduce((total, app) => {
            const cost = calculateApplicationCost(app);

            if (RecurringInfoUtils.isRecurring(app)) {
                const seasons = seasonsMap.get(app.building_id);
                const recurringInstances = calculateRecurringInstances(app, seasons);
                return total + (cost * recurringInstances.length);
            }

            return total + cost;
        }, 0);
    };

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

    const grandTotal = calculateTotal(applications);

    return (
        <div>
            {/* Regular Applications Sections - Grouped by Building */}
            {regularApplicationsByBuilding.map((buildingGroup, index) => {
                const sectionTotal = calculateTotal(buildingGroup.applications);
                return (
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
                        {sectionTotal > 0 && (
                            <div className={styles.sectionTotal}>
                                <strong>{t('bookingfrontend.total')}:</strong>
                                <strong>{formatCurrency(sectionTotal)}</strong>
                            </div>
                        )}
                    </section>
                );
            })}

            {/* Recurring Applications Section */}
            {recurringApplications.length > 0 && (() => {
                const recurringTotal = calculateTotal(recurringApplications);
                return (
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
                        {recurringTotal > 0 && (
                            <div className={styles.sectionTotal}>
                                <strong>{t('bookingfrontend.total')}:</strong>
                                <strong>{formatCurrency(recurringTotal)}</strong>
                            </div>
                        )}
                    </section>
                );
            })()}

            {/* Grand Total */}
            {grandTotal > 0 && (regularApplicationsByBuilding.length > 0 || recurringApplications.length > 0) && (
                <div className={styles.grandTotal}>
                    <strong>{t('bookingfrontend.grand_total')}:</strong>
                    <strong>{formatCurrency(grandTotal)}</strong>
                </div>
            )}
        </div>
    );
};

export default CartSection;