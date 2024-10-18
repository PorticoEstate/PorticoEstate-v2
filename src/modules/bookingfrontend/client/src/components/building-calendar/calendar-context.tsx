import {createContext, FC, PropsWithChildren, useContext} from 'react';
import {FCallTempEvent} from "@/components/building-calendar/building-calendar.types";
import {IShortResource} from "@/service/pecalendar.types";


interface CalendarContextType {
    resources: Record<string, IShortResource>;
    tempEvents: Record<string, FCallTempEvent>;
    setTempEvents: (value: (((prevState: Record<string, FCallTempEvent>) => Record<string, FCallTempEvent>) | Record<string, FCallTempEvent>)) => void
    enabledResources: Set<string>

}


const CalendarContext = createContext<CalendarContextType | undefined>(undefined);



export const useTempEvents = () => {
    const ctx = useCalendarContext();
    return {tempEvents: ctx.tempEvents, setTempEvents: ctx.setTempEvents};
}

export const useAvailableResources = () => {
    const ctx = useCalendarContext();
    return ctx.resources;
}
export const useEnabledResources = () => {
    const ctx = useCalendarContext();
    return ctx.enabledResources;
}
export const useCalendarContext = () => {
    const context = useContext(CalendarContext);
    if (context === undefined) {
        throw new Error('useCalendarContext must be used within a CalendarProvider');
    }
    return context;
};

interface CalendarContextProps extends CalendarContextType{
    // resourceToIds: { [p: number]: number };
    // resources: Record<string, IBuildingResource>;

}

const CalendarProvider: FC<PropsWithChildren<CalendarContextProps>> = (props) => {
    return (
        <CalendarContext.Provider value={{resources: props.resources, setTempEvents: props.setTempEvents, tempEvents: props.tempEvents, enabledResources: props.enabledResources}}>
            {props.children}
        </CalendarContext.Provider>
    );
}

export default CalendarProvider


