import { CanvasComponentRenderer, CanvasDrawContext, ComponentRenderResult } from "../types";
import { drawTruncatedText, drawDebugOutline, DEBUG_CANVAS_DIMENSIONS, DEBUG_CANVAS_VISUAL } from "../utils";

/**
 * Title component renderer implementation
 */
export const titleRenderer: CanvasComponentRenderer = {
  name: 'title',
  render: (
    context: CanvasDrawContext,
    props: {
      title: string,
      displayTime: string,
      maxHeight?: number // Optional max height constraint
    },
    x: number,
    y: number,
    availableWidth: number,
    availableHeight: number
  ): ComponentRenderResult => {
    const { ctx } = context;
    const { title, maxHeight } = props;

    // Calculate component height based on constraints
    // Default component height if not specified
    const defaultHeight = 24;

    // Determine the actual height to use - minimum of available height, maxHeight (if specified), or default
    const effectiveMaxHeight = maxHeight ? Math.min(availableHeight, maxHeight) : availableHeight;
    const componentHeight = Math.min(effectiveMaxHeight, defaultHeight);

    // Calculate vertical position to center text
    const textBaseline = Math.floor(componentHeight / 2);
    const titleX = x;
    const titleY = Math.round(y + textBaseline);

    // Use semibold font for title
    ctx.font = `600 12px 'Roboto', sans-serif`;

    // Calculate max width for title
    const titleMaxWidth = availableWidth;

    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Rendering title component`, {
        title,
        titleX,
        titleY,
        titleMaxWidth,
        componentHeight,
        availableHeight,
        effectiveMaxHeight,
        defaultHeight,
        fontWeight: '600'
      });
    }

    // Draw title with truncation if needed and get dimensions
    const textResult = drawTruncatedText(ctx, title, Math.round(titleX), titleY, titleMaxWidth);

    // We've already calculated the component height based on constraints

    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Title text rendered with dimensions:`, {
        textWidth: textResult.width,
        textHeight: textResult.height,
        truncated: textResult.truncated
      });
    }

    // Draw debug outline
    drawDebugOutline(
      ctx,
      'Title',
      x,
      y,
      availableWidth,
      componentHeight
    );

    // Reset font to normal weight
    ctx.font = `12px 'Roboto', sans-serif`;

    // Return component dimensions and how much to advance
    return {
      usedWidth: availableWidth,  // Title typically uses full width
      usedHeight: componentHeight, // The actual height used
      xAdvance: 0, // Title always takes the full width, so no X advance
      yAdvance: componentHeight // Always advance Y after title
    };
  }
};

/**
 * Legacy function for backward compatibility
 * @returns The new Y position after rendering
 */
export function renderTitle(
  context: CanvasDrawContext,
  title: string,
  displayTime: string,
  yPos: number
): number {
  // Skip if title component is not enabled in this layout
  if (!context.layoutComponents.includes('title')) {
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Title component skipped`, {
        reason: `Not included in layout '${context.layoutType}'`,
        title,
        enabledComponents: context.layoutComponents
      });
    }
    return yPos;
  }

  // Call the component renderer with appropriate props
  const result = titleRenderer.render(
    context,
    { title, displayTime },
    0,
    yPos,
    context.dimensions.width,
    context.dimensions.height - yPos
  );

  return yPos + result.yAdvance;
}