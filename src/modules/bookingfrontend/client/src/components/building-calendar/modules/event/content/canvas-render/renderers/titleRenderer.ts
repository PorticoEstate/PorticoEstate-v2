import { CanvasDrawContext } from "../types";
import { drawTruncatedText } from "../utils";

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
  const { ctx, dimensions, layoutType } = context;
  
  // Skip rendering if minimal layout
  if (layoutType === 'minimal') return yPos;
  
  // For short layout, title is on same line as time
  const titleX = layoutType === 'short' ? ctx.measureText(displayTime).width + 10 : 0;
  const titleY = Math.round(layoutType === 'short' ? yPos - 20 : yPos);

  // Use semibold font for title
  ctx.font = `600 12px 'Roboto', sans-serif`;

  // Calculate max width for title
  const titleMaxWidth = layoutType === 'short'
    ? dimensions.width - titleX - 40 // Leave space for resource circles
    : dimensions.width;

  drawTruncatedText(ctx, title, Math.round(titleX), titleY, titleMaxWidth);

  // Reset font to normal weight
  ctx.font = `12px 'Roboto', sans-serif`;

  // Update y position for next element
  return layoutType !== 'short' ? yPos + 20 : yPos;
}