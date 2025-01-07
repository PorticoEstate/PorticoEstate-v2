import {createContext, FC, PropsWithChildren, useContext, useMemo} from 'react';
import {FCallTempEvent} from "@/components/building-calendar/building-calendar.types";
import {usePartialApplications} from "@/service/hooks/api-hooks";
import {applicationTimeToLux} from "@/components/layout/header/shopping-cart/shopping-cart-content";


interface CalendarContextType {
    tempEvents: Record<string, FCallTempEvent>;
    setEnabledResources: (value: (((prevState: Set<string>) => Set<string>) | Set<string>)) => void
    enabledResources: Set<string>
    setResourcesHidden: (value: boolean) => void
    resourcesHidden: boolean
    currentBuilding: number | string;
}


const CalendarContext = createContext<CalendarContextType | undefined>(undefined);


export const useTempEvents = () => {
    const ctx = useCalendarContext();
    return {tempEvents: ctx.tempEvents};
}

export const useResourcesHidden = () => {
    const ctx = useCalendarContext();
    return {resourcesHidden: ctx.resourcesHidden, setResourcesHidden: ctx.setResourcesHidden};
}
export const useCurrentBuilding = () => {
    const ctx = useCalendarContext();
    return ctx.currentBuilding;
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

interface CalendarContextProps extends Omit<CalendarContextType, 'tempEvents'> {

}

const CalendarProvider: FC<PropsWithChildren<CalendarContextProps>> = (props) => {
    const {data: cartItems} = usePartialApplications();

    const tempEvents: Record<string, FCallTempEvent> = useMemo(() => {
        return (cartItems?.list || []).reduce<Record<string, FCallTempEvent>>((all, curr) => {
            if(!curr.resources.some(res => res.building_id != null && +res.building_id === +props.currentBuilding)){
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
                    extendedProps: {resources: curr.resources.map(a => a.id), type: "temporary", applicationId: curr.id, building_id: curr.building_id},
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
            setResourcesHidden: props.setResourcesHidden,
            resourcesHidden: props.resourcesHidden,
            tempEvents: tempEvents,
            enabledResources: props.enabledResources,
            setEnabledResources: props.setEnabledResources
        }}>
            {props.children}
        </CalendarContext.Provider>
    );
}

export default CalendarProvider


