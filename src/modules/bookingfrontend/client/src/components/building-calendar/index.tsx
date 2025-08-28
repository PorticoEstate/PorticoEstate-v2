import {DateTime} from "luxon";
import {fetchBuildingScheduleOLD, fetchBuildingSeasons, fetchFreeTimeSlotsForRange, fetchOrganizationSchedule} from "@/service/api/api-utils";
import {fetchBuilding, fetchBuildingResources} from "@/service/api/building";
import CalendarWrapper from "@/components/building-calendar/CalendarWrapper";
import NotFound from "next/dist/client/components/not-found-error";
import {IBuilding} from "@/service/types/Building";

interface BuildingCalendarProps {
    building_id?: string;
    organization_id?: string;
    resource_id?: string;
    initialDate?: string; // ISO date string format
    readOnly?: boolean;
    buildings?: IBuilding[];
}

const BuildingCalendar = async (props: BuildingCalendarProps) => {
    const {building_id, organization_id, resource_id, initialDate: initialDateStr, readOnly = false, buildings} = props;

    if (!building_id && !organization_id) {
        throw new Error('Either building_id or organization_id must be provided');
    }

    const initialDate = initialDateStr ? DateTime.fromISO(initialDateStr) : DateTime.now();

	// Get start and end dates for initial free time slots
	const startDate = initialDate.startOf('week');
	const endDate = startDate.plus({ weeks: 1 }); // Fetch 2 weeks initially

	try {
        if (building_id) {
            // Building mode
            const buildingId = parseInt(building_id, 10);
            const [initialFreeTime, building, buildingResources, seasons] = await Promise.all([
                fetchFreeTimeSlotsForRange(buildingId, startDate, endDate),
                fetchBuilding(buildingId),
                fetchBuildingResources(buildingId),
                fetchBuildingSeasons(buildingId)
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
                    readOnly={readOnly}
                />
            );
        } else {
            // Organization mode
            const orgId = parseInt(organization_id!, 10);
            // For organization calendar, we don't need free time slots as it's read-only
            // We also don't have a single building or resources
            return (
                <CalendarWrapper
                    initialDate={initialDate.toJSDate()}
                    initialFreeTime={{}}
                    organizationId={orgId}
                    readOnly={true}
                    buildings={buildings || []}
                />
            );
        }
    } catch (error) {
        console.error('Error fetching initial data:', error);
        return NotFound();
    }
}

export default BuildingCalendar



