import { CanvasDrawContext, Dimensions, LayoutType } from "./types";
import { FCallEvent, FCEventContentArg } from "@/components/building-calendar/building-calendar.types";

/**
 * Determines the layout type based on container height
 */
export function determineLayoutType(height: number): LayoutType {
  if (height <= 30) return 'minimal';
  if (height <= 50) return 'short';
  if (height <= 75) return 'medium';
  if (height <= 100) return 'standard';
  return 'large';
}

/**
 * Sets up the canvas with proper dimensions and scaling
 */
export function setupCanvas(
  canvas: HTMLCanvasElement, 
  containerWidth: number, 
  containerHeight: number,
  devicePixelRatio: number
): Dimensions {
  // Round to full pixels to avoid sub-pixel rendering issues
  const width = Math.round(containerWidth);
  const height = Math.round(containerHeight);

  // Set dimensions for high DPI displays while matching container exactly
  canvas.width = Math.round(width * devicePixelRatio);
  canvas.height = Math.round(height * devicePixelRatio);

  // Match the style dimensions exactly to container size
  canvas.style.width = `${width}px`;
  canvas.style.height = `${height}px`;

  return { width, height };
}

/**
 * Draws text with ellipsis if it doesn't fit in the available width
 */
export function drawTruncatedText(
  ctx: CanvasRenderingContext2D,
  text: string, 
  x: number, 
  y: number, 
  maxWidth: number
): void {
  if (!text) return;

  // If text fits, simply draw it
  const textWidth = ctx.measureText(text).width;
  if (textWidth <= maxWidth) {
    ctx.fillText(text, x, y);
    return;
  }

  // Handle ellipsis calculation
  const ellipsis = '…'; // Unicode ellipsis
  const ellipsisWidth = ctx.measureText(ellipsis).width;

  // If we don't even have space for ellipsis alone
  if (maxWidth < ellipsisWidth) {
    return; // Don't draw anything
  }

  // Calculate available width for truncated text
  const availableWidth = maxWidth - ellipsisWidth;

  // Binary search for the right cut-off point
  let low = 0;
  let high = text.length;
  let best = 0;

  while (low <= high) {
    const mid = Math.floor((low + high) / 2);
    const testText = text.substring(0, mid);
    const testWidth = ctx.measureText(testText).width;

    if (testWidth <= availableWidth) {
      best = mid;
      low = mid + 1;
    } else {
      high = mid - 1;
    }
  }

  // Get the truncated text
  let truncatedText = text.substring(0, best);

  // Create a small buffer for better appearance
  while (ctx.measureText(truncatedText).width > availableWidth && truncatedText.length > 0) {
    truncatedText = truncatedText.slice(0, -1);
  }

  // Draw the truncated text with ellipsis
  ctx.fillText(truncatedText + ellipsis, x, y);
}

/**
 * Draws the remaining count indicator
 */
export function drawRemainingCount(
  ctx: CanvasRenderingContext2D,
  count: number, 
  x: number, 
  y: number
): void {
  // Ensure exact same center point as resource circles
  const centerX = Math.round(x + 8);
  const centerY = Math.round(y);

  // Set text properties
  ctx.font = "bold 12px 'Roboto', sans-serif";
  ctx.fillStyle = '#333';
  ctx.textBaseline = 'middle';

  // Split into separate characters
  const plus = "+";
  const countText = String(count);

  // Measure dimensions
  const plusWidth = ctx.measureText(plus).width;
  const countWidth = ctx.measureText(countText).width;

  // Calculate positions for centering around the plus sign
  const plusX = centerX - (plusWidth / 2);
  const countX = plusX + plusWidth;

  // Draw each element
  ctx.fillText(plus, plusX, centerY);
  ctx.fillText(countText, countX, centerY + 1);
}