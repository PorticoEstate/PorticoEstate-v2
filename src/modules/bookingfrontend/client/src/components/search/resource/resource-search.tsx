'use client'
import React, {FC, useMemo, useState, useEffect} from 'react';
import {useSearchData, useTowns} from "@/service/hooks/api-hooks";
import {IBuilding} from "@/service/types/Building";
import {Textfield, Select, Button, Chip, Spinner, Field, Label} from '@digdir/designsystemet-react';
import styles from './resource-search.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import CalendarDatePicker from "@/components/date-time-picker/calendar-date-picker";
import {ISearchDataBuilding, ISearchDataOptimized, ISearchDataTown, ISearchResource} from '@/service/types/api/search.types';
import ResourceResultItem from "@/components/search/resource/resource-result-item";

interface ResourceSearchProps {
	initialSearchData?: ISearchDataOptimized;
	initialTowns?: ISearchDataTown[];
}

// Interface for localStorage search state
interface StoredSearchState {
  textSearchQuery: string;
  date: string;
  where: number | '';
  timestamp: number;
}

const STORAGE_KEY = 'resource_search_state';
const STORAGE_TTL = 24 * 60 * 60 * 1000; // 24 hours in milliseconds

const ResourceSearch: FC<ResourceSearchProps> = ({ initialSearchData, initialTowns }) => {
    // Initialize state for search filters
    const [textSearchQuery, setTextSearchQuery] = useState<string>('');
    const [date, setDate] = useState<Date>(new Date());
    const [where, setWhere] = useState<number | ''>('');

    // Fetch all search data, using initialSearchData as default
    const {data: searchData, isLoading: isLoadingSearch, error: searchError} = useSearchData({
        initialData: initialSearchData
    });

    // Fetch towns data separately using the dedicated endpoint
    const {data: townsData, isLoading: isLoadingTowns, error: townsError} = useTowns({
        initialData: initialTowns
    });

    const t = useTrans();

    // Determine overall loading and error state
    const isLoading = isLoadingSearch || isLoadingTowns;
    const error = searchError || townsError;

    // Load saved search state from localStorage on initial render
    useEffect(() => {
        // Only run in browser environment
        if (typeof window !== 'undefined') {
            try {
                const savedState = localStorage.getItem(STORAGE_KEY);
                if (savedState) {
                    const parsedState: StoredSearchState = JSON.parse(savedState);

                    // Check if state is still valid (not expired)
                    const now = Date.now();
                    if (now - parsedState.timestamp < STORAGE_TTL) {
                        setTextSearchQuery(parsedState.textSearchQuery);
                        if (parsedState.date) {
                            setDate(new Date(parsedState.date));
                        }
                        setWhere(parsedState.where);
                    } else {
                        // Remove expired state
                        localStorage.removeItem(STORAGE_KEY);
                    }
                }
            } catch (e) {
                console.error('Error loading search state from localStorage:', e);
                localStorage.removeItem(STORAGE_KEY);
            }
        }
    }, []);

    // Get towns list from dedicated towns endpoint
    const towns = useMemo(() => {
        if (!townsData) return [];

        // Towns array already contains unique towns with id and name
        // Sort by name for display in dropdown
        return [...townsData].sort((a, b) => a.name.localeCompare(b.name));
    }, [townsData]);

    // Combine resources with their buildings
    const resourcesWithBuildings = useMemo(() => {
        if (!searchData) return [];

        const result: Array<ISearchResource & { building?: ISearchDataBuilding }> = [];

        // Create a mapping of building_id to building
        const buildingMap = new Map<number, ISearchDataBuilding>();
        searchData.buildings.forEach(building => {
            buildingMap.set(building.id, building);
        });

        // Connect resources to their buildings
        searchData.resources.forEach(resource => {
            const connections = searchData.building_resources.filter(
                br => br.resource_id === resource.id
            );

            connections.forEach(connection => {
                const building = buildingMap.get(connection.building_id);
                if (building) {
                    result.push({
                        ...resource,
                        building: building
                    });
                }
            });
        });

        return result;
    }, [searchData]);

    // Calculate similarity score for sorting
    const calculateSimilarity = (
        resource: ISearchResource & { building?: ISearchDataBuilding },
        query: string
    ): number => {
        const resourceName = resource.name.toLowerCase();
        const buildingName = resource.building?.name?.toLowerCase() || '';
        const queryLower = query.toLowerCase();

        // Find matching activity for this resource
        const activityName = searchData?.activities.find(activity =>
            activity.id === resource.activity_id
        )?.name.toLowerCase() || '';

        // Check resource name, building name, and activity name
        const nameToCheck = [resourceName, buildingName, activityName];
        let highestScore = 0;

        for (const name of nameToCheck) {
            // Exact match gets highest score
            if (name === queryLower) {
                return 100;
            }

            // Starts with query gets high score
            if (name.startsWith(queryLower)) {
                // Calculate how much of the string is matched
                // This will prioritize shorter strings that more closely match the query
                const matchRatio = queryLower.length / name.length;
                const score = 75 + (matchRatio * 20); // This gives higher scores to closer matches
                highestScore = Math.max(highestScore, score);
                continue;
            }

            // Contains query gets medium score
            if (name.includes(queryLower)) {
                highestScore = Math.max(highestScore, 50);
                continue;
            }

            // Partial word match gets lower score
            const words = queryLower.split(' ');
            for (const word of words) {
                if (word.length > 2 && name.includes(word)) {
                    highestScore = Math.max(highestScore, 25);
                    break;
                }
            }
        }

        return highestScore;
    };
    // Apply all filters to resources and sort by relevance
    const filteredResources = useMemo(() => {
        if (!resourcesWithBuildings.length) return [];

        // If no filters are applied, return empty array (don't show results by default)
        if (!textSearchQuery.trim() && !where) {
            return [];
        }

        let filtered = resourcesWithBuildings;

        // Only apply text search if something has been entered
        if (textSearchQuery && textSearchQuery.trim() !== '') {
            const query = textSearchQuery.toLowerCase();
            filtered = filtered.filter(resource => {
                const resourceNameMatch = resource.name.toLowerCase().includes(query);
                const buildingNameMatch = resource.building?.name?.toLowerCase().includes(query);

                // Find activity for this resource and check if it matches the query
                const activityMatch = resource.activity_id ?
                    searchData?.activities.find(activity =>
                        activity.id === resource.activity_id &&
                        activity.name.toLowerCase().includes(query)
                    ) : null;

                return resourceNameMatch || buildingNameMatch || !!activityMatch;
            });

            // Sort by relevance/similarity
            filtered.sort((a, b) => {
                const scoreA = calculateSimilarity(a, textSearchQuery);
                const scoreB = calculateSimilarity(b, textSearchQuery);
                return scoreB - scoreA;
            });
        }

        // Town filter - using building's town_id to filter
        if (where) {
            const townId = Number(where);

            // Filter resources by buildings that have the selected town_id
            filtered = filtered.filter(resource =>
                resource.building && resource.building.town_id === townId
            );
        }

        // Date availability filter would go here
        // For now, we're not implementing date filtering logic

        return filtered;
    }, [resourcesWithBuildings, textSearchQuery, where, searchData?.towns]);

    // Handle date selection
    const handleDateChange = (newDate: Date | null) => {
        if (newDate) {
            setDate(newDate);
        }
    };

    // Clear all filters
    const clearFilters = () => {
        setTextSearchQuery('');
        setWhere('');
        setDate(new Date());

        // Clear localStorage when filters are reset
        if (typeof window !== 'undefined') {
            localStorage.removeItem(STORAGE_KEY);
        }
    };

    // Save search state to localStorage whenever it changes
    useEffect(() => {
        // Only save if there's actually something to save
        if (textSearchQuery || where !== '') {
            if (typeof window !== 'undefined') {
                try {
                    const stateToSave: StoredSearchState = {
                        textSearchQuery,
                        date: date.toISOString(),
                        where,
                        timestamp: Date.now()
                    };
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(stateToSave));
                } catch (e) {
                    console.error('Error saving search state to localStorage:', e);
                }
            }
        }
    }, [textSearchQuery, date, where]);

    if (isLoading) {
        return (
            <div className={styles.loadingContainer}>
                <Spinner data-size="lg" aria-label={t('common.loading...')}/>
                <p>{t('common.loading...')}</p>
            </div>
        );
    }

    if (error) {
        return (
            <div className={styles.errorContainer}>
                <p>{t('common.error_occurred')}</p>
                <Button onClick={() => window.location.reload()}>{t('common.try_again')}</Button>
            </div>
        );
    }

    return (
        <div className={styles.resourceSearchContainer}>
            <section id="resource-filter" className={styles.filterSection}>
                <div className={styles.searchInputs}>
                    <div className={styles.searchField}>
                        <Textfield
                            label={t('common.search')}
                            value={textSearchQuery}
                            onChange={(e) => setTextSearchQuery(e.target.value)}
                            placeholder={t('bookingfrontend.search available resources')}
                        />
                    </div>

                    <div className={styles.dateFilter}>
                        <Field>
                            <Label>{t('bookingfrontend.when')}</Label>
                            <CalendarDatePicker
                                currentDate={date}
                                onDateChange={handleDateChange}
                                view="timeGridDay"
                            />
                        </Field>
                    </div>

                    <div className={styles.townFilter}>
                        <Field>
                            <Label>{t('bookingfrontend.where')}</Label>
                            <Select
                                value={where}
                                onChange={(e) => setWhere(e.target.value ? +e.target.value : '')}
                            >

                                <Select.Option value="">{t('booking.all')}</Select.Option>
                                {towns.map(town => (
                                    <Select.Option key={town.id} value={town.id.toString()}>
                                        {town.name}
                                    </Select.Option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                </div>

                {(textSearchQuery || where !== '') && (
                    <div className={styles.activeFilters}>
                        <span>{t('common.filter')}:</span>
                        <div className={styles.filterChips}>
                            {textSearchQuery && (
                                <Chip.Removable data-color="brand1" onClick={() => setTextSearchQuery('')}>
                                    {t('common.search')}: {textSearchQuery}
                                </Chip.Removable>
                            )}
                            {where && (
                                <Chip.Removable data-color="brand1" onClick={() => setWhere('')}>
                                    {t('bookingfrontend.town part')}: {towns.find(town => town.id === Number(where))?.name || ''}
                                </Chip.Removable>
                            )}
                            <Button
                                variant="tertiary"
                                data-size="sm"
                                onClick={clearFilters}
                            >
                                {t('bookingfrontend.search_clear_filters')}
                            </Button>
                        </div>
                    </div>
                )}
            </section>

            <section id="resource-results" className={styles.resultsSection}>
                {!textSearchQuery.trim() && !where ? (
                    <div className={styles.noResults}>
                        <p>{t('bookingfrontend.search_use_filters_to_search')}</p>
                    </div>
                ) : filteredResources.length > 0 ? (
                    <div className={styles.resourceGrid}>
                        {filteredResources.map(resource => (
                            <ResourceResultItem key={resource.id} resource={resource}/>
                        ))}
                    </div>
                ) : (
                    <div className={styles.noResults}>
                        <p>{t('bookingfrontend.search_no_resources_match')}</p>
                        <Button
                            variant="secondary"
                            onClick={clearFilters}
                        >
                            {t('bookingfrontend.search_clear_filters')}
                        </Button>
                    </div>
                )}
            </section>
        </div>
    );
}

export default ResourceSearch;