import React, {FC, useMemo} from 'react';
import styles from "./timeslot-view.module.scss";
import {DateTime} from "luxon";
import {
	useBuildingFreeTimeSlots,
	useCreateSimpleApplication,
	useDeletePartialApplication,
	useBuildingSeasons
} from "@/service/hooks/api-hooks";
import {IBuilding} from "@/service/types/Building";
import {useEnabledResources} from "@/components/building-calendar/calendar-context";
import TimeSlotCard from "@/components/building-calendar/views/timeslots/card/time-slot-card";
import {IFreeTimeSlot} from "@/service/pecalendar.types";
import {Spinner} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useWebSocketContext} from "@/service/websocket/websocket-context";
import {useResource} from "@/service/api/building";

interface TimeslotViewProps {
	currentDate: DateTime;
	viewMode: string;
	building: IBuilding;
}

const TimeslotView: FC<TimeslotViewProps> = (props) => {
	const {enabledResources} = useEnabledResources();
	const createSimpleApp = useCreateSimpleApplication();
	const deletePartialApp = useDeletePartialApplication();
	const webSocketService = useWebSocketContext();
	const t = useTrans();

	// Get current resource ID
	const currentResourceId = useMemo(() => {
		if (enabledResources.size !== 1) return undefined;
		return [...enabledResources][0];
	}, [enabledResources]);

	// Fetch resource data and seasons
	const {data: resourceData} = useResource(currentResourceId || 0);
	const {data: seasons} = useBuildingSeasons(props.building.id);
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
					currentWeek = currentWeek.plus({weeks: 1});
				}

				return weeks;
			default:
				return [props.currentDate];
		}
	}, [props.currentDate, viewRange]);

	const {data: freeTimeSlots, refetch: refetchTimeSlots, isLoading} = useBuildingFreeTimeSlots({
		building_id: props.building.id,
		weeks,
		instance: undefined,
	});

	// Calculate the booking horizon limit based on resource settings and season
	const horizonLimit = useMemo(() => {
		if (!resourceData) return null;

		const now = DateTime.now();
		let horizonEnd: DateTime | null = null;

		// Replicate PHP month_shifter logic
		const monthShifter = (from: DateTime, months: number): DateTime => {
			let adjustedMonths = months;

			// If end of current month is in the future, subtract 1 from months
			const endOfMonth = from.endOf('month');
			if (endOfMonth > from) {
				adjustedMonths = adjustedMonths - 1;
			}

			return from.plus({ months: adjustedMonths }).endOf('month');
		};

		// Calculate horizon based on resource settings
		// PHP logic: day horizon is calculated first but month horizon OVERWRITES it
		if (resourceData.booking_month_horizon) {
			// Month horizon: use month_shifter logic and set to end of day (23:59:59)
			horizonEnd = monthShifter(now, resourceData.booking_month_horizon).endOf('day');
		} else if (resourceData.booking_day_horizon) {
			// Only day horizon (no month horizon): simple +X days from now
			horizonEnd = now.plus({ days: resourceData.booking_day_horizon });
		}

		// Find active season that contains current date
		const activeSeason = seasons?.find(season => {
			const seasonStart = DateTime.fromISO(season.from_);
			const seasonEnd = DateTime.fromISO(season.to_);
			return now >= seasonStart && now <= seasonEnd;
		});

		// If there's a season limit, use the minimum of horizon and season end
		if (activeSeason) {
			const seasonEnd = DateTime.fromISO(activeSeason.to_);
			if (horizonEnd) {
				return horizonEnd < seasonEnd ? horizonEnd : seasonEnd;
			}
			return seasonEnd;
		}

		// Return horizon limit (or null if no limits configured)
		return horizonEnd;
	}, [resourceData, seasons])

	const visibleTimeslots = useMemo(() => {
		if (!freeTimeSlots || !currentResourceId) return [];

		// Get the slots for the single enabled resource
		const resourceId = currentResourceId
		const slots = freeTimeSlots[resourceId] || [];

		// Get the current date/time to filter out past slots
		const now = DateTime.now();

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

			// Filter out slots in the past
			if (slotStart < now) {
				return false;
			}

			// Filter by horizon limit (resource booking horizon + season limit)
			if (horizonLimit && slotStart > horizonLimit) {
				return false;
			}

			// Filter by the selected date range
			return slotStart >= startOfRange && slotEnd <= endOfRange;
		}).sort((a, b) => {
			return DateTime.fromISO(a.start_iso) < DateTime.fromISO(b.start_iso) ? -1 : 1;
		});
	}, [freeTimeSlots, viewRange, props.currentDate, currentResourceId, horizonLimit]);

	// Track which slot is currently being processed
	const [processingSlotId, setProcessingSlotId] = React.useState<string | null>(null);

	const handleSlotAction = (slot: IFreeTimeSlot) => {
		// Create a unique ID for this slot to track processing state
		const slotId = `${slot.start_iso}-${slot.resource_id}`;
		setProcessingSlotId(slotId);

		const isRemovable = slot.overlap &&
			slot.overlap_reason === 'complete_overlap' &&
			slot.overlap_type === 'complete' &&
			slot.overlap_event?.type === 'application' &&
			slot.overlap_event?.status === 'NEWPARTIAL1' &&
			slot.overlap_event?.id !== undefined;

		if (isRemovable && slot.overlap_event?.id) {
			// Delete the overlapping application
			deletePartialApp.mutate(slot.overlap_event.id, {
				onSuccess: () => {
					// Check if websocket connection is active
					const isWebSocketActive = webSocketService.isReady && 
	webSocketService.status === 'OPEN' && 
	webSocketService.sessionConnected;

					// If websocket is not active, manually refetch the timeslots
					if (!isWebSocketActive) {
						refetchTimeSlots();
						console.log("WebSocket not fully connected - manual refetch required")

					}
					setProcessingSlotId(null);
				},
				onError: () => {
					setProcessingSlotId(null);
				}
			});
		} else {
			// Create a new application
			createSimpleApp.mutate({timeslot: slot, building_id: props.building.id}, {
				onSuccess: () => {
					// Check if websocket connection is active
					const isWebSocketActive = webSocketService.isReady && 
	webSocketService.status === 'OPEN' && 
	webSocketService.sessionConnected;


					// If websocket is not active, manually refetch the timeslots
					if (!isWebSocketActive) {
						refetchTimeSlots().then(() => setProcessingSlotId(null));
						console.log("WebSocket not fully connected - manual refetch required")
					} else {
						setProcessingSlotId(null);
					}
				},
				onError: () => {
					setProcessingSlotId(null);
				}
			});
		}
	};

	return (
		<div className={styles.calendarBody}>
			{isLoading ? (
				<div className={styles.loading}>
					<Spinner data-size={'lg'} aria-label={t('common.loading')} />
					<div>{t('common.loading')}</div>
				</div>
			) : (
				<>
					{visibleTimeslots.map((slot, index) => {
						// Create a unique ID for this slot to check if it's being processed
						const slotId = `${slot.start_iso}-${slot.resource_id}`;
						const isProcessingThisSlot = processingSlotId === slotId;

						return (
							<TimeSlotCard
								key={`${slot.when}`}
								slot={slot}
								resourceId={currentResourceId}
								onSelect={handleSlotAction}
								isProcessing={isProcessingThisSlot}
							/>
						);
					})}
					{!isLoading && visibleTimeslots.length === 0 && (
						<div className={styles.noSlots}>
							No available time slots for this period
						</div>
					)}
				</>
			)}
		</div>
	);
}

export default TimeslotView