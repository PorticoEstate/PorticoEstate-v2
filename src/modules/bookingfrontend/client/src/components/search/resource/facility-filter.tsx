import React, {FC, useState, useMemo, useEffect} from 'react';
import {Button, Checkbox, Fieldset, Link, Textfield} from '@digdir/designsystemet-react';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {ISearchDataFacility} from '@/service/types/api/search.types';
import styles from './resource-search.module.scss';

interface FacilityFilterWithLimitProps {
    facilities: ISearchDataFacility[];
    selectedFacilities: number[];
    setSelectedFacilities: React.Dispatch<React.SetStateAction<number[]>>;
}

const FacilityFilterWithLimit: FC<FacilityFilterWithLimitProps> = ({
    facilities: facilitiesWithResources,
    selectedFacilities,
    setSelectedFacilities
}) => {
    const t = useTrans();

    // Track state for facilities filter
    const [showAllFacilities, setShowAllFacilities] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const INITIAL_FACILITIES_LIMIT = 15;

    // Filter facilities based on search query
    const filteredFacilities = useMemo(() => {
        if (!searchQuery.trim()) {
            return facilitiesWithResources;
        }

        const query = searchQuery.toLowerCase();

        return facilitiesWithResources.filter(facility =>
            facility.name.toLowerCase().includes(query)
        );
    }, [facilitiesWithResources, searchQuery]);

    // Sort facilities - only when modal opens
    const [initialSortComplete, setInitialSortComplete] = useState(false);
    const [sortedFacilities, setSortedFacilities] = useState<ISearchDataFacility[]>([]);

    // Create a stable sorted list on first render (when modal opens)
    useEffect(() => {
        if (!initialSortComplete) {
            const sorted = [...filteredFacilities].sort((a, b) => {
                // First prioritize selected facilities
                const aIsSelected = selectedFacilities.includes(a.id);
                const bIsSelected = selectedFacilities.includes(b.id);

                if (aIsSelected && !bIsSelected) return -1;
                if (!aIsSelected && bIsSelected) return 1;

                // Then sort alphabetically
                return a.name.localeCompare(b.name);
            });

            setSortedFacilities(sorted);
            setInitialSortComplete(true);
        }
    }, []);

    // Update sorted facilities when search query changes, but preserve order
    useEffect(() => {
        if (initialSortComplete) {
            setSortedFacilities(filteredFacilities);
        }
    }, [filteredFacilities]);

    // Calculate total count
    const totalFacilities = sortedFacilities.length;
    const hasMoreToShow = totalFacilities > INITIAL_FACILITIES_LIMIT;

    // Apply limit based on show all state
    const facilitiesToDisplay = showAllFacilities
        ? sortedFacilities
        : sortedFacilities.slice(0, INITIAL_FACILITIES_LIMIT);

    // Render facilities as checkboxes
    const facilitiesToShow = facilitiesToDisplay.map(facility => (
        <Checkbox
            key={facility.id}
            value={facility.id.toString()}
            id={`facility-${facility.id}`}
            checked={selectedFacilities.includes(facility.id)}
            onChange={() => {
                setSelectedFacilities(prev =>
                    prev.includes(facility.id)
                        ? prev.filter(id => id !== facility.id)
                        : [...prev, facility.id]
                );
            }}
            label={facility.name}
        />
    ));

    if (facilitiesWithResources.length === 0) {
        return null;
    }

    return (
        <Fieldset>
            <Fieldset.Legend>{t('bookingfrontend.facilities')}</Fieldset.Legend>

            {/* Add search input for facilities */}
            <div className={styles.filterSearch}>
                <Textfield
                    label=""
                    data-size="sm"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    placeholder={t('bookingfrontend.search_facilities')}
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
                {facilitiesToShow.length > 0 ? (
                    <>
                        {facilitiesToShow}

                        {hasMoreToShow && (
                            showAllFacilities ? (
                                <Button
                                    variant="tertiary"
                                    onClick={() => setShowAllFacilities(false)}
                                    className={styles.showMoreButton}
                                >
                                    {t('bookingfrontend.show_less')}
                                </Button>
                            ) : (
                                <Button
                                    variant="tertiary"
                                    onClick={() => setShowAllFacilities(true)}
                                    className={styles.showMoreButton}
                                >
                                    {t('bookingfrontend.show_more')} ({totalFacilities - INITIAL_FACILITIES_LIMIT} {t('bookingfrontend.more')})
                                </Button>
                            )
                        )}
                    </>
                ) : (
                    <div className={styles.noFilterResults}>
                        {t('bookingfrontend.no_facilities_match')}
                    </div>
                )}
            </div>
        </Fieldset>
    );
};

export default FacilityFilterWithLimit;