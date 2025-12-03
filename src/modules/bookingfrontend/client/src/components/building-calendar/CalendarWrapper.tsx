'use client'

import React, {useState, useCallback, useRef, useEffect, useMemo} from 'react';
import {DateTime, Interval} from "luxon";
import BuildingCalendarClient from "@/components/building-calendar/building-calendar-client";
import {IEvent, IFreeTimeSlot} from "@/service/pecalendar.types";
import {DatesSetArg} from "@fullcalendar/core";
import {IBuilding, Season} from "@/service/types/Building";
import {useLoadingContext} from "@/components/loading-wrapper/LoadingContext";
import {useBuildingSchedule, useOrganizationSchedule, usePartialApplications} from "@/service/hooks/api-hooks";
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
    
    // Fetch partial applications from shopping cart
    const {data: partialApplications} = usePartialApplications();

    const prioritizeEvents = useCallback((events: IEvent[], enabledResources: Set<string>): IEvent[] => {
        // First get all events that affect enabled resources
        const relevantEvents = events.filter(event =>
            event.resources.some(resource => enabledResources.has(resource.id.toString()))
        );

        // Get events by type
        const masterEvents = relevantEvents.filter(event => event.type === 'event');
        const bookings = relevantEvents.filter(event => event.type === 'booking');
        const allocations = relevantEvents.filter(event => event.type === 'allocation');

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

        // Helper function to split a time period based on blocked periods
        const splitTimePeriod = (
            start: DateTime,
            end: DateTime,
            blockedPeriods: Array<{start: DateTime, end: DateTime}>
        ): Array<{start: DateTime, end: DateTime}> => {
            // Sort blocked periods by start time
            const sortedBlocked = [...blockedPeriods].sort((a, b) =>
                a.start.toMillis() - b.start.toMillis()
            );

            const freeSegments: Array<{start: DateTime, end: DateTime}> = [];
            let currentStart = start;

            for (const blocked of sortedBlocked) {
                // If blocked period doesn't overlap with our current range, skip it
                if (blocked.end <= currentStart || blocked.start >= end) {
                    continue;
                }

                // If there's a gap before this blocked period, add it as a free segment
                if (currentStart < blocked.start) {
                    freeSegments.push({
                        start: currentStart,
                        end: DateTime.min(blocked.start, end)
                    });
                }

                // Move current start to after the blocked period
                currentStart = DateTime.max(currentStart, blocked.end);

                // If we've reached or passed the end, we're done
                if (currentStart >= end) {
                    break;
                }
            }

            // Add any remaining time after all blocked periods
            if (currentStart < end) {
                freeSegments.push({ start: currentStart, end });
            }

            return freeSegments;
        };

        // Process bookings - trim based on events
        const processedBookings: IEvent[] = [];
        bookings.forEach(booking => {
            const bookingStart = DateTime.fromISO(booking.from_);
            const bookingEnd = DateTime.fromISO(booking.to_);

            // Get all blocking periods for this booking's resources
            const allBlockingPeriods: Array<{start: DateTime, end: DateTime}> = [];
            booking.resources.forEach(resource => {
                const resourceId = resource.id.toString();
                if (enabledResources.has(resourceId)) {
                    const eventPeriods = blockedPeriodsByEvents.get(resourceId) || [];
                    allBlockingPeriods.push(...eventPeriods);
                }
            });

            // If no overlaps, keep the booking as-is
            if (allBlockingPeriods.length === 0) {
                processedBookings.push(booking);
                return;
            }

            // Split the booking into non-overlapping segments
            const freeSegments = splitTimePeriod(bookingStart, bookingEnd, allBlockingPeriods);

            // Create a booking for each free segment (only if segments exist)
            freeSegments.forEach((segment, index) => {
                processedBookings.push({
                    ...booking,
                    id: index === 0 ? booking.id : booking.id * 10000 + index, // Ensure unique IDs
                    from_: segment.start.toISO()!,
                    to_: segment.end.toISO()!
                });
            });
        });

        // Process allocations - trim based on events and bookings
        const processedAllocations: IEvent[] = [];
        allocations.forEach(allocation => {
            const allocationStart = DateTime.fromISO(allocation.from_);
            const allocationEnd = DateTime.fromISO(allocation.to_);

            // Get all blocking periods for this allocation's resources
            const allBlockingPeriods: Array<{start: DateTime, end: DateTime}> = [];
            allocation.resources.forEach(resource => {
                const resourceId = resource.id.toString();
                if (enabledResources.has(resourceId)) {
                    const eventPeriods = blockedPeriodsByEvents.get(resourceId) || [];
                    const bookingPeriods = blockedPeriodsByBookings.get(resourceId) || [];
                    allBlockingPeriods.push(...eventPeriods, ...bookingPeriods);
                }
            });

            // If no overlaps, keep the allocation as-is
            if (allBlockingPeriods.length === 0) {
                processedAllocations.push(allocation);
                return;
            }

            // Split the allocation into non-overlapping segments
            const freeSegments = splitTimePeriod(allocationStart, allocationEnd, allBlockingPeriods);

            // Create an allocation for each free segment (only if segments exist)
            freeSegments.forEach((segment, index) => {
                processedAllocations.push({
                    ...allocation,
                    id: index === 0 ? allocation.id : allocation.id * 10000 + index, // Ensure unique IDs
                    from_: segment.start.toISO()!,
                    to_: segment.end.toISO()!
                });
            });
        });

        // Return all events: master events (unchanged) + processed bookings + processed allocations
        return [...masterEvents, ...processedBookings, ...processedAllocations];
    }, []);

    // Partial applications are now handled in the temp events context
    // This removes duplication between temp events and partial application events

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
        const scheduleEvents = QCRES.data || [];
        
        // In organization mode, show all events without resource filtering initially
        if (organizationId && !buildingId) {
            return scheduleEvents;
        }

        // Apply priority filtering to schedule events
        const prioritizedScheduleEvents = prioritizeEvents(scheduleEvents, enabledResources);
        
        return prioritizedScheduleEvents;
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
			seasons={seasons}
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
