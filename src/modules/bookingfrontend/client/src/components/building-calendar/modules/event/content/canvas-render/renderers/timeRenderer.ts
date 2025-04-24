import { CanvasComponentRenderer, CanvasDrawContext, ComponentRenderResult } from "../types";
import { drawTruncatedText, drawDebugOutline, DEBUG_CANVAS_DIMENSIONS, DEBUG_CANVAS_VISUAL } from "../utils";

interface TimeRenderResult {
  yPos: number;
  displayTime: string;
}

/**
 * Time component renderer implementation
 */
export const timeRenderer: CanvasComponentRenderer = {
  name: 'time',
  render: (
    context: CanvasDrawContext,
    props: {
      timeText: string,
      maxWidth?: number, // Optional max width constraint
      maxHeight?: number // Optional max height constraint
    },
    x: number,
    y: number,
    availableWidth: number,
    availableHeight: number
  ): ComponentRenderResult => {
    const { ctx, dimensions, layoutConfig } = context;
    const { timeText, maxWidth, maxHeight } = props;

    // Check if we're in horizontal rendering mode
    const isHorizontalMode = layoutConfig.horizontalRendering === true;

    // If in narrow containers or in horizontal mode, show only start time
    const displayTime = (availableWidth < 100 || (isHorizontalMode && availableWidth < 120))
      ? timeText.split(' - ')[0]
      : timeText;

    // Calculate max width for time text
    // Use explicit maxWidth if provided, or limit to 35px in horizontal mode
    let timeMaxWidth = availableWidth;
    if (maxWidth) {
      timeMaxWidth = Math.min(timeMaxWidth, maxWidth);
    } else if (isHorizontalMode) {
      timeMaxWidth = Math.min(timeMaxWidth, 35); // Default limit in horizontal mode
    }

    // Calculate component height based on constraints
    // Default component height if not specified
    const defaultHeight = 24;

    // Determine the actual height to use - minimum of available height, maxHeight (if specified), or default
    const effectiveMaxHeight = maxHeight ? Math.min(availableHeight, maxHeight) : availableHeight;
    const componentHeight = Math.min(effectiveMaxHeight, defaultHeight);

    // Calculate vertical position to center text
    const textBaseline = Math.floor(componentHeight / 2);
    const adjustedYPos = y + textBaseline;

    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Rendering time component`, {
        timeText,
        displayTime,
        x,
        y,
        adjustedYPos,
        isHorizontalMode,
        timeMaxWidth,
        componentHeight,
        availableHeight,
        effectiveMaxHeight,
        defaultHeight,
        truncated: displayTime !== timeText,
        rule: (availableWidth < 100 || (isHorizontalMode && availableWidth < 120))
          ? 'Using shortened time (start time only)'
          : 'Using full time range'
      });
    }

    // Draw time text with ellipsis if needed and get dimensions
    const textResult = drawTruncatedText(ctx, displayTime, x, Math.round(adjustedYPos), timeMaxWidth);

    // We've already calculated the component height based on constraints

    // Calculate component width based on text and constraints
    const textWidthWithMargin = textResult.width + 5;
    const componentWidth = Math.min(timeMaxWidth, textWidthWithMargin);

    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Time text rendered with dimensions:`, {
        textWidth: textResult.width,
        textHeight: textResult.height,
        truncated: textResult.truncated,
        calculatedWidth: componentWidth
      });
    }

    drawDebugOutline(
      ctx,
      'Time',
      x,
      y,
      componentWidth,
      componentHeight
    );

    // Return component dimensions and how much to advance
    return {
      usedWidth: componentWidth,  // The actual width used by the component
      usedHeight: componentHeight, // The actual height used by the component
      xAdvance: isHorizontalMode ? componentWidth : 0, // Only advance X in horizontal mode
      yAdvance: isHorizontalMode ? 0 : componentHeight  // Only advance Y in vertical mode
    };
  }
};

/**
 * Legacy function for backward compatibility
 * @returns The new Y position after rendering and the displayed time text
 */
export function renderTime(
  context: CanvasDrawContext,
  timeText: string,
  yPos: number
): TimeRenderResult {
  // Call the component renderer with appropriate props
  const result = timeRenderer.render(
    context,
    { timeText },
    0,
    yPos,
    context.dimensions.width,
    context.dimensions.height - yPos
  );

  return {
    yPos: yPos + result.yAdvance,
    displayTime: timeText  // We don't have access to the modified text here, so return original
  };
}