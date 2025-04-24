import React, {FC, useState, useMemo, useEffect} from 'react';
import {Button, Checkbox, Fieldset, Textfield} from '@digdir/designsystemet-react';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {ISearchDataActivity} from '@/service/types/api/search.types';
import styles from './resource-search.module.scss';

interface ActivityFilterWithLimitProps {
    activities: ISearchDataActivity[];
    selectedActivities: number[];
    setSelectedActivities: React.Dispatch<React.SetStateAction<number[]>>;
}

const ActivityFilterWithLimit: FC<ActivityFilterWithLimitProps> = ({
    activities: activitiesWithResources,
    selectedActivities,
    setSelectedActivities
}) => {
    const t = useTrans();
    // Track how many activities to show initially
    const [showAllActivities, setShowAllActivities] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const INITIAL_ACTIVITIES_LIMIT = 15;

    // Filter activities based on search query
    const filteredActivities = useMemo(() => {
        if (!searchQuery.trim()) {
            return activitiesWithResources;
        }

        const query = searchQuery.toLowerCase();

        return activitiesWithResources.filter(activity =>
            activity.name.toLowerCase().includes(query)
        );
    }, [activitiesWithResources, searchQuery]);

    // Define ActivityGroup type
    type ActivityGroup = {
        parentActivity: ISearchDataActivity | null;
        isNoGroup: boolean;
        children: ISearchDataActivity[];
        hasSelected: boolean; // Track if this group has any selected activities
    };

    // Memoize activity groups to prevent recalculation on every render
    const activityGroups = useMemo(() => {
        // Create a set of all activity IDs to check for valid parents
        const activityIds = new Set(filteredActivities.map(a => a.id));

        // Group activities by parent_id
        const parentActivities = filteredActivities.filter(a => a.parent_id === 0 || a.parent_id === undefined);
        const childActivitiesMap = new Map<number, typeof filteredActivities>();

        // Group child activities by their parent
        filteredActivities
            .filter(a => a.parent_id !== 0 && a.parent_id !== undefined)
            .forEach(activity => {
                if (!childActivitiesMap.has(activity.parent_id)) {
                    childActivitiesMap.set(activity.parent_id, []);
                }
                childActivitiesMap.get(activity.parent_id)?.push(activity);
            });

        const groups: ActivityGroup[] = [];

        // Identify activities with parent_id that doesn't exist in our activity list
        // This happens when the parent activity doesn't exist or isn't active
        const activitiesWithInvalidParents = filteredActivities
            .filter(a =>
                a.parent_id !== 0 &&
                a.parent_id !== undefined &&
                !activityIds.has(a.parent_id) &&
                !parentActivities.some(p => p.id === a.parent_id)
            );

        // Add parent activities with their children
        parentActivities.forEach(parentActivity => {
            const children = childActivitiesMap.get(parentActivity.id) || [];

            // Check if parent or any children are selected
            const parentIsSelected = selectedActivities.includes(parentActivity.id);
            const anyChildrenSelected = children.some(child =>
                selectedActivities.includes(child.id)
            );

            groups.push({
                parentActivity,
                isNoGroup: false,
                children,
                hasSelected: parentIsSelected || anyChildrenSelected
            });
        });

        // Add no-group activities
        if (activitiesWithInvalidParents.length > 0) {
            // Check if any no-group activities are selected
            const anyNoGroupSelected = activitiesWithInvalidParents.some(activity =>
                selectedActivities.includes(activity.id)
            );

            groups.push({
                parentActivity: null,
                isNoGroup: true,
                children: activitiesWithInvalidParents,
                hasSelected: anyNoGroupSelected
            });
        }

        return groups;
    }, [filteredActivities, selectedActivities]);

    // Initial sorting when modal opens
    const [initialSortComplete, setInitialSortComplete] = useState(false);
    const [sortedGroups, setSortedGroups] = useState<ActivityGroup[]>([]);

    // Create a stable sorted list on first render (when modal opens)
    useEffect(() => {
        if (!initialSortComplete) {
            const sorted = [...activityGroups].sort((a, b) => {
                // First sort by whether they have selected items
                if (a.hasSelected && !b.hasSelected) return -1;
                if (!a.hasSelected && b.hasSelected) return 1;

                // Then sort by name for consistent display
                if (a.parentActivity && b.parentActivity) {
                    return a.parentActivity.name.localeCompare(b.parentActivity.name);
                }

                // No-group should appear last if no selections
                if (a.isNoGroup) return 1;
                if (b.isNoGroup) return -1;

                return 0;
            });

            setSortedGroups(sorted);
            setInitialSortComplete(true);
        }
    }, []);

    // Update sorted groups when the filtered activities change
    // (but maintain the same order - no resorting)
    useEffect(() => {
        if (initialSortComplete) {
            setSortedGroups(activityGroups);
        }
    }, [initialSortComplete, searchQuery, filteredActivities]);

    // Count total activity items (counting each parent + each child separately)
    const countTotalItems = (groups: ActivityGroup[]): number => {
        return groups.reduce((total, group) => {
            // Count parent (or "No group" title) as 1
            return total + 1 + group.children.length;
        }, 0);
    };

    // Limit groups to display a maximum of INITIAL_ACTIVITIES_LIMIT items
    const limitGroups = (groups: ActivityGroup[], limit: number): ActivityGroup[] => {
        if (showAllActivities) {
            return groups;
        }

        const result: ActivityGroup[] = [];
        let itemCount = 0;

        for (const group of groups) {
            // If adding this group would exceed the limit
            const groupSize = 1 + group.children.length; // Parent + children

            if (itemCount + groupSize <= limit) {
                // Whole group fits within limit
                result.push(group);
                itemCount += groupSize;
            } else if (itemCount < limit) {
                // Only part of this group fits - we need to truncate children
                const remainingItems = limit - itemCount - 1; // -1 for parent

                if (remainingItems > 0) {
                    // Add parent with limited children
                    result.push({
                        ...group,
                        children: group.children.slice(0, remainingItems)
                    });
                }

                // We've reached our limit
                break;
            } else {
                // We've already reached our limit
                break;
            }
        }

        return result;
    };

    // Use the sorted groups if initial sort is complete, otherwise use unsorted
    const groupsForDisplay = initialSortComplete ? sortedGroups : activityGroups;

    // Calculate total before limiting
    const totalItems = countTotalItems(groupsForDisplay);
    const hasMoreToShow = totalItems > INITIAL_ACTIVITIES_LIMIT;

    // Limit groups to show
    const groupsToShow = showAllActivities
        ? groupsForDisplay
        : limitGroups(groupsForDisplay, INITIAL_ACTIVITIES_LIMIT);

    // Transform groups into React components
    const renderGroups = (groups: ActivityGroup[]): React.ReactNode[] => {
        return groups.map((group, index) => {
            if (group.isNoGroup) {
                // Render No-group section
                return (
                    <div key="no-group" className={styles.activityGroup}>
                        <strong className={styles.noGroupTitle}>{t('bookingfrontend.other')}</strong>
                        <div className={styles.childActivities}>
                            {group.children.map(activity => (
                                <Checkbox
                                    key={activity.id}
                                    value={activity.id.toString()}
                                    id={`activity-${activity.id}`}
                                    checked={selectedActivities.includes(activity.id)}
                                    onChange={() => {
                                        setSelectedActivities(prev =>
                                            prev.includes(activity.id)
                                                ? prev.filter(id => id !== activity.id)
                                                : [...prev, activity.id]
                                        );
                                    }}
                                    label={activity.name}
                                />
                            ))}
                        </div>
                    </div>
                );
            } else if (group.parentActivity) {
                // Render parent activity with children
                return (
                    <div key={group.parentActivity.id} className={styles.activityGroup}>
                        <Checkbox
                            value={group.parentActivity.id.toString()}
                            id={`activity-${group.parentActivity.id}`}
                            checked={selectedActivities.includes(group.parentActivity.id)}
                            onChange={() => {
                                setSelectedActivities(prev =>
                                    prev.includes(group.parentActivity!.id)
                                        ? prev.filter(id => id !== group.parentActivity!.id)
                                        : [...prev, group.parentActivity!.id]
                                );
                            }}
                            label={<strong>{group.parentActivity.name}</strong>}
                        />

                        {group.children.length > 0 && (
                            <div className={styles.childActivities}>
                                {group.children.map(childActivity => (
                                    <Checkbox
                                        key={childActivity.id}
                                        value={childActivity.id.toString()}
                                        id={`activity-${childActivity.id}`}
                                        checked={selectedActivities.includes(childActivity.id)}
                                        onChange={() => {
                                            setSelectedActivities(prev =>
                                                prev.includes(childActivity.id)
                                                    ? prev.filter(id => id !== childActivity.id)
                                                    : [...prev, childActivity.id]
                                            );
                                        }}
                                        label={childActivity.name}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                );
            }

            return null;
        }).filter(Boolean);
    };

    // Convert groups to React nodes
    const activitiesToShow = renderGroups(groupsToShow);

    if (activitiesWithResources.length === 0) {
        return null;
    }

    return (
        <Fieldset>
            <Fieldset.Legend>{t('booking.activities')}</Fieldset.Legend>

            {/* Add search input for activities */}
            <div className={styles.filterSearch}>
                <Textfield
                    label=""
					data-size="sm"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    placeholder={t('bookingfrontend.search_activities')}
                />
                <Button
                    variant="tertiary"
					data-size="sm"
                    onClick={() => setSearchQuery('')}
                    className={styles.clearSearchButton}
                    disabled={!searchQuery}
                >
                    {t('bookingfrontend.clear')}
                </Button>
            </div>

            <div className={styles.checkboxGroup}>
                {activitiesToShow.length > 0 ? (
                    <>
                        {activitiesToShow}

                        {hasMoreToShow && (
                            showAllActivities ? (
                                <Button
                                    variant="tertiary"
                                    onClick={() => setShowAllActivities(false)}
                                    className={styles.showMoreButton}
                                >
                                    {t('bookingfrontend.show_less')}
                                </Button>
                            ) : (
                                <Button
                                    variant="tertiary"
                                    onClick={() => setShowAllActivities(true)}
                                    className={styles.showMoreButton}
                                >
                                    {t('bookingfrontend.show_more')} ({totalItems - INITIAL_ACTIVITIES_LIMIT} {t('bookingfrontend.more')})
                                </Button>
                            )
                        )}
                    </>
                ) : (
                    <div className={styles.noFilterResults}>
                        {t('bookingfrontend.no_activities_match')}
                    </div>
                )}
            </div>
        </Fieldset>
    );
};

export default ActivityFilterWithLimit;