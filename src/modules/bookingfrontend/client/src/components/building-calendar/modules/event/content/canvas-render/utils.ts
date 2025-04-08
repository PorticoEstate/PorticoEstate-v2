import { CanvasDrawContext, Dimensions, LayoutType, LayoutRule, ComponentType } from "./types";
import { FCallEvent, FCEventContentArg } from "@/components/building-calendar/building-calendar.types";
import { DEBUG_CANVAS_DIMENSIONS, DEBUG_CANVAS_VISUAL } from './useCanvasDimensions';

/**
 * Configurable layout rules
 * - Order matters! Rules are evaluated in order, first match wins
 * - maxHeight is the maximum container height for the rule to apply
 * - components lists which elements should be rendered in this layout
 */
export const LAYOUT_RULES: LayoutRule[] = [
  {
    name: 'minimal',
    maxHeight: 30,
    components: ['time', 'resourceCircles'],
    description: 'Very compact view with time and resource circles side by side',
    sideBySideComponents: {
      time_resourceCircles: true // Time and resource circles should render side-by-side
    }
  },
  {
    name: 'short',
    maxHeight: 50,
    components: ['time', 'resourceCircles'],
    description: 'Compact view with time and resource indicators side by side',
    sideBySideComponents: {
      time_resourceCircles: false // Components should stack vertically
    }
  },
  {
    name: 'medium',
    maxHeight: 75,
    components: ['time', 'title', 'resourceCircles'],
    description: 'Medium view with time, title and resource indicators',
    sideBySideComponents: {
      time_resourceCircles: false // Components should stack vertically
    }
  },
  {
    name: 'standard',
    maxHeight: 100,
    components: ['time', 'title', 'organizer', 'resourceCircles'],
    description: 'Standard view with time, title, organizer and resource indicators',
    sideBySideComponents: {
      time_resourceCircles: false // Components should stack vertically
    }
  },
  {
    name: 'large',
    maxHeight: Infinity,
    components: ['time', 'title', 'organizer', 'resourceList'],
    description: 'Large view with all components and full resource list',
    sideBySideComponents: {
      time_resourceCircles: false // Components should stack vertically
    }
  }
];

/**
 * Determines the layout rule based on container height
 * Returns the rule name, components to display, and the full layout configuration
 */
export function determineLayoutType(height: number): { layoutType: LayoutType, components: ComponentType[], layoutConfig: LayoutRule } {
  // Find the first rule that matches the height
  const rule = LAYOUT_RULES.find(rule => height <= rule.maxHeight);

  // Default to the last rule if none match (shouldn't happen with Infinity)
  const selectedRule = rule || LAYOUT_RULES[LAYOUT_RULES.length - 1];

  if (DEBUG_CANVAS_DIMENSIONS) {
    console.log(`Layout determined: ${selectedRule.name}`, {
      height,
      selectedComponents: selectedRule.components,
      description: selectedRule.description,
      sideBySideConfig: selectedRule.sideBySideComponents || {},
      allRules: LAYOUT_RULES.map(r => ({
        name: r.name,
        maxHeight: r.maxHeight === Infinity ? '∞' : r.maxHeight,
        components: r.components.join(', '),
        description: r.description,
        sideBySideComponents: r.sideBySideComponents || {}
      }))
    });
  }

  return {
    layoutType: selectedRule.name,
    components: selectedRule.components,
    layoutConfig: selectedRule
  };
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

/**
 * Draws a debug outline and label for a component
 */
export function drawDebugOutline(
  ctx: CanvasRenderingContext2D,
  componentName: string,
  x: number,
  y: number,
  width: number,
  height: number
): void {
  if (!DEBUG_CANVAS_VISUAL) return;

  // Save current context state
  ctx.save();

  // Draw outline
  ctx.strokeStyle = 'rgba(255, 0, 0, 0.8)';
  ctx.lineWidth = 1;
  ctx.strokeRect(x, y, width, height);

  // Draw component name
  ctx.font = '10px Arial';
  ctx.fillStyle = 'rgba(255, 0, 0, 0.5)';
  ctx.fillText(componentName, x + 3, y + 10);

  // Restore context state
  ctx.restore();
}