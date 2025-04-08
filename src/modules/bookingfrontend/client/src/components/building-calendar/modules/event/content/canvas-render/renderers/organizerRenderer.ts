import { CanvasDrawContext } from "../types";
import { drawTruncatedText } from "../utils";
import { IEventIsAPIEvent } from "@/service/pecalendar.types";

/**
 * Renders the organizer information on the canvas
 * @returns The new Y position after rendering
 */
export function renderOrganizer(
  context: CanvasDrawContext, 
  eventData: any, 
  yPos: number
): number {
  const { ctx, dimensions, layoutType } = context;
  
  // Only render for standard and large layouts if there's organizer data
  if ((layoutType === 'standard' || layoutType === 'large') &&
      IEventIsAPIEvent(eventData) && eventData?.organizer) {

    const organizerMaxWidth = dimensions.width;
    drawTruncatedText(ctx, eventData.organizer, 0, Math.round(yPos), organizerMaxWidth);
    
    // Update y position for next element
    return yPos + 24;
  }
  
  // If not rendered, return the same y position
  return yPos;
}