import React, {FC, useMemo} from 'react';
import styles from "./timeslot-view.module.scss";
import {DateTime} from "luxon";
import {useBuildingFreeTimeSlots, useCreateSimpleApplication} from "@/service/hooks/api-hooks";
import {IBuilding} from "@/service/types/Building";
import {useEnabledResources} from "@/components/building-calendar/calendar-context";
import TimeSlotCard from "@/components/building-calendar/views/timeslots/card/time-slot-card";

interface TimeslotViewProps {
	currentDate: DateTime;
	viewMode: string;
	building: IBuilding;

}

const TimeslotView: FC<TimeslotViewProps> = (props) => {
	const {enabledResources} = useEnabledResources();
	const createSimpleApp = useCreateSimpleApplication();
	const viewRange = useMemo(() => {
		if (props.viewMode.includes('Day')) {
			return 'day'
		}
		if (props.viewMode.includes('Week')) {
			return 'week'
		}
		return 'month'
	}, [props.viewMode]);

	const weeks = useMemo(() => {
		switch (viewRange) {
			case 'day':
			case 'week':
				return [props.currentDate];
			case 'month':
				// Get all weeks that overlap with the current month
				const startOfMonth = props.currentDate.startOf('month');
				const endOfMonth = props.currentDate.endOf('month');
				const weeks = [];

				let currentWeek = startOfMonth.startOf('week');
				while (currentWeek <= endOfMonth) {
					weeks.push(currentWeek);
					currentWeek = currentWeek.plus({ weeks: 1 });
				}

				return weeks;
			default:
				return [props.currentDate];
		}
	}, [props.currentDate, viewRange]);

	const {data: freeTimeSlots} = useBuildingFreeTimeSlots({
		building_id: props.building.id,
		weeks,
		instance: undefined,
	});


	const currentResourceId = useMemo(() => {
		if (enabledResources.size !== 1) return undefined;

		// Get the slots for the single enabled resource
		const resourceId = [...enabledResources][0];
		return resourceId
	}, [enabledResources])

	const visibleTimeslots = useMemo(() => {
		if (!freeTimeSlots || !currentResourceId) return [];

		// Get the slots for the single enabled resource
		const resourceId = currentResourceId
		const slots = freeTimeSlots[resourceId] || [];

		const startOfRange = (() => {
			switch (viewRange) {
				case 'day':
					return props.currentDate.startOf('day');
				case 'week':
					return props.currentDate.startOf('week');
				case 'month':
					return props.currentDate.startOf('month');
			}
		})();

		const endOfRange = (() => {
			switch (viewRange) {
				case 'day':
					return props.currentDate.endOf('day');
				case 'week':
					return props.currentDate.endOf('week');
				case 'month':
					return props.currentDate.endOf('month');
			}
		})();

		return slots.filter(slot => {
			const slotStart = DateTime.fromISO(slot.start_iso);
			const slotEnd = DateTime.fromISO(slot.end_iso);
			return slotStart >= startOfRange && slotEnd <= endOfRange;
		}).sort((a, b) => {
			return DateTime.fromISO(a.start_iso) < DateTime.fromISO(b.start_iso) ? -1 : 1;
		});
	}, [freeTimeSlots, viewRange, props.currentDate, currentResourceId]);

	return (
		<div className={styles.calendarBody}>
			{visibleTimeslots.map((slot, index) => (
				<TimeSlotCard
					key={`${slot.start_iso}-${index}`}
					slot={slot}
					resourceId={currentResourceId}
					onSelect={(slot) => createSimpleApp.mutate(slot)}
				/>
			))}
			{visibleTimeslots.length === 0 && (
				<div className={styles.noSlots}>
					No available time slots for this period
				</div>
			)}
		</div>
	);
}

export default TimeslotView


