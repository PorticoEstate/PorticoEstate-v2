import { CanvasDrawContext } from "../types";
import { drawTruncatedText } from "../utils";

interface TimeRenderResult {
  yPos: number;
  displayTime: string;
}

/**
 * Renders the time information on the canvas
 * @returns The new Y position after rendering and the displayed time text
 */
export function renderTime(
  context: CanvasDrawContext, 
  timeText: string, 
  yPos: number
): TimeRenderResult {
  const { ctx, dimensions, layoutType } = context;
  
  // For minimal layouts or very narrow containers, show only start time
  const displayTime = (layoutType === 'minimal' || dimensions.width < 100)
    ? timeText.split(' - ')[0]
    : timeText;

  // Calculate max width for time text
  const timeMaxWidth = dimensions.width;

  // Draw time text with ellipsis if needed
  drawTruncatedText(ctx, displayTime, 0, Math.round(yPos), timeMaxWidth);

  // Return updated yPos for next element and the displayTime for other renderers that might need it
  return {
    yPos: yPos + (layoutType === 'minimal' || layoutType === 'short' ? 0 : 20),
    displayTime
  };
}