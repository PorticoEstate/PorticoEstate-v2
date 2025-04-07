import {DateTime} from "luxon";
import {fetchBuildingScheduleOLD, fetchBuildingSeasons, fetchFreeTimeSlotsForRange} from "@/service/api/api-utils";
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

	// Get start and end dates for initial free time slots
	const startDate = initialDate.startOf('week');
	const endDate = startDate.plus({ weeks: 1 }); // Fetch 2 weeks initially

	try {
        const [initialFreeTime, building, buildingResources, seasons] = await Promise.all([
			fetchFreeTimeSlotsForRange(buildingId, startDate, endDate),
            fetchBuilding(buildingId),
            fetchBuildingResources(buildingId),
			fetchBuildingSeasons(+building_id)
        ]);
        return (
            <CalendarWrapper
                initialDate={initialDate.toJSDate()}
                initialFreeTime={initialFreeTime}
                buildingId={buildingId}
                resources={buildingResources}
                seasons={seasons}
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



