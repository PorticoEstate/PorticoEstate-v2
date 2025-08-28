import {createContext, FC, PropsWithChildren, useContext, useMemo} from 'react';
import {FCallTempEvent} from "@/components/building-calendar/building-calendar.types";
import {usePartialApplications} from "@/service/hooks/api-hooks";
import {applicationTimeToLux} from "@/components/layout/header/shopping-cart/shopping-cart-content";
import {useBuildingResources} from "@/service/api/building";
import {ResourceUsesTimeSlots} from "@/components/building-calendar/util/calender-helpers";


interface CalendarContextType {
	tempEvents: Record<string, FCallTempEvent>;
	setEnabledResources: (value: (((prevState: Set<string>) => Set<string>) | Set<string>)) => void
	enabledResources: Set<string>
	setResourcesHidden: (value: boolean) => void
	resourcesHidden: boolean
	currentBuilding?: number | string;
	currentOrganization?: number | string;
	calendarViewMode: ICalendarViewMode;
}


export type ICalendarViewMode = 'calendar' | 'timeslots' | 'none';

const CalendarContext = createContext<CalendarContextType | undefined>(undefined);


export const useTempEvents = () => {
	const ctx = useCalendarContext();
	return {tempEvents: ctx.tempEvents};
}


export const useCalenderViewMode = () => {
	const ctx = useCalendarContext();
	return ctx.calendarViewMode;
}

export const useResourcesHidden = () => {
	const ctx = useCalendarContext();
	return {resourcesHidden: ctx.resourcesHidden, setResourcesHidden: ctx.setResourcesHidden};
}
export const useCurrentBuilding = () => {
	const ctx = useCalendarContext();
	return ctx.currentBuilding;
}

export const useCurrentOrganization = () => {
	const ctx = useCalendarContext();
	return ctx.currentOrganization;
}

export const useIsOrganization = () => {
	const ctx = useCalendarContext();
	return !!ctx.currentOrganization && !ctx.currentBuilding;
}


export const useEnabledResources = () => {
	const {setEnabledResources, enabledResources} = useCalendarContext();
	return {setEnabledResources, enabledResources};
}
export const useCalendarContext = () => {
	const context = useContext(CalendarContext);
	if (context === undefined) {
		throw new Error('useCalendarContext must be used within a CalendarProvider');
	}
	return context;
};

interface CalendarContextProps extends Omit<CalendarContextType, 'tempEvents' | 'calendarViewMode'> {

}

const CalendarProvider: FC<PropsWithChildren<CalendarContextProps>> = (props) => {
	const {data: cartItems} = usePartialApplications();
	const {data: resources} = useBuildingResources(props.currentBuilding);


	const viewMode: ICalendarViewMode = useMemo(() => {
		if(props.currentOrganization) {
			return 'calendar'
		}
		if (!resources || !props.enabledResources) {
			// Default to calendar view for organization mode when resources aren't loaded
			return 'calendar';
		}

		if(props.enabledResources.size === 0) {
			return 'calendar';

		}
		// If any slotted resource is selected, we're in slot view
		const enabledResources = [...(props.enabledResources.values() || [])]
		const hasSlottedResourceEnable = enabledResources.some(id => ResourceUsesTimeSlots(resources.find(res => +res.id === +id)!));

		if (hasSlottedResourceEnable) {
			return 'timeslots';
		}

		// If nothing is selected
		return 'calendar';
	}, [props.enabledResources, resources]);


	const tempEvents: Record<string, FCallTempEvent> = useMemo(() => {
		if(props.currentOrganization !== undefined || !props.currentBuilding) {
			return {}
		}
		return (cartItems?.list || []).reduce<Record<string, FCallTempEvent>>((all, curr) => {
			if (!curr.resources?.some(res => res.building_id != null && +res.building_id === +props.currentBuilding!)) {
				return all;
			}

			const temp = all;
			const dates = curr.dates;
			dates.forEach(date => {
				temp[date.id] = {
					allDay: false,
					editable: true,
					start: applicationTimeToLux(date.from_).toJSDate(),
					end: applicationTimeToLux(date.to_).toJSDate(),
					extendedProps: {
						resources: curr.resources.map(a => a.id),
						type: "temporary",
						applicationId: curr.id,
						building_id: curr.building_id
					},
					id: `${date.id}`,
					title: curr.name
				}
			})

			return temp;

		}, {})

	}, [cartItems?.list, props.currentBuilding])


	return (
		<CalendarContext.Provider value={{
			currentBuilding: props.currentBuilding,
			currentOrganization: props.currentOrganization,
			setResourcesHidden: props.setResourcesHidden,
			resourcesHidden: props.resourcesHidden,
			tempEvents: tempEvents,
			enabledResources: props.enabledResources,
			setEnabledResources: props.setEnabledResources,
			calendarViewMode: viewMode
		}}>
			{props.children}
		</CalendarContext.Provider>
	);
}

export default CalendarProvider


