'use client'
import React, {FC, useMemo, useState, useEffect} from 'react';
import {useSearchData, useTowns, useMultiDomains, useAvailableResourcesMultiDomain} from "@/service/hooks/api-hooks";
import {Textfield, Select, Button, Chip, Spinner, Field, Label} from '@digdir/designsystemet-react';
import styles from './resource-search.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import CalendarDatePicker from "@/components/date-time-picker/calendar-date-picker";
import {ISearchDataBuilding, ISearchDataOptimized, ISearchDataTown, ISearchResource} from '@/service/types/api/search.types';
import {IMultiDomain} from '@/service/types/api.types';
import ResourceResultItem from "@/components/search/resource/resource-result-item";
import FilterModal from './filter-modal';
import {FilterIcon} from '@navikt/aksel-icons';
import {useIsMobile} from "@/service/hooks/is-mobile";

interface ResourceSearchProps {
    initialSearchData?: ISearchDataOptimized;
    initialTowns?: ISearchDataTown[];
    initialMultiDomains?: IMultiDomain[];
}

// Interface for localStorage search state
interface StoredSearchState {
    textSearchQuery: string;
    date: string | null;
    where: number | '';
    selectedActivities: number[];
    selectedFacilities: number[];
    timestamp: number;
}

const STORAGE_KEY = 'resource_search_state';
const STORAGE_TTL = 24 * 60 * 60 * 1000; // 24 hours in milliseconds

const ResourceSearch: FC<ResourceSearchProps> = ({ initialSearchData, initialTowns, initialMultiDomains }) => {
    // Initialize state for search filters
    const [textSearchQuery, setTextSearchQuery] = useState<string>('');
    const [date, setDate] = useState<Date | null>(null);
    const [where, setWhere] = useState<number | ''>('');
    const [filtersModalOpen, setFiltersModalOpen] = useState<boolean>(false);
    const [selectedActivities, setSelectedActivities] = useState<number[]>([]);
    const [selectedFacilities, setSelectedFacilities] = useState<number[]>([]);
    const [isSearching, setIsSearching] = useState<boolean>(false);
    const [searchFieldScrolled, setSearchFieldScrolled] = useState<boolean>(false);
    const isMobile = useIsMobile();

    // Add scroll event listener for mobile
    useEffect(() => {
        const handleScroll = () => {
            if (window.innerWidth <= 768) {
                setSearchFieldScrolled(window.scrollY > 50);
            }
        };

        window.addEventListener('scroll', handleScroll);
        return () => window.removeEventListener('scroll', handleScroll);
    }, []);

    // Fetch all search data, using initialSearchData as default
    const {data: searchData, isLoading: isLoadingSearch, error: searchError} = useSearchData({
        initialData: initialSearchData
    });

    // Fetch towns data separately using the dedicated endpoint
    const {data: townsData, isLoading: isLoadingTowns, error: townsError} = useTowns({
        initialData: initialTowns
    });

    // Fetch multi-domains data for cross-domain search
    const {data: multiDomainsData, isLoading: isLoadingMultiDomains, error: multiDomainsError} = useMultiDomains({
        initialData: initialMultiDomains
    });

    // Format date for API call (YYYY-MM-DD)
    const formattedDate = date ? date.toISOString().split('T')[0] : undefined;

    // Fetch available resources for the selected date across all domains
    const {data: availableResourcesByDomain} = useAvailableResourcesMultiDomain(formattedDate, multiDomainsData);

    const t = useTrans();

    // Determine overall loading and error state
    const isLoading = isLoadingSearch || isLoadingTowns || isLoadingMultiDomains;
    const error = searchError || townsError || multiDomainsError;

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
                        } else {
                            setDate(null);
                        }
                        setWhere(parsedState.where);
                        if (parsedState.selectedActivities) {
                            setSelectedActivities(parsedState.selectedActivities);
                        }
                        if (parsedState.selectedFacilities) {
                            setSelectedFacilities(parsedState.selectedFacilities);
                        }
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
        if (!textSearchQuery.trim() && !where && !date && selectedActivities.length === 0 && selectedFacilities.length === 0) {
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

        // Activity filter - resource must have ALL selected activities (AND logic)
        if (selectedActivities.length > 0) {
            filtered = filtered.filter(resource => {
                const matchingActivities = new Set<number>();

                // Check direct resource.activity_id match
                if (resource.activity_id && selectedActivities.includes(resource.activity_id)) {
                    matchingActivities.add(resource.activity_id);
                }

                // Check resource_activities direct connections
                const resourceActivities = searchData?.resource_activities.filter(
                    ra => ra.resource_id === resource.id
                );

                resourceActivities?.forEach(ra => {
                    if (selectedActivities.includes(ra.activity_id)) {
                        matchingActivities.add(ra.activity_id);
                    }
                });

                // Check resource category activity connections
                if (resource.rescategory_id) {
                    // Get all activities linked to this resource's category
                    const categoryActivities = searchData?.resource_category_activity.filter(
                        rca => rca.rescategory_id === resource.rescategory_id
                    );

                    // Add any matching activities from the resource's category
                    categoryActivities?.forEach(ca => {
                        if (selectedActivities.includes(ca.activity_id)) {
                            matchingActivities.add(ca.activity_id);
                        }
                    });
                }

                // Resource must have ALL selected activities
                return selectedActivities.every(activityId =>
                    matchingActivities.has(activityId)
                );
            });
        }

        // Facility filter - resource must have ALL selected facilities (AND logic)
        if (selectedFacilities.length > 0) {
            filtered = filtered.filter(resource => {
                // Check resource_facilities connection
                const resourceFacilities = searchData?.resource_facilities.filter(
                    rf => rf.resource_id === resource.id
                );

                // Resource must have ALL selected facilities
                return selectedFacilities.every(facilityId =>
                    resourceFacilities?.some(rf => rf.facility_id === facilityId)
                );
            });
        }

        // Sort by availability - available resources first
        if (availableResourcesByDomain) {
            filtered.sort((a, b) => {
                // Determine which domain this resource belongs to
                const aDomain = a.domain_name || 'local';
                const bDomain = b.domain_name || 'local';

                // Check if resource is available in its respective domain
                const aIsAvailable = availableResourcesByDomain[aDomain]?.includes(a.original_id || a.id) || false;
                const bIsAvailable = availableResourcesByDomain[bDomain]?.includes(b.original_id || b.id) || false;

                // If both available or both unavailable, maintain current order (relevance)
                if (aIsAvailable === bIsAvailable) return 0;

                // Available resources come first
                return bIsAvailable ? 1 : -1;
            });
        }

        return filtered;
    }, [resourcesWithBuildings, textSearchQuery, where, selectedActivities, selectedFacilities, searchData, availableResourcesByDomain]);

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
        setDate(null);
        setSelectedActivities([]);
        setSelectedFacilities([]);

        // Clear localStorage when filters are reset
        if (typeof window !== 'undefined') {
            localStorage.removeItem(STORAGE_KEY);

            // Create a clean state to ensure complete reset
            const cleanState: StoredSearchState = {
                textSearchQuery: '',
                date: null,
                where: '',
                selectedActivities: [],
                selectedFacilities: [],
                timestamp: Date.now()
            };

            // Store the clean state
            localStorage.setItem(STORAGE_KEY, JSON.stringify(cleanState));
        }
    };

    // Save search state to localStorage whenever it changes
    useEffect(() => {
        // Save state even if only activities or facilities are selected
        if (textSearchQuery || where !== '' || selectedActivities.length > 0 || selectedFacilities.length > 0) {
            if (typeof window !== 'undefined') {
                try {
                    const stateToSave: StoredSearchState = {
                        textSearchQuery,
                        date: date ? date.toISOString() : null,
                        where,
                        selectedActivities,
                        selectedFacilities,
                        timestamp: Date.now()
                    };
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(stateToSave));
                } catch (e) {
                    console.error('Error saving search state to localStorage:', e);
                }
            }
        }
    }, [textSearchQuery, date, where, selectedActivities, selectedFacilities]);

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

    // Render active filter chips in a more compact way
    const renderActiveFilters = () => {
        if (!textSearchQuery && where === '' && !date && selectedActivities.length === 0 && selectedFacilities.length === 0) {
            return null;
        }

        // Count total active filters
        const totalFilters = [
            textSearchQuery ? 1 : 0,
            where ? 1 : 0,
            date ? 1 : 0,
            selectedActivities.length,
            selectedFacilities.length
        ].reduce((sum, current) => sum + current, 0);

        return (
            <div className={styles.activeFilters}>
                {/* Only show clear filters button if there are filters other than textSearchQuery */}
                {(where || date || selectedActivities.length > 0 || selectedFacilities.length > 0) && (
                    <div className={styles.filterSummary}>
                        <Button
                            variant="tertiary"
                            data-size="sm"
                            onClick={clearFilters}
                            className={styles.clearFiltersButton}
                            aria-label={t('bookingfrontend.search_clear_filters')}
                        >
                            {isMobile ? '✕' : t('bookingfrontend.search_clear_filters')}
                        </Button>
                    </div>
                )}

                <div className={styles.filterChips}>
                    {textSearchQuery && !isMobile && (
                        <Chip.Removable
                            data-color="brand1"
                            data-size="sm"
                            onClick={() => setTextSearchQuery('')}
                        >
                            {textSearchQuery}
                        </Chip.Removable>
                    )}
                    {where && (
                        <Chip.Removable
                            data-color="brand1"
                            data-size="sm"
                            onClick={() => setWhere('')}
                        >
                            {towns.find(town => town.id === Number(where))?.name || ''}
                        </Chip.Removable>
                    )}
                    {date && (
                        <Chip.Removable
                            data-color="brand1"
                            data-size="sm"
                            onClick={() => setDate(null)}
                        >
                            {date.toLocaleDateString()}
                        </Chip.Removable>
                    )}
                    {selectedActivities.map(activityId => {
                        const activity = searchData?.activities.find(a => a.id === activityId);
                        return activity ? (
                            <Chip.Removable
                                key={`activity-${activityId}`}
                                data-color="brand1"
                                data-size="sm"
                                onClick={() => setSelectedActivities(prev => prev.filter(id => id !== activityId))}
                            >
                                {activity.name}
                            </Chip.Removable>
                        ) : null;
                    })}
                    {selectedFacilities.map(facilityId => {
                        const facility = searchData?.facilities.find(f => f.id === facilityId);
                        return facility ? (
                            <Chip.Removable
                                key={`facility-${facilityId}`}
                                data-color="brand1"
                                data-size="sm"
                                onClick={() => setSelectedFacilities(prev => prev.filter(id => id !== facilityId))}
                            >
                                {facility.name}
                            </Chip.Removable>
                        ) : null;
                    })}
                </div>
            </div>
        );
    };

    // Show total number of searchable domains for user awareness
    const renderSearchInfo = () => {
        if (!multiDomainsData || multiDomainsData.length === 0) return null;

        const totalDomains = multiDomainsData.length + 1; // +1 for current domain

        return (
            <div className={styles.searchInfo}>
                <p>{t('bookingfrontend.searching_across_domains', { count: totalDomains }) || `Searching across ${totalDomains} domains`}</p>
            </div>
        );
    };

    // Render search results section
    const renderSearchResults = () => {
        if (!textSearchQuery.trim() && !where && !date && selectedActivities.length === 0 && selectedFacilities.length === 0) {
            return (
                <div className={styles.noResults}>
                    <p>{t('bookingfrontend.search_use_filters_to_search')}</p>
                    {/*{renderSearchInfo()}*/}
                </div>
            );
        }

        if (filteredResources.length === 0) {
            return (
                <div className={styles.noResults}>
                    <p>{t('bookingfrontend.search_no_resources_match')}</p>
                    <Button
                        variant="secondary"
                        onClick={clearFilters}
                    >
                        {t('bookingfrontend.search_clear_filters')}
                    </Button>
                    {/*{renderSearchInfo()}*/}
                </div>
            );
        }

        // Calculate result statistics
        const localResults = filteredResources.filter(r => !r.domain_name);
        const externalResults = filteredResources.filter(r => r.domain_name);

        return (
            <div className={styles.resultsContainer}>
                {/*{(localResults.length > 0 && externalResults.length > 0) && (*/}
                {/*    <div className={styles.resultsStats}>*/}
                {/*        <p>{t('bookingfrontend.mixed_results_info', { */}
                {/*            local: localResults.length, */}
                {/*            external: externalResults.length, */}
                {/*            total: filteredResources.length */}
                {/*        }) || `Showing ${filteredResources.length} results (${localResults.length} local, ${externalResults.length} from other domains)`}</p>*/}
                {/*    </div>*/}
                {/*)}*/}

                <div className={styles.resourceGrid}>
                    {filteredResources.map(resource => {
                        // Determine availability for this resource based on its domain
                        const resourceDomain = resource.domain_name || 'local';
                        const resourceIdToCheck = resource.original_id || resource.id;
                        console.log(resourceDomain, resourceIdToCheck, availableResourcesByDomain?.[resourceDomain]);
						const isAvailable = availableResourcesByDomain?.[resourceDomain]
                            ? availableResourcesByDomain[resourceDomain].includes(resourceIdToCheck)
                            : undefined;

                        return (
                            <ResourceResultItem
                                key={`${resource.domain_name || 'local'}-${resource.id}`}
                                resource={resource}
                                selectedDate={date}
                                isAvailable={isAvailable}
                            />
                        );
                    })}
                </div>
            </div>
        );
    };

	return (
        <div className={`${styles.resourceSearchContainer} ${isSearching ? styles.isSearching : ''}`}>
            {/* Search Filter Section */}
            <section id="resource-filter" className={`${styles.filterSection} ${searchFieldScrolled && isMobile ? styles.scrolled : ''}`}>
				<div className={styles.searchInputs}>
					<div className={`${styles.searchField} ${searchFieldScrolled ? styles.scrolled : ''}`}>
						<Textfield
							label={t('common.search')}
							value={textSearchQuery}
							onChange={(e) => {
									setTextSearchQuery(e.target.value);
									// Show searching animation
									setIsSearching(true);

									// On mobile, scroll the results into view with offset for sticky header
									if (isMobile) {
										// Wait for the DOM to update with new results
										setTimeout(() => {
											const resultsSection = document.getElementById('resource-results');
											if (resultsSection) {
												// Calculate the height of the sticky header
												const filterSection = document.getElementById('resource-filter');
												const headerHeight = filterSection ? filterSection.offsetHeight : 0;

												// Get the position of the results section
												const resultsRect = resultsSection.getBoundingClientRect();

												// Scroll to position with offset
												window.scrollTo({
													top: window.scrollY + resultsRect.top - headerHeight - 16, // Add 16px extra padding
													behavior: 'smooth'
												});
											}

											// After scrolling, clear the searching state
											setTimeout(() => setIsSearching(false), 500);
										}, 300);
									} else {
										// For desktop, just clear the searching state after a delay
										setTimeout(() => setIsSearching(false), 300);
									}
								}}
							placeholder={t('bookingfrontend.search available resources')}
                            id="resource-search-input"
							// @ts-ignore
							suffix={
								textSearchQuery && isMobile ? (
									<Button
										variant="tertiary"
										data-size="sm"
										className={styles.clearSearchButton}
										onClick={() => setTextSearchQuery('')}
										aria-label={t('common.clear')}
									>
										✕
									</Button>
								) : undefined
							}
						/>
					</div>

					{/* Only show date and town filters on desktop */}
					{!isMobile && (
                        <>
                            <div className={styles.dateFilter}>
                                <div>
                                    <Label>{t('bookingfrontend.when')}</Label>
                                    <CalendarDatePicker
                                        currentDate={date}
                                        onDateChange={handleDateChange}
                                        view="timeGridDay"
										placeholder={t('bookingfrontend.select date')}
										allowEmpty={true}
                                    />
                                </div>
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
                        </>
                    )}

					<div className={styles.moreFiltersContainer}>
						<Button
							variant="secondary"
							onClick={() => setFiltersModalOpen(true)}
							data-size={'sm'}
						>
							<FilterIcon/> {t(isMobile ? 'common.filter' : 'bookingfrontend.more_filters')}
						</Button>
						<FilterModal
							open={filtersModalOpen}
							onClose={() => setFiltersModalOpen(false)}
							searchData={searchData}
							selectedActivities={selectedActivities}
							setSelectedActivities={setSelectedActivities}
							selectedFacilities={selectedFacilities}
							setSelectedFacilities={setSelectedFacilities}
                            // Pass date and where props for mobile view
                            date={date}
                            onDateChange={handleDateChange}
                            where={where}
                            onWhereChange={setWhere}
                            towns={towns}
                            showDateWhere={isMobile}
						/>
					</div>
				</div>


				{renderActiveFilters()}
			</section>

			{/* Search Results Section */}
			<section id="resource-results" className={styles.resultsSection}>
				{renderSearchResults()}
			</section>
		</div>
	);
};

export default ResourceSearch;