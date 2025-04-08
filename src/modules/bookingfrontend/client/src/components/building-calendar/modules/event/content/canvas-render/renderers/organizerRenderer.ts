import { CanvasComponentRenderer, CanvasDrawContext, ComponentRenderResult } from "../types";
import { drawTruncatedText, drawDebugOutline, DEBUG_CANVAS_DIMENSIONS, DEBUG_CANVAS_VISUAL } from "../utils";
import { IEventIsAPIEvent } from "@/service/pecalendar.types";

/**
 * Organizer component renderer implementation
 */
export const organizerRenderer: CanvasComponentRenderer = {
  name: 'organizer',
  render: (
    context: CanvasDrawContext,
    props: {
      eventData: any,
      maxHeight?: number // Optional max height constraint
    },
    x: number,
    y: number,
    availableWidth: number,
    availableHeight: number
  ): ComponentRenderResult => {
    const { ctx } = context;
    const { eventData, maxHeight } = props;

    // Default return in case there's no data to render
    const emptyResult: ComponentRenderResult = {
      usedWidth: 0,
      usedHeight: 0,
      xAdvance: 0,
      yAdvance: 0
    };

    // Only render if there's organizer data
    if (!IEventIsAPIEvent(eventData) || !eventData?.organizer) {
      if (DEBUG_CANVAS_DIMENSIONS) {
        console.log(`Organizer component has no data to render`, {
          hasOrganizerData: IEventIsAPIEvent(eventData) && !!eventData?.organizer
        });
      }
      return emptyResult;
    }

    // Calculate component height based on constraints
    // Default component height if not specified
    const defaultHeight = 24;

    // Determine the actual height to use - minimum of available height, maxHeight (if specified), or default
    const effectiveMaxHeight = maxHeight ? Math.min(availableHeight, maxHeight) : availableHeight;
    const componentHeight = Math.min(effectiveMaxHeight, defaultHeight);

    // Calculate vertical position to center text
    const textBaseline = Math.floor(componentHeight / 2);
    const organizerMaxWidth = availableWidth;
    const organizerY = y + textBaseline;

    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Rendering organizer component`, {
        organizer: eventData.organizer,
        x,
        y,
        organizerY,
        componentHeight,
        availableHeight,
        effectiveMaxHeight,
        defaultHeight,
        organizerMaxWidth
      });
    }

    // Draw the organizer text and get dimensions
    const textResult = drawTruncatedText(ctx, eventData.organizer, x, Math.round(organizerY), organizerMaxWidth);

    // We've already calculated component height based on constraints

    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Organizer text rendered with dimensions:`, {
        textWidth: textResult.width,
        textHeight: textResult.height,
        truncated: textResult.truncated
      });
    }

    // Draw debug outline
    drawDebugOutline(
      ctx,
      'Organizer',
      x,
      y,
      availableWidth,
      componentHeight
    );

    // Return component dimensions and how much to advance
    return {
      usedWidth: availableWidth, // Organizer typically uses full width
      usedHeight: componentHeight, // The actual height used
      xAdvance: 0, // Organizer always takes the full width
      yAdvance: componentHeight // Always advance Y after organizer
    };
  }
};

/**
 * Legacy function for backward compatibility
 * @returns The new Y position after rendering
 */
export function renderOrganizer(
  context: CanvasDrawContext,
  eventData: any,
  yPos: number
): number {
  // Skip if organizer component is not enabled in this layout
  if (!context.layoutComponents.includes('organizer')) {
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Organizer component skipped`, {
        reason: `Not included in layout '${context.layoutType}'`,
        hasOrganizerData: IEventIsAPIEvent(eventData) && !!eventData?.organizer,
        enabledComponents: context.layoutComponents
      });
    }
    return yPos;
  }

  // Call the component renderer with appropriate props
  const result = organizerRenderer.render(
    context,
    { eventData },
    0,
    yPos,
    context.dimensions.width,
    context.dimensions.height - yPos
  );

  return yPos + result.yAdvance;
}