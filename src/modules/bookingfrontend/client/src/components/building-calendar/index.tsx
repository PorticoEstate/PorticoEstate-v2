import {DateTime} from "luxon";
import {fetchBuildingSchedule, fetchBuildingScheduleOLD, fetchFreeTimeSlots} from "@/service/api/api-utils";
import {fetchBuilding, fetchBuildingResources} from "@/service/api/building";
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
        initialDate.set({weekday: 1}).startOf('day').toFormat("y-MM-dd"),
        initialDate.set({weekday: 1}).startOf('day').plus({week: 1}).toFormat("y-MM-dd"),
    ];

    try {
        const [initialSchedule, initialFreeTime, building, initialWeekSchedule, buildingResources] = await Promise.all([
            fetchBuildingScheduleOLD(buildingId, weeksToFetch),
            fetchFreeTimeSlots(buildingId),
            fetchBuilding(buildingId),
            fetchBuildingSchedule(buildingId, weeksToFetch),
            fetchBuildingResources(buildingId)
        ]);
        return (
            <CalendarWrapper
                initialDate={initialDate.toJSDate()}
                initialSchedule={initialSchedule.schedule || []}
                initialFreeTime={initialFreeTime}
                buildingId={buildingId}
                resources={buildingResources}
                seasons={initialSchedule.seasons}
                building={building}
                resourceId={resource_id}
                initialWeekSchedule={initialWeekSchedule}
            />
        );
    } catch (error) {
        console.error('Error fetching initial data:', error);
        return NotFound();
    }
}

export default BuildingCalendar



