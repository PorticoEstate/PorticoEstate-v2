import { CanvasComponentRenderer, CanvasDrawContext, ComponentRenderResult } from "../types";
import { drawDebugOutline, DEBUG_CANVAS_DIMENSIONS } from "../utils";

/**
 * Custom badge component renderer example
 * Shows how to create a completely new component type
 */
export const customBadgeRenderer: CanvasComponentRenderer = {
  name: 'customBadge',
  render: (
    context: CanvasDrawContext,
    props: {
      text?: string;
      color?: string;
      backgroundColor?: string;
      borderRadius?: number;
      padding?: number;
    },
    x: number,
    y: number,
    availableWidth: number,
    availableHeight: number
  ): ComponentRenderResult => {
    const { ctx } = context;

    // Extract and set defaults for props
    const {
      text = 'Badge',
      color = '#ffffff',
      backgroundColor = '#4a86e8',
      borderRadius = 4,
      padding = 5
    } = props;

    // Calculate text dimensions
    ctx.font = `bold 11px 'Roboto', sans-serif`;
    const textMetrics = ctx.measureText(text);
    const textHeight = 12; // Approximate text height

    // Badge dimensions
    const badgeHeight = textHeight + (padding * 2);
    const badgeWidth = textMetrics.width + (padding * 4);
    const badgeX = Math.round(x + (availableWidth / 2) - (badgeWidth / 2)); // Center horizontally

    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Rendering custom badge component`, {
        text,
        x, y,
        badgeX,
        badgeWidth,
        badgeHeight,
        color,
        backgroundColor
      });
    }

    // Draw badge background
    ctx.save();
    ctx.fillStyle = backgroundColor;
    ctx.beginPath();
    ctx.roundRect(badgeX, y, badgeWidth, badgeHeight, borderRadius);
    ctx.fill();

    // Draw badge text
    ctx.fillStyle = color;
    ctx.textBaseline = 'middle';
    ctx.fillText(
      text,
      badgeX + (badgeWidth / 2) - (textMetrics.width / 2),
      y + (badgeHeight / 2)
    );
    ctx.restore();

    // Draw debug outline
    drawDebugOutline(
      ctx,
      'CustomBadge',
      x,
      y,
      availableWidth,
      badgeHeight
    );

    // Return component dimensions and how much to advance
    return {
      usedWidth: availableWidth, // Badge uses full width for centering
      usedHeight: badgeHeight, // The actual height used
      xAdvance: 0, // Badge doesn't advance X
      yAdvance: badgeHeight + 5 // Add extra space after badge
    };
  }
};

// Note: This component will be registered in the index.ts file
// to avoid circular dependency issues