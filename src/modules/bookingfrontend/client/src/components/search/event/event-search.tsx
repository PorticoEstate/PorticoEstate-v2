'use client'
import React, {FC, useMemo, useState, useEffect} from 'react';
import {useSearchData, useTowns, useUpcomingEvents} from "@/service/hooks/api-hooks";
import {Textfield, Select, Button, Chip, Spinner, Field, Label} from '@digdir/designsystemet-react';
import styles from './event-search.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import CalendarDatePicker from "@/components/date-time-picker/calendar-date-picker";
import {ISearchDataOptimized, ISearchDataTown} from '@/service/types/api/search.types';
import EventResultItem from "@/components/search/event/event-result-item";
import {IShortEvent} from "@/service/pecalendar.types";
import {useSearchParams, useRouter, usePathname} from 'next/navigation';

interface EventSearchProps {
    initialSearchData?: ISearchDataOptimized;
    initialEvents?: IShortEvent[];
    initialTowns?: ISearchDataTown[];
}

// We're using IShortEvent directly from pecalendar.types.ts
// but we'll add some UI-specific properties to the component itself

// Storage constants removed

const EventSearch: FC<EventSearchProps> = ({ initialSearchData, initialEvents, initialTowns }) => {
    const searchParams = useSearchParams();
    const router = useRouter();
    const pathname = usePathname();
    
    // Initialize date states from URL parameters or defaults
    const initFromDate = () => {
        const fromDateParam = searchParams.get('fromDate');
        if (fromDateParam) {
            const date = new Date(fromDateParam);
            return !isNaN(date.getTime()) ? date : new Date();
        }
        return new Date();
    };
    
    const initToDate = () => {
        const toDateParam = searchParams.get('toDate');
        if (toDateParam) {
            const date = new Date(toDateParam);
            return !isNaN(date.getTime()) ? date : new Date(new Date().getTime() + (7 * 24 * 60 * 60 * 1000));
        }
        return new Date(new Date().getTime() + (7 * 24 * 60 * 60 * 1000)); // 7 days later
    };

    // Initialize state for search filters
    const [textSearchQuery, setTextSearchQuery] = useState<string>('');
    const [fromDate, setFromDate] = useState<Date>(initFromDate());
    const [toDate, setToDate] = useState<Date>(initToDate());
    const [townId, setTownId] = useState<number | ''>('');

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

    // Get towns list from towns data
    const towns = useMemo(() => {
        if (!townsData) return [];
        
        // Towns array already contains unique towns with id and name
        // Sort by name for display in dropdown
        return [...townsData].sort((a, b) => a.name.localeCompare(b.name));
    }, [townsData]);

    // Use React Query hook for fetching events
    const fromDateIso = fromDate.toISOString().split('T')[0];
    const toDateIso = toDate.toISOString().split('T')[0];

    const {
        data: events = [],
        isLoading: isLoadingEvents,
        error: eventsError,
        refetch: refetchEvents
    } = useUpcomingEvents({
        fromDate: fromDateIso,
        toDate: toDateIso,
        initialEvents: initialEvents
    });

    // Function to update URL with new search params
    const updateURL = (newFromDate: Date, newToDate: Date) => {
        const params = new URLSearchParams(searchParams.toString());
        params.set('fromDate', newFromDate.toISOString().split('T')[0]);
        params.set('toDate', newToDate.toISOString().split('T')[0]);
        router.push(`${pathname}?${params.toString()}`, { scroll: false });
    };

    // Trigger refetch when date parameters change
    useEffect(() => {
        refetchEvents();
    }, [fromDateIso, toDateIso, refetchEvents]);

    // Apply text and town filters to events
    const filteredEvents = useMemo(() => {
        if (!events.length) return [];

        // If no filters are applied, return all events
        let filtered = events;

        // Apply text search filter
        if (textSearchQuery && textSearchQuery.trim() !== '') {
            const query = textSearchQuery.toLowerCase();
            filtered = filtered.filter(event => {
                const eventNameMatch = event.name?.toLowerCase().includes(query);
                const locationMatch = event.building_name?.toLowerCase().includes(query);
                const organizerMatch = event.organizer?.toLowerCase().includes(query) ||
                                      event.customer_organization_name?.toLowerCase().includes(query);

                return eventNameMatch || locationMatch || organizerMatch;
            });
        }

        // Town filter
        if (townId && searchData?.buildings) {
            // Find buildings in the selected town
            const buildingsInTown = searchData.buildings
                .filter(building => building.town_id === townId)
                .map(building => building.id);

            filtered = filtered.filter(event =>
                buildingsInTown?.includes(event.building_id)
            );
        }

        return filtered;
    }, [events, textSearchQuery, townId, searchData?.buildings]);

    // Handle date selection
    const handleFromDateChange = (newDate: Date | null) => {
        if (newDate) {
            setFromDate(newDate);

            // If toDate is before fromDate, adjust it
            let newToDate = toDate;
            if (toDate < newDate) {
                newToDate = new Date(newDate.getTime() + (7 * 24 * 60 * 60 * 1000));
                setToDate(newToDate);
            }
            
            // Update URL with new dates
            updateURL(newDate, newToDate);
        }
    };

    const handleToDateChange = (newDate: Date | null) => {
        if (newDate) {
            setToDate(newDate);
            // Update URL with new dates
            updateURL(fromDate, newDate);
        }
    };

    // Clear all filters
    const clearFilters = () => {
        setTextSearchQuery('');
        setTownId('');
        setFromDate(new Date());
        setToDate(new Date(new Date().getTime() + (7 * 24 * 60 * 60 * 1000)));
    };


    if (isLoadingSearch) {
        return (
            <div className={styles.loadingContainer}>
                <Spinner data-size="lg" aria-label={t('common.loading...')}/>
                <p>{t('common.loading...')}</p>
            </div>
        );
    }

    if (searchError) {
        return (
            <div className={styles.errorContainer}>
                <p>{t('common.error_occurred')}</p>
                <Button onClick={() => window.location.reload()}>{t('common.try_again')}</Button>
            </div>
        );
    }

    return (
        <div className={styles.eventSearchContainer}>
            <section id="event-filter" className={styles.filterSection}>
                <div className={styles.searchInputs}>
                    <div className={styles.searchField}>
                        <Textfield
                            label={t('common.search')}
                            value={textSearchQuery}
                            onChange={(e) => setTextSearchQuery(e.target.value)}
                            placeholder={t('bookingfrontend.search events')}
                        />
                    </div>

                    <div className={styles.dateFilter}>
                        <Field>
                            <Label>{t('bookingfrontend.from_date')}</Label>
                            <CalendarDatePicker
                                currentDate={fromDate}
                                onDateChange={handleFromDateChange}
								allowPastDates
                                view="timeGridDay"
								showYear
                            />
                        </Field>
                    </div>

                    <div className={styles.dateFilter}>
                        <Field>
                            <Label>{t('bookingfrontend.to_date')}</Label>
                            <CalendarDatePicker
                                currentDate={toDate}
                                onDateChange={handleToDateChange}
								allowPastDates
                                view="timeGridDay"
								showYear
                            />
                        </Field>
                    </div>

                    <div className={styles.townFilter}>
                        <Field>
                            <Label>{t('bookingfrontend.where')}</Label>
                            <Select
                                value={townId}
                                onChange={(e) => setTownId(e.target.value === '' ? '' : Number(e.target.value))}
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

                {(textSearchQuery || townId !== '') && (
                    <div className={styles.activeFilters}>
                        <span>{t('common.filter')}:</span>
                        <div className={styles.filterChips}>
                            {textSearchQuery && (
                                <Chip.Removable data-color="brand1" onClick={() => setTextSearchQuery('')}>
                                    {t('common.search')}: {textSearchQuery}
                                </Chip.Removable>
                            )}
                            {townId !== '' && (
                                <Chip.Removable data-color="brand1" onClick={() => setTownId('')}>
                                    {t('bookingfrontend.town')}: {towns.find(t => t.id === townId)?.name}
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

            <section id="event-results" className={styles.resultsSection}>
                {isLoadingEvents ? (
                    <div className={styles.loadingContainer}>
                        <Spinner data-size="md" aria-label={t('common.loading...')}/>
                    </div>
                ) : eventsError ? (
                    <div className={styles.errorContainer}>
                        <p>{t('common.error_loading_events')}</p>
                        <Button
                            variant="secondary"
                            onClick={() => window.location.reload()}
                        >
                            {t('common.try_again')}
                        </Button>
                    </div>
                ) : filteredEvents.length === 0 ? (
                    <div className={styles.noResults}>
                        <p>{t('bookingfrontend.search_no_events_match')}</p>
                    </div>
                ) : (
                    <div className={styles.eventGrid}>
                        {filteredEvents.map(event => (
                            <EventResultItem key={event.id} event={event}/>
                        ))}
                    </div>
                )}
            </section>
        </div>
    );
}

export default EventSearch;