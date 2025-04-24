import React, {FC, useEffect, useMemo, useState} from 'react';
import {Button} from '@digdir/designsystemet-react';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {ISearchDataOptimized} from '@/service/types/api/search.types';
import MobileDialog from "@/components/dialog/mobile-dialog";
import ActivityFilterWithLimit from './activity-filter';
import FacilityFilterWithLimit from './facility-filter';
import styles from './resource-search.module.scss';

interface FilterModalProps {
    open: boolean;
    onClose: () => void;
    searchData: ISearchDataOptimized | undefined;
    selectedActivities: number[];
    setSelectedActivities: React.Dispatch<React.SetStateAction<number[]>>;
    selectedFacilities: number[];
    setSelectedFacilities: React.Dispatch<React.SetStateAction<number[]>>;
}

const FilterModal: FC<FilterModalProps> = ({
    open,
    onClose,
    searchData,
    selectedActivities,
    setSelectedActivities,
    selectedFacilities,
    setSelectedFacilities
}) => {
    const t = useTrans();

    // Get activities that are associated with at least one ACTIVE resource in an ACTIVE building
    const activitiesWithResources = useMemo(() => {
        if (!searchData) return [];

        // Create a set of activity IDs that have associated active resources in active buildings
        const activitiesInUse = new Set<number>();

        // Create mapping from resource ID to its connections
        const resourceToBuilding = new Map<number, number[]>();
        searchData.building_resources.forEach(br => {
            if (!resourceToBuilding.has(br.resource_id)) {
                resourceToBuilding.set(br.resource_id, []);
            }
            resourceToBuilding.get(br.resource_id)?.push(br.building_id);
        });

        // Get all active buildings
        const activeBuildings = new Set(
            searchData.buildings
                .filter(building => building.deactivate_calendar !== 1)
                .map(building => building.id)
        );

        // Get all active resources that are in active buildings
        const activeUsableResources = new Map<number, {
            name: string,
            buildingIds: number[],
            buildingNames: string[]
        }>();

        searchData.resources
            .filter(resource => resource.active === 1)
            .forEach(resource => {
                const buildingIds = resourceToBuilding.get(resource.id) || [];
                const activeResourceBuildingIds = buildingIds.filter(id => activeBuildings.has(id));

                // If resource is connected to any active building, consider it usable
                if (activeResourceBuildingIds.length > 0) {
                    const buildingNames = activeResourceBuildingIds
                        .map(id => searchData.buildings.find(b => b.id === id)?.name || '')
                        .filter(name => name !== '');

                    activeUsableResources.set(resource.id, {
                        name: resource.name,
                        buildingIds: activeResourceBuildingIds,
                        buildingNames
                    });
                }
            });

        // Check direct resource->activity connections
        searchData.resources
            .filter(resource => resource.active === 1 && resource.activity_id !== null)
            .forEach(resource => {
                // Skip resources not in active buildings
                if (!activeUsableResources.has(resource.id)) return;

                const activityId = resource.activity_id as number;
                activitiesInUse.add(activityId);
            });

        // Check resource_activities connections
        searchData.resource_activities.forEach(ra => {
            // Skip if resource is not active & in active building
            if (!activeUsableResources.has(ra.resource_id)) return;

            activitiesInUse.add(ra.activity_id);
        });

        // Check resource category activity connections
        const resourceCategoryMap = new Map<number, number[]>();

        // Create a map of resources to their categories
        searchData.resources
            .filter(resource => resource.active === 1 && resource.rescategory_id !== null)
            .forEach(resource => {
                if (activeUsableResources.has(resource.id) && resource.rescategory_id) {
                    if (!resourceCategoryMap.has(resource.rescategory_id)) {
                        resourceCategoryMap.set(resource.rescategory_id, []);
                    }
                    resourceCategoryMap.get(resource.rescategory_id)?.push(resource.id);
                }
            });

        // Check activities linked to resource categories
        searchData.resource_category_activity.forEach(rca => {
            const resourceIds = resourceCategoryMap.get(rca.rescategory_id) || [];

            // Skip if no active resources use this category
            if (resourceIds.length === 0) return;

            activitiesInUse.add(rca.activity_id);
        });

        // Find parent IDs of all activities in use
        const parentsNeeded = new Set<number>();
        searchData.activities.forEach(activity => {
            if (activitiesInUse.has(activity.id) && activity.parent_id !== 0) {
                parentsNeeded.add(activity.parent_id);
            }
        });

        // Filter the activities list to include:
        // 1. Active activities with active resources in active buildings
        // 2. Parent activities that have children with active resources
        return searchData.activities.filter(activity =>
            activity.active === 1 && (
                activitiesInUse.has(activity.id) || // This activity has resources
                parentsNeeded.has(activity.id)      // This is a parent of an activity with resources
            )
        ).sort((a, b) => a.name.localeCompare(b.name));
    }, [searchData]);

    // Get facilities that are associated with at least one ACTIVE resource in an ACTIVE building
    const facilitiesWithResources = useMemo(() => {
        if (!searchData) return [];

        // Create a set of facility IDs that have associated active resources in active buildings
        const facilitiesInUse = new Set<number>();

        // Create mapping from resource ID to its connections
        const resourceToBuilding = new Map<number, number[]>();
        searchData.building_resources.forEach(br => {
            if (!resourceToBuilding.has(br.resource_id)) {
                resourceToBuilding.set(br.resource_id, []);
            }
            resourceToBuilding.get(br.resource_id)?.push(br.building_id);
        });

        // Get all active buildings
        const activeBuildings = new Set(
            searchData.buildings
                .filter(building => building.deactivate_calendar !== 1)
                .map(building => building.id)
        );

        // Get all active resources that are in active buildings
        const activeUsableResources = new Set<number>();
        searchData.resources
            .filter(resource => resource.active === 1)
            .forEach(resource => {
                const buildingIds = resourceToBuilding.get(resource.id) || [];

                // If resource is connected to any active building, consider it usable
                if (buildingIds.some(id => activeBuildings.has(id))) {
                    activeUsableResources.add(resource.id);
                }
            });

        // Check each resource_facility connection
        searchData.resource_facilities.forEach(rf => {
            // Only include this facility if the resource is active and in an active building
            if (activeUsableResources.has(rf.resource_id)) {
                facilitiesInUse.add(rf.facility_id);
            }
        });

        // Filter the facilities list to only include those with active resources in active buildings
        return searchData.facilities.filter(facility =>
            facilitiesInUse.has(facility.id)
        );
    }, [searchData]);

    // Key to force re-mount of filter components when modal opens
    const [filterKey, setFilterKey] = useState(0);

    // Reset key when modal opens to force remounting the components
    useEffect(() => {
        if (open) {
            setFilterKey(prev => prev + 1);
        }
    }, [open]);

    return (
        <MobileDialog
            open={open}
            onClose={onClose}
            title={t('bookingfrontend.more_filters')}
            stickyFooter
            footer={(closeModal) => (
                <div className={styles.filterModalFooter}>
                    <Button
                        variant="secondary"
                        onClick={() => {
                            setSelectedActivities([]);
                            setSelectedFacilities([]);
                        }}
                    >
                        {t('bookingfrontend.search_clear_filters')}
                    </Button>
                    <Button
                        onClick={() => {
                            closeModal();
                        }}
                    >
                        {t('booking.close')}
                    </Button>
                </div>
            )}
            confirmOnClose={false}
            closeOnBackdropClick={false}
        >
            <div className={styles.filterModalContent}>
                <ActivityFilterWithLimit
                    key={`activity-filter-${filterKey}`}
                    activities={activitiesWithResources}
                    selectedActivities={selectedActivities}
                    setSelectedActivities={setSelectedActivities}
                />

                <FacilityFilterWithLimit
                    key={`facility-filter-${filterKey}`}
                    facilities={facilitiesWithResources}
                    selectedFacilities={selectedFacilities}
                    setSelectedFacilities={setSelectedFacilities}
                />
            </div>
        </MobileDialog>
    );
};

export default FilterModal;