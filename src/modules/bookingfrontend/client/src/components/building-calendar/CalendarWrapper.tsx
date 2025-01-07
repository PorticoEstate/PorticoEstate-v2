'use client'

import React, {useState, useCallback, useRef, useEffect} from 'react';
import {DateTime, Interval} from "luxon";
import BuildingCalendarClient from "@/components/building-calendar/building-calendar-client";
import {IEvent, Season} from "@/service/pecalendar.types";
import {DatesSetArg} from "@fullcalendar/core";
import {IBuilding} from "@/service/types/Building";
import {useLoadingContext} from "@/components/loading-wrapper/LoadingContext";
import {useBuildingSchedule} from "@/service/hooks/api-hooks";
import CalendarProvider from "@/components/building-calendar/calendar-context";
import {FCallTempEvent} from "@/components/building-calendar/building-calendar.types";
import {useQueryClient} from "@tanstack/react-query";
import styles from "@/components/building-calendar/building-calender.module.scss";
import CalendarResourceFilter from "@/components/building-calendar/modules/resource-filter/calender-resource-filter";
import {useIsMobile} from "@/service/hooks/is-mobile";

interface CalendarWrapperProps {
    initialSchedule: IEvent[];
    initialFreeTime: any; // Replace 'any' with the correct type
    buildingId: number;
    resources: IResource[];
    seasons: Season[];
    building: IBuilding;
    initialDate: Date;
    resourceId?: string;
    initialWeekSchedule: Record<string, IEvent[]>
}


const CalendarWrapper: React.FC<CalendarWrapperProps> = ({
                                                             initialDate,
                                                             initialSchedule,
                                                             initialFreeTime,
                                                             buildingId,
                                                             resources,
                                                             seasons,
                                                             building,
                                                             resourceId,
                                                             initialWeekSchedule
                                                         }) => {
    const initialEnabledResources = new Set<string>(
        resourceId ? [resourceId] : resources.map(a => `${a.id}`)
    );
    const [enabledResources, setEnabledResources] = useState<Set<string>>(initialEnabledResources);
    const {setLoadingState} = useLoadingContext();
    const queryClient = useQueryClient();
    const isMobile = useIsMobile();
    const [resourcesContainerRendered, setResourcesContainerRendered] = useState<boolean>(!resourceId && !(window.innerWidth < 601));
    const [resourcesHidden, setSResourcesHidden] = useState<boolean>(!!resourceId || window.innerWidth < 601);

    useEffect(() => {
        resources.forEach((res) => queryClient.setQueryData<IResource>(['resource', `${res.id}`], res))
        queryClient.setQueryData(['buildingResources', `${resourceId}`], resources);
    }, [resources, queryClient, resourceId]);


    const prioritizeEvents = useCallback((events: IEvent[]): IEvent[] => {
        const allocationIds = events
            .filter(event => event.type === 'allocation')
            .map(event => event.id);

        return events.filter(event =>
            !allocationIds.includes(event.allocation_id || -1)
        );
    }, []);
    const [dates, setDates] = useState<DateTime[]>([DateTime.fromJSDate(initialDate)]);

    const QCRES = useBuildingSchedule({
        building_id: building.id,
        weeks: dates,
        initialWeekSchedule: initialWeekSchedule
    });

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
        >

            <div className={`${styles.calendar} ${resourcesHidden ? styles.closed : ''} `}
                // onTransitionStart={handleBeforeTransition}
                 onTransitionEnd={handleAfterTransition}>
                {/*<CalendarHeader view={view} calendarRef={calendarRef} setView={(v) => setView(v)}/>*/}
                <CalendarResourceFilter
                    transparent={resourcesHidden}
                    open={resourcesContainerRendered}
                    setOpen={setResourcesContainerRendered}
                    buildingId={building.id}
                />
                <BuildingCalendarClient
                    initialDate={DateTime.fromJSDate(initialDate)}
                    events={QCRES.data}
                    onDateChange={handleDateChange}
                    seasons={seasons}
                    building={building}
                    initialEnabledResources={enabledResources}
                />
            </div>
        </CalendarProvider>
    );
};

export default CalendarWrapper;
