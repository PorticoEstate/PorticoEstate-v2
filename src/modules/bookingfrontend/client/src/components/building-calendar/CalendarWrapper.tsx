// File: components/CalendarWrapper.tsx
'use client'

import React, {useState, useCallback, useRef} from 'react';
import {DateTime, Interval} from "luxon";
import BuildingCalendarClient from "@/components/building-calendar/building-calendar-client";
import {fetchBuildingSchedule, fetchFreeTimeSlots} from "@/service/api/api-utils";
import {IEvent, IShortResource, Season} from "@/service/pecalendar.types";
import {DatesSetArg} from "@fullcalendar/core";
import {Modal, Spinner} from '@digdir/designsystemet-react'
import {IBuilding} from "@/service/types/Building";
import {useLoadingContext} from "@/components/loading-wrapper/LoadingContext";

interface CalendarWrapperProps {
    initialSchedule: IEvent[];
    initialFreeTime: any; // Replace 'any' with the correct type
    buildingId: number;
    resources: Record<string, IShortResource>;
    seasons: Season[];
    building: IBuilding;
    initialDate: Date;
}

const CalendarWrapper: React.FC<CalendarWrapperProps> = ({
                                                             initialDate,
                                                             initialSchedule,
                                                             initialFreeTime,
                                                             buildingId,
                                                             resources,
                                                             seasons,
                                                             building
                                                         }) => {
    const [freeTime, setFreeTime] = useState(initialFreeTime);
    const {setLoadingState} = useLoadingContext();
    const modalRef = useRef<HTMLDialogElement>(null);

    const prioritizeEvents = useCallback((events: IEvent[]): IEvent[] => {
        const allocationIds = events
            .filter(event => event.type === 'allocation')
            .map(event => event.id);

        return events.filter(event =>
            !allocationIds.includes(event.allocation_id || -1)
        );
    }, []);
    const [schedule, setSchedule] = useState<IEvent[]>(prioritizeEvents(initialSchedule));

    const fetchData = useCallback(async (start: DateTime, end?: DateTime) => {
        setLoadingState('building', true);
        try {
            const firstDay = start.startOf('week');
            const lastDay = (end || DateTime.now()).endOf('week');


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

            const [newSchedule, newFreeTime] = await Promise.all([
                fetchBuildingSchedule(buildingId, weeksToFetch),
                fetchFreeTimeSlots(buildingId)
            ]);

            setSchedule(prioritizeEvents(newSchedule.schedule || []));
            setFreeTime(newFreeTime);

        } catch (error) {
            console.error('Error fetching data:', error);
            // Handle error (e.g., show error message to user)
        } finally {
            setLoadingState('building', false);

        }
    }, [buildingId, prioritizeEvents]);


    const handleDateChange = (newDate: DatesSetArg) => {
        fetchData(DateTime.fromJSDate(newDate.start), DateTime.fromJSDate(newDate.end))
        // .then(([newSchedule, newFreeTime]) => {
        //     setSchedule(prioritizeEvents(newSchedule.schedule || []));
        //     setFreeTime(newFreeTime);
        // });
    };
    return (
        <div>

            <BuildingCalendarClient
                initialDate={DateTime.fromJSDate(initialDate)}
                events={schedule}
                onDateChange={handleDateChange}
                resources={resources}
                seasons={seasons}
                building={building}
            />
        </div>
    );
};

export default CalendarWrapper;
