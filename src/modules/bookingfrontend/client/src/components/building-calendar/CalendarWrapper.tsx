'use client'

import React, {useState, useCallback, useRef, useEffect, useMemo} from 'react';
import {DateTime, Interval} from "luxon";
import BuildingCalendarClient from "@/components/building-calendar/building-calendar-client";
import {IEvent, IFreeTimeSlot} from "@/service/pecalendar.types";
import {DatesSetArg} from "@fullcalendar/core";
import {IBuilding, Season} from "@/service/types/Building";
import {useLoadingContext} from "@/components/loading-wrapper/LoadingContext";
import {useBuildingSchedule, useOrganizationSchedule} from "@/service/hooks/api-hooks";
import CalendarProvider from "@/components/building-calendar/calendar-context";
import {FCallTempEvent} from "@/components/building-calendar/building-calendar.types";
import {useQueryClient} from "@tanstack/react-query";
import styles from "@/components/building-calendar/building-calender.module.scss";
import CalendarResourceFilter from "@/components/building-calendar/modules/resource-filter/calender-resource-filter";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {useBuilding} from "@/service/api/building";
import { IResource } from '@/service/types/resource.types';
import FullCalendar from "@fullcalendar/react";

interface CalendarWrapperProps {
    initialFreeTime: Record<string, IFreeTimeSlot[]>; // [resourceId]: Array<IFreeTimeSlot>
    buildingId?: number;
    organizationId?: number;
    resources?: IResource[];
    seasons?: Season[];
    building?: IBuilding;
    buildings?: IBuilding[];
    initialDate: Date;
    resourceId?: string;
    readOnly?: boolean;
}


const CalendarWrapper: React.FC<CalendarWrapperProps> = ({
                                                             initialDate,
                                                             initialFreeTime,
                                                             buildingId,
                                                             organizationId,
                                                             resources,
                                                             seasons,
                                                             building,
                                                             buildings,
                                                             resourceId,
                                                             readOnly = false,
                                                         }) => {
    const initialEnabledResources = new Set<string>(
        resourceId ? [resourceId] : []
    );
    const [enabledResources, setEnabledResources] = useState<Set<string>>(initialEnabledResources);
    const {setLoadingState} = useLoadingContext();
    const queryClient = useQueryClient();
    const isMobile = useIsMobile();
    const [resourcesContainerRendered, setResourcesContainerRendered] = useState<boolean>(!resourceId && !(window.innerWidth < 601));
    const [resourcesHidden, setSResourcesHidden] = useState<boolean>(!!resourceId || window.innerWidth < 601);
	const [dates, setDates] = useState<DateTime[]>([DateTime.fromJSDate(initialDate)]);
    const calendarRef = useRef<FullCalendar>(null);

	const {data: _, isLoading, isStale} = useBuilding(building?.id, undefined, building);

	useEffect(() => {
        resources?.forEach((res) => queryClient.setQueryData<IResource>(['resource', `${res.id}`], res))
        queryClient.setQueryData(['buildingResources', `${resourceId}`], resources);
    }, [resources, queryClient, resourceId]);

    const buildingScheduleQuery = useBuildingSchedule({
        building_id: buildingId,
        weeks: dates,
    });

    const organizationScheduleQuery = useOrganizationSchedule({
        organization_id: organizationId,
        weeks: dates,
    });

    // Use the appropriate query result based on mode
    const QCRES = buildingId ? buildingScheduleQuery : organizationScheduleQuery;

    const prioritizeEvents = useCallback((events: IEvent[], enabledResources: Set<string>): IEvent[] => {
        // First get all events that affect enabled resources
        const relevantEvents = events.filter(event =>
            event.resources.some(resource => enabledResources.has(resource.id.toString()))
        );

        // Get events by type
        const masterEvents = relevantEvents.filter(event => event.type === 'event');
        const bookings = relevantEvents.filter(event => event.type === 'booking');

        // Create maps of blocked time periods per resource
        const blockedPeriodsByEvents = new Map<string, Array<{start: DateTime, end: DateTime, eventId: number}>>();
        const blockedPeriodsByBookings = new Map<string, Array<{start: DateTime, end: DateTime, eventId: number}>>();

        // Map event blocks
        masterEvents.forEach(event => {
            const start = DateTime.fromISO(event.from_);
            const end = DateTime.fromISO(event.to_);

            event.resources.forEach(resource => {
                const resourceId = resource.id.toString();
                if (enabledResources.has(resourceId)) {
                    if (!blockedPeriodsByEvents.has(resourceId)) {
                        blockedPeriodsByEvents.set(resourceId, []);
                    }
                    blockedPeriodsByEvents.get(resourceId)?.push({ start, end, eventId: event.id });
                }
            });
        });

        // Map booking blocks
        bookings.forEach(booking => {
            const start = DateTime.fromISO(booking.from_);
            const end = DateTime.fromISO(booking.to_);

            booking.resources.forEach(resource => {
                const resourceId = resource.id.toString();
                if (enabledResources.has(resourceId)) {
                    if (!blockedPeriodsByBookings.has(resourceId)) {
                        blockedPeriodsByBookings.set(resourceId, []);
                    }
                    blockedPeriodsByBookings.get(resourceId)?.push({ start, end, eventId: booking.id });
                }
            });
        });

        return relevantEvents.filter(event => {
            // Keep all events (highest priority)
            if (event.type === 'event') return true;

            const eventStart = DateTime.fromISO(event.from_);
            const eventEnd = DateTime.fromISO(event.to_);

            // For bookings, only check overlap with events
            if (event.type === 'booking') {
                return !event.resources.some(resource => {
                    const resourceId = resource.id.toString();
                    if (!enabledResources.has(resourceId)) return false;

                    // Check overlap with events only
                    const eventBlockedPeriods = blockedPeriodsByEvents.get(resourceId) || [];
                    return eventBlockedPeriods.some(period =>
                        !(eventEnd <= period.start || eventStart >= period.end)
                    );
                });
            }

            // For allocations, check overlap with both events and bookings
            if (event.type === 'allocation') {
                return !event.resources.some(resource => {
                    const resourceId = resource.id.toString();
                    if (!enabledResources.has(resourceId)) return false;

                    // Check overlap with events
                    const eventBlockedPeriods = blockedPeriodsByEvents.get(resourceId) || [];
                    const hasEventOverlap = eventBlockedPeriods.some(period =>
                        !(eventEnd <= period.start || eventStart >= period.end)
                    );
                    if (hasEventOverlap) return true;

                    // Check overlap with bookings
                    const bookingBlockedPeriods = blockedPeriodsByBookings.get(resourceId) || [];
                    return bookingBlockedPeriods.some(period =>
                        !(eventEnd <= period.start || eventStart >= period.end)
                    );
                });
            }

            return true;
        });
    }, []);

    // Extract resources that actually exist in the schedule data
    const scheduleResources = useMemo(() => {
        if (!QCRES.data) return [];

        const resourceMap = new Map();
        QCRES.data.forEach(event => {
            event.resources.forEach(resource => {
                resourceMap.set(resource.id, resource);
            });
        });

        return Array.from(resourceMap.values());
    }, [QCRES.data]);

    const prioritizedEvents = useMemo(() => {
        if (!QCRES.data) return [];

        // In organization mode, show all events without resource filtering initially
        if (organizationId && !buildingId) {
            return QCRES.data;
        }

        // In building mode, apply priority filtering
        return prioritizeEvents(QCRES.data, enabledResources);
    }, [QCRES.data, enabledResources, prioritizeEvents, organizationId, buildingId]);




    const fetchData = useCallback(async (start: DateTime, end?: DateTime) => {
        setLoadingState('building', true);
        try {
            const firstDay = start.startOf('week');
            const lastDay = (end || DateTime.now()).endOf('week').plus({weeks: 1});


            // Create an interval from start to end
            const dateInterval = Interval.fromDateTimes(firstDay, lastDay);

            // Generate an array of week start dates
            const weeksToFetch = dateInterval.splitBy({weeks: 1}).map(interval =>
                interval.start!.toFormat("y-MM-dd")
            );

            // If the array is empty (which shouldn't happen, but just in case),
            // add the start date
            if (weeksToFetch.length === 0) {
                weeksToFetch.push(firstDay.toFormat("y-MM-dd"));
            }

            setDates(dateInterval.splitBy({weeks: 1}).map(interval =>
                interval.start!
            ))


        } catch (error) {
            console.error('Error fetching data:', error);
        } finally {
            setLoadingState('building', false);

        }
    }, [buildingId, prioritizeEvents, setLoadingState]);


    const handleDateChange = (newDate: DatesSetArg) => {
        fetchData(DateTime.fromJSDate(newDate.start), DateTime.fromJSDate(newDate.end))
    };

    const handleAfterTransition = () => {
        if (isMobile) {
            return;
        }
        if (resourcesHidden) {
            setResourcesContainerRendered(false);
        }

        // Force FullCalendar to rerender after sidebar animation completes
        if (calendarRef.current) {
            const calendarApi = calendarRef.current.getApi();
            (calendarApi as any).render();
        }
    };


    const setResourcesHidden = (v: boolean) => {
        if (isMobile) {
            setResourcesContainerRendered(v);
            setSResourcesHidden(!v)
        }
        if (!v) {
            setResourcesContainerRendered(true);
        }
        setSResourcesHidden(v)
    }

    return (
        <CalendarProvider
            enabledResources={enabledResources}
            setEnabledResources={setEnabledResources}
            setResourcesHidden={setResourcesHidden}
            resourcesHidden={resourcesHidden}
            currentBuilding={buildingId}
			currentOrganization={organizationId}
        >

            <div className={`${styles.calendar} ${resourcesHidden ? styles.closed : ''} `}
                // onTransitionStart={handleBeforeTransition}
                 onTransitionEnd={handleAfterTransition}>
                {/*<CalendarHeader view={view} calendarRef={calendarRef} setView={(v) => setView(v)}/>*/}
                    <CalendarResourceFilter
                        transparent={resourcesHidden}
                        open={resourcesContainerRendered}
                        setOpen={setResourcesContainerRendered}
                        filteredResources={scheduleResources}
                    />
                <BuildingCalendarClient
                    ref={calendarRef}
                    initialDate={DateTime.fromJSDate(initialDate)}
                    events={prioritizedEvents}
                    onDateChange={handleDateChange}
                    seasons={seasons}
                    building={building}
                    buildings={buildings}
                    readOnly={readOnly}
                    initialEnabledResources={enabledResources}
                />
            </div>
        </CalendarProvider>
    );
};

export default CalendarWrapper;
