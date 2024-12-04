import {createContext, FC, PropsWithChildren, useContext} from 'react';
import {FCallTempEvent} from "@/components/building-calendar/building-calendar.types";
import {IShortResource} from "@/service/pecalendar.types";


interface CalendarContextType {
    tempEvents: Record<string, FCallTempEvent>;
    setTempEvents: (value: (((prevState: Record<string, FCallTempEvent>) => Record<string, FCallTempEvent>) | Record<string, FCallTempEvent>)) => void
    setEnabledResources: (value: (((prevState: Set<string>) => Set<string>) | Set<string>)) => void
    enabledResources: Set<string>
    setResourcesHidden: (value: boolean) => void
    resourcesHidden: boolean
    currentBuilding: number | string;
}


const CalendarContext = createContext<CalendarContextType | undefined>(undefined);


export const useTempEvents = () => {
    const ctx = useCalendarContext();
    return {tempEvents: ctx.tempEvents, setTempEvents: ctx.setTempEvents};
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

interface CalendarContextProps extends CalendarContextType {
    // resourceToIds: { [p: number]: number };
    // resources: Record<string, IBuildingResource>;

}

const CalendarProvider: FC<PropsWithChildren<CalendarContextProps>> = (props) => {
    return (
        <CalendarContext.Provider value={{
            currentBuilding: props.currentBuilding,
            setResourcesHidden: props.setResourcesHidden,
            resourcesHidden: props.resourcesHidden,
            setTempEvents: props.setTempEvents,
            tempEvents: props.tempEvents,
            enabledResources: props.enabledResources,
            setEnabledResources: props.setEnabledResources
        }}>
            {props.children}
        </CalendarContext.Provider>
    );
}

export default CalendarProvider


