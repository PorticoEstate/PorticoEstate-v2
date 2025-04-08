import { CanvasDrawContext } from "../types";
import { drawTruncatedText, drawDebugOutline } from "../utils";
import { IEventIsAPIEvent } from "@/service/pecalendar.types";
import { DEBUG_CANVAS_DIMENSIONS, DEBUG_CANVAS_VISUAL } from '../useCanvasDimensions';

/**
 * Renders the organizer information on the canvas
 * @returns The new Y position after rendering
 */
export function renderOrganizer(
  context: CanvasDrawContext, 
  eventData: any, 
  yPos: number
): number {
  const { ctx, dimensions, layoutType, layoutComponents } = context;
  
  // Skip if organizer component is not enabled in this layout
  if (!layoutComponents.includes('organizer')) {
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Organizer component skipped`, {
        reason: `Not included in layout '${layoutType}'`,
        hasOrganizerData: IEventIsAPIEvent(eventData) && !!eventData?.organizer,
        enabledComponents: layoutComponents
      });
    }
    return yPos;
  }
  
  // Only render if there's organizer data
  if (IEventIsAPIEvent(eventData) && eventData?.organizer) {

    // Each component manages its own padding
    const paddingTop = 6; // Padding from previous component
    const paddingBottom = 4; // Padding after this component
    
    const organizerMaxWidth = dimensions.width;
    const organizerY = yPos + paddingTop;
    
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Rendering organizer component`, {
        organizer: eventData.organizer,
        yPos,
        organizerY,
        paddingTop,
        paddingBottom,
        organizerMaxWidth,
        rule: 'Only shown in standard or large layouts'
      });
    }
    
    drawTruncatedText(ctx, eventData.organizer, 0, Math.round(organizerY), organizerMaxWidth);
    
    // Add debug outline for the entire component (including padding)
    const organizerTextMetrics = ctx.measureText(eventData.organizer);
    const textHeight = 14; // Approximate text height
    const componentHeight = paddingTop + textHeight + paddingBottom;
    
    drawDebugOutline(
      ctx, 
      'Organizer', 
      0, 
      yPos, 
      dimensions.width, 
      componentHeight
    );
    
    // Return position for the next component
    return yPos + componentHeight;
  }
  
  // If no organizer data, return the same y position
  if (DEBUG_CANVAS_DIMENSIONS) {
    console.log(`Organizer component skipped`, {
      reason: 'No organizer data available',
      hasOrganizerData: false
    });
  }
  return yPos;
}