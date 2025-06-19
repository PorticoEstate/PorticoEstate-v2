import {IResource} from "@/service/types/resource.types";
import {IBuilding} from "@/service/types/Building";

/**
 * Utility functions for handling resource deactivation logic.
 * Building-level deactivation flags override resource-level flags.
 */

/**
 * Check if calendar functionality should be deactivated for a resource.
 * Building-level deactivation overrides resource-level settings.
 * 
 * @param resource - The resource to check
 * @param building - The building containing the resource
 * @returns true if calendar should be deactivated
 */
export function isCalendarDeactivated(resource: IResource, building: IBuilding): boolean {
    // Building-level deactivation overrides resource-level
    if (building.deactivate_calendar) {
        return true;
    }
    
    // Otherwise, use resource-level setting
    return resource.deactivate_calendar;
}

/**
 * Check if application functionality should be deactivated for a resource.
 * Building-level deactivation overrides resource-level settings.
 * 
 * @param resource - The resource to check
 * @param building - The building containing the resource
 * @returns true if applications should be deactivated
 */
export function isApplicationDeactivated(resource: IResource, building: IBuilding): boolean {
    // Building-level deactivation overrides resource-level
    if (building.deactivate_application) {
        return true;
    }
    
    // Otherwise, use resource-level setting
    return resource.deactivate_application;
}

/**
 * Check if both calendar and application functionality should be deactivated for a resource.
 * Building-level deactivation overrides resource-level settings.
 * 
 * @param resource - The resource to check
 * @param building - The building containing the resource
 * @returns object with calendar and application deactivation status
 */
export function getResourceDeactivationStatus(resource: IResource, building: IBuilding): {
    calendar: boolean;
    application: boolean;
} {
    return {
        calendar: isCalendarDeactivated(resource, building),
        application: isApplicationDeactivated(resource, building)
    };
}