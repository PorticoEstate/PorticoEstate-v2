'use client'
import React, {FC, useMemo, useState, useEffect} from 'react';
import {useSearchData, useUpcomingEvents} from "@/service/hooks/api-hooks";
import {Textfield, Select, Button, Chip, Spinner, Field, Label} from '@digdir/designsystemet-react';
import styles from './event-search.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import CalendarDatePicker from "@/components/date-time-picker/calendar-date-picker";
import {ISearchDataOptimized} from '@/service/types/api/search.types';
import EventResultItem from "@/components/search/event/event-result-item";
import {IShortEvent} from "@/service/pecalendar.types";

interface EventSearchProps {
    initialSearchData?: ISearchDataOptimized;
    initialEvents?: IShortEvent[];
}

// We're using IShortEvent directly from pecalendar.types.ts
// but we'll add some UI-specific properties to the component itself

// Storage constants removed

const EventSearch: FC<EventSearchProps> = ({ initialSearchData, initialEvents }) => {
    // Initialize state for search filters
    const [textSearchQuery, setTextSearchQuery] = useState<string>('');
    const [fromDate, setFromDate] = useState<Date>(new Date());
    const [toDate, setToDate] = useState<Date>(new Date(new Date().getTime() + (7 * 24 * 60 * 60 * 1000))); // 7 days later
    const [district, setDistrict] = useState<string>('');

    // Fetch all search data, using initialSearchData as default
    const {data: searchData, isLoading: isLoadingSearch, error: searchError} = useSearchData({
        initialData: initialSearchData
    });

    const t = useTrans();

    // Extract unique districts from buildings
    const districts = useMemo(() => {
        if (!searchData?.buildings) return [];

        const uniqueDistricts = Array.from(
            new Set(searchData.buildings.map(building => building.district))
        ).filter(Boolean);

        return uniqueDistricts.sort();
    }, [searchData?.buildings]);

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

    // Trigger refetch when date parameters change
    useEffect(() => {
        refetchEvents();
    }, [fromDateIso, toDateIso, refetchEvents]);

    // Apply text and district filters to events
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

        // District filter
        if (district && searchData?.buildings) {
            // Find buildings in the selected district
            const buildingsInDistrict = searchData.buildings
                .filter(building => building.district === district)
                .map(building => building.id);

            filtered = filtered.filter(event =>
                buildingsInDistrict?.includes(event.building_id)
            );
        }

        return filtered;
    }, [events, textSearchQuery, district, searchData?.buildings]);

    // Handle date selection
    const handleFromDateChange = (newDate: Date | null) => {
        if (newDate) {
            setFromDate(newDate);

            // If toDate is before fromDate, adjust it
            if (toDate < newDate) {
                const newToDate = new Date(newDate.getTime() + (7 * 24 * 60 * 60 * 1000));
                setToDate(newToDate);
            }
        }
    };

    const handleToDateChange = (newDate: Date | null) => {
        if (newDate) {
            setToDate(newDate);
        }
    };

    // Clear all filters
    const clearFilters = () => {
        setTextSearchQuery('');
        setDistrict('');
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

                    <div className={styles.districtFilter}>
                        <Field>
                            <Label>{t('bookingfrontend.where')}</Label>
                            <Select
                                value={district}
                                onChange={(e) => setDistrict(e.target.value)}
                            >
                                <Select.Option value="">{t('booking.all')}</Select.Option>
                                {districts.map(district => (
                                    <Select.Option key={district} value={district}>
                                        {district}
                                    </Select.Option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                </div>

                {(textSearchQuery || district !== '') && (
                    <div className={styles.activeFilters}>
                        <span>{t('common.filter')}:</span>
                        <div className={styles.filterChips}>
                            {textSearchQuery && (
                                <Chip.Removable data-color="brand1" onClick={() => setTextSearchQuery('')}>
                                    {t('common.search')}: {textSearchQuery}
                                </Chip.Removable>
                            )}
                            {district && (
                                <Chip.Removable data-color="brand1" onClick={() => setDistrict('')}>
                                    {t('bookingfrontend.town part')}: {district}
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