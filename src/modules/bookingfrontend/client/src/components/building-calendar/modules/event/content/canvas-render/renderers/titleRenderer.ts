import { CanvasDrawContext } from "../types";
import { drawTruncatedText, drawDebugOutline } from "../utils";
import { DEBUG_CANVAS_DIMENSIONS, DEBUG_CANVAS_VISUAL } from '../useCanvasDimensions';

/**
 * Renders the event title on the canvas
 * @returns The new Y position after rendering
 */
export function renderTitle(
  context: CanvasDrawContext, 
  title: string,
  displayTime: string,
  yPos: number
): number {
  const { ctx, dimensions, layoutType, layoutComponents } = context;
  
  // Skip if title component is not enabled in this layout
  if (!layoutComponents.includes('title')) {
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Title component skipped`, {
        reason: `Not included in layout '${layoutType}'`,
        title,
        enabledComponents: layoutComponents
      });
    }
    return yPos;
  }
  
  // Each component manages its own padding
  const paddingTop = 6; // Padding from previous component
  const paddingBottom = 4; // Padding after this component
  
  // Title is always on its own line
  const titleX = 0;
  const titleY = Math.round(yPos + paddingTop);

  // Use semibold font for title
  ctx.font = `600 12px 'Roboto', sans-serif`;

  // Calculate max width for title
  const titleMaxWidth = dimensions.width;

  if (DEBUG_CANVAS_DIMENSIONS) {
    console.log(`Rendering title component`, {
      title,
      titleX,
      titleY,
      titleMaxWidth,
      paddingTop,
      paddingBottom,
      rule: 'Title on separate line (medium layout or larger)',
      fontWeight: '600'
    });
  }

  drawTruncatedText(ctx, title, Math.round(titleX), titleY, titleMaxWidth);
  
  // Add debug outline for the entire component (including padding)
  const titleTextMetrics = ctx.measureText(title);
  const textHeight = 14; // Approximate text height
  const componentHeight = paddingTop + textHeight + paddingBottom;
  
  drawDebugOutline(
    ctx, 
    'Title', 
    0, 
    yPos, 
    dimensions.width, 
    componentHeight
  );

  // Reset font to normal weight
  ctx.font = `12px 'Roboto', sans-serif`;

  // Return position for the next component
  return yPos + componentHeight;
}