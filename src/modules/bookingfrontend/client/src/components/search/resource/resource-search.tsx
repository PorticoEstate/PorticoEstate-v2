'use client'
import React, {FC, useMemo, useState} from 'react';
import {useSearchData} from "@/service/hooks/api-hooks";
import {IBuilding} from "@/service/types/Building";
import {Textfield, Select, Button, Chip, Spinner, Field, Label} from '@digdir/designsystemet-react';
import {DateTime} from 'luxon';
import styles from './resource-search.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import ColourCircle from '@/components/building-calendar/modules/colour-circle/colour-circle';
import CalendarDatePicker from "@/components/date-time-picker/calendar-date-picker";
import {ISearchDataBuilding} from '@/service/types/api/search.types';
import ResourceResultItem from "@/components/search/resource/resource-result-item";

interface ResourceSearchProps {
}

const ResourceSearch: FC<ResourceSearchProps> = () => {
    // State for search filters
    const [textSearchQuery, setTextSearchQuery] = useState<string>('');
    const [date, setDate] = useState<Date>(new Date());
    const [where, setWhere] = useState<IBuilding['district'] | ''>('');

    // Fetch all search data
    const {data: searchData, isLoading, error} = useSearchData();
    const t = useTrans();

    // Extract unique districts from buildings
    const districts = useMemo(() => {
        if (!searchData?.buildings) return [];

        const uniqueDistricts = Array.from(
            new Set(searchData.buildings.map(building => building.district))
        ).filter(Boolean);

        return uniqueDistricts.sort();
    }, [searchData?.buildings]);

    // Combine resources with their buildings
    const resourcesWithBuildings = useMemo(() => {
        if (!searchData) return [];

        const result: Array<IResource & { building?: ISearchDataBuilding }> = [];

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
        resource: IResource & { building?: ISearchDataBuilding },
        query: string
    ): number => {
        const resourceName = resource.name.toLowerCase();
        const buildingName = resource.building?.name?.toLowerCase() || '';
        const queryLower = query.toLowerCase();

        // Check both resource name and building name
        const nameToCheck = [resourceName, buildingName];
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
                return resourceNameMatch || buildingNameMatch;
            });

            // Sort by relevance/similarity
            filtered.sort((a, b) => {
                const scoreA = calculateSimilarity(a, textSearchQuery);
                const scoreB = calculateSimilarity(b, textSearchQuery);
                return scoreB - scoreA;
            });
        }

        // District filter
        if (where) {
            filtered = filtered.filter(resource => resource.building?.district === where);
        }

        // Date availability filter would go here
        // For now, we're not implementing date filtering logic

        return filtered;
    }, [resourcesWithBuildings, textSearchQuery, where]);

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
    };

    if (isLoading) {
        return (
            <div className={styles.loadingContainer}>
                <Spinner data-size="lg" aria-label={t('common.loading')}/>
                <p>{t('common.loading')}</p>
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
                            label={t('search.search_resources')}
                            value={textSearchQuery}
                            onChange={(e) => setTextSearchQuery(e.target.value)}
                            placeholder={t('search.search_by_name_or_location')}
                        />
                    </div>

                    <div className={styles.dateFilter}>
                        <Field>
                            <Label>{t('search.available_on_date')}</Label>
                            <CalendarDatePicker
                                currentDate={date}
                                onDateChange={handleDateChange}
                                view="timeGridDay"
                            />
                        </Field>
                    </div>

                    <div className={styles.districtFilter}>
                        <Field>
                            <Label>{t('search.available_on_date')}</Label>
                            <Select
                                value={where}
                                onChange={(e) => setWhere(e.target.value)}
                            >

                                <Select.Option value="">{t('search.all_districts')}</Select.Option>
                                {districts.map(district => (
                                    <Select.Option key={district} value={district}>
                                        {district}
                                    </Select.Option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                </div>

                {(textSearchQuery || where !== '') && (
                    <div className={styles.activeFilters}>
                        <span>{t('search.active_filters')}:</span>
                        <div className={styles.filterChips}>
                            {textSearchQuery && (
                                <Chip.Removable data-color="brand1" onClick={() => setTextSearchQuery('')}>
                                    {t('search.search')}: {textSearchQuery}
                                </Chip.Removable>
                            )}
                            {where && (
                                <Chip.Removable data-color="brand1" onClick={() => setWhere('')}>
                                    {t('search.district')}: {where}
                                </Chip.Removable>
                            )}
                            <Button
                                variant="tertiary"
                                data-size="sm"
                                onClick={clearFilters}
                            >
                                {t('search.clear_all_filters')}
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