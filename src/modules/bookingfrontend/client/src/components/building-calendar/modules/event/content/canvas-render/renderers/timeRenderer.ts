import { CanvasDrawContext } from "../types";
import { drawTruncatedText, drawDebugOutline } from "../utils";
import { DEBUG_CANVAS_DIMENSIONS, DEBUG_CANVAS_VISUAL } from '../useCanvasDimensions';

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
  const { ctx, dimensions, layoutType, layoutComponents, layoutConfig } = context;
  
  // Check if time and resourceCircles should be rendered side-by-side
  const isSideBySide = layoutConfig.sideBySideComponents?.time_resourceCircles === true;
  
  // Skip if time component is not enabled
  if (!layoutComponents.includes('time')) {
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Time component skipped - not in enabled components list`);
    }
    return { yPos, displayTime: timeText };
  }
  
  // If in very narrow containers or in minimal layouts that are side-by-side, show only start time
  const displayTime = (dimensions.width < 100 || (isSideBySide && dimensions.width < 120))
    ? timeText.split(' - ')[0]
    : timeText;

  // Calculate max width for time text
  // When side-by-side, time should only take 35px of width
  const timeMaxWidth = isSideBySide ? 35 : dimensions.width;
  
  // Add consistent internal padding
  const paddingTop = 14; // Padding from top of component
  const adjustedYPos = yPos + paddingTop;
  
  if (DEBUG_CANVAS_DIMENSIONS) {
    console.log(`Rendering time component`, {
      timeText,
      displayTime,
      yPos,
      adjustedYPos,
      paddingTop,
      isSideBySide,
      timeMaxWidth,
      truncated: displayTime !== timeText,
      rule: (dimensions.width < 100 || (isSideBySide && dimensions.width < 120))
        ? 'Using shortened time (start time only)' 
        : 'Using full time range'
    });
  }

  // Draw time text with ellipsis if needed
  drawTruncatedText(ctx, displayTime, 0, Math.round(adjustedYPos), timeMaxWidth);
  
  // Add debug outline for the entire component (including padding)
  const timeTextMetrics = ctx.measureText(displayTime);
  const textHeight = 14; // Approximate text height
  const componentHeight = paddingTop + textHeight;
  
  drawDebugOutline(
    ctx, 
    'Time', 
    0, 
    yPos, 
    isSideBySide ? Math.min(35, timeTextMetrics.width + 5) : dimensions.width, 
    componentHeight
  );

  // Calculate the next component's starting position
  // If we're in side-by-side mode, we return the same yPos to not advance vertically
  // Otherwise, we return the position after this component (including its padding)
  return {
    yPos: isSideBySide ? yPos : yPos + componentHeight,
    displayTime
  };
}