import {DateTime} from "luxon";
import {fetchBuildingSchedule, fetchFreeTimeSlots} from "@/service/api/api-utils";
import {fetchBuilding} from "@/service/api/building";
import CalendarWrapper from "@/components/building-calendar/CalendarWrapper";
import NotFound from "next/dist/client/components/not-found-error";

interface BuildingCalendarProps {
    building_id: string;
    resource_id?: string;
}

const BuildingCalendar = async (props: BuildingCalendarProps) => {
    const {building_id, resource_id} = props;


    const buildingId = parseInt(building_id, 10);
    const initialDate = DateTime.now();
    const weeksToFetch = [
        initialDate.startOf('week').toFormat("y-MM-dd"),
        initialDate.startOf('week').plus({week: 1}).toFormat("y-MM-dd"),
    ];

    try {
        const [initialSchedule, initialFreeTime, building] = await Promise.all([
            fetchBuildingSchedule(buildingId, weeksToFetch),
            fetchFreeTimeSlots(buildingId),
            fetchBuilding(buildingId)
        ]);
        return (
            <CalendarWrapper
                initialDate={initialDate.toJSDate()}
                initialSchedule={initialSchedule.schedule || []}
                initialFreeTime={initialFreeTime}
                buildingId={buildingId}
                resources={initialSchedule.resources}
                seasons={initialSchedule.seasons}
                building={building}
                resourceId={resource_id}
            />
        );
    } catch (error) {
        console.error('Error fetching initial data:', error);
        return NotFound();
    }
}

export default BuildingCalendar



