import { Dimensions, LayoutType, LayoutRule, ComponentType } from "./types";
// Debug flags for canvas rendering
export const DEBUG_CANVAS_DIMENSIONS = false;
export const DEBUG_CANVAS_VISUAL = false; // Enable visual debug outlines and labels
/**
 * Configurable layout rules
 * - Order matters! Rules are evaluated in order, first match wins
 * - maxHeight is the maximum container height for the rule to apply
 * - components lists which elements should be rendered in this layout
 */
export const LAYOUT_RULES: LayoutRule[] = [
  // Test enabled layout to demonstrate custom components - uncomment for testing
  // {
  //   name: 'test-with-badge',
  //   maxHeight: 200, // Set higher than other layouts to see it in action
  //   components: ['time', 'title', 'customBadge', 'organizer', 'resourceCircles'],
  //   description: 'Test layout with badge component',
  //   horizontalRendering: false, // Stack components vertically
  //   componentProps: {
  //     customBadge: {
  //       text: 'TEST',
  //       backgroundColor: '#34a853',
  //       color: '#ffffff'
  //     },
  //     time: {
  //       maxWidth: 35 // Limit time width
  //     }
  //   }
  // },
  {
    name: 'minimal',
    maxHeight: 30,
    components: ['time', 'resourceCircles'],
    description: 'Very compact view with time and resource circles side by side',
    horizontalRendering: true, // Render components horizontally
    componentProps: {
      time: {
        maxWidth: 35 // Limit time width in horizontal mode
      }
    }
  },
  {
    name: 'short',
    maxHeight: 50,
    components: ['time', 'resourceCircles'],
    description: 'Compact view with time and resource indicators stacked',
    horizontalRendering: false // Stack components vertically
  },
  {
    name: 'medium',
    maxHeight: 80,
    components: ['time', 'title', 'resourceCircles'],
    description: 'Medium view with time, title and resource indicators',
    horizontalRendering: false // Stack components vertically
  },
  {
    name: 'standard',
    maxHeight: 106,
    components: ['time', 'title', 'organizer', 'resourceCircles'],
    description: 'Standard view with time, title, organizer and resource indicators',
    horizontalRendering: false // Stack components vertically
  },
  {
    name: 'large',
    maxHeight: Infinity,
    components: ['time', 'title', 'organizer', 'resourceList'],
    description: 'Large view with all components and full resource list',
    horizontalRendering: false, // Stack components vertically
    // Example of custom component props
    componentProps: {
      resourceList: {
        maxItems: 4
      },
      time: {
        // maxWidth: 35,
        maxHeight: 24
      },
      title: {
        maxHeight: 24
      },
      organizer: {
        maxHeight: 24
      }
    }
  },
  // Example of a custom layout with a different component order and custom badge
  // {
  //   name: 'custom',
  //   maxHeight: -1, // Disabled by default (use negative value to disable)
  //   components: ['title', 'time', 'customBadge', 'organizer', 'resourceCircles'],
  //   description: 'Custom layout with title at the top and custom badge (for demonstration)',
  //   horizontalRendering: true, // Render components horizontally
  //   componentProps: {
  //     customBadge: {
  //       text: 'NEW',
  //       backgroundColor: '#ea4335',
  //       color: '#ffffff'
  //     },
  //     time: {
  //       maxWidth: 35 // Limit time width in horizontal mode
  //     }
  //   }
  // }
];

/**
 * Determines the layout rule based on container height
 * Returns the rule name, components to display, and the full layout configuration
 */
export function determineLayoutType(height: number): { layoutType: LayoutType, components: ComponentType[], layoutConfig: LayoutRule } {
  // Support for enabling custom layouts by a query parameter
  const urlParams = new URLSearchParams(window.location.search);
  const testLayoutParam = urlParams.get('testLayout');

  // Test param to enable named custom layouts, or if a height is provided use that
  let forceTestLayout: LayoutRule | null | undefined = null;
  if (testLayoutParam) {
    // First look for a named layout
    forceTestLayout = LAYOUT_RULES.find(rule => rule.name === testLayoutParam);

    // If not found and the param is a number, find the first rule above that height
    if (!forceTestLayout && !isNaN(Number(testLayoutParam))) {
      const testHeight = Number(testLayoutParam);
      // Enable the first layout rule that has max height above the requested height
      forceTestLayout = LAYOUT_RULES.find(rule =>
        rule.maxHeight >= testHeight && rule.maxHeight !== Infinity
      );
    }

    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Test layout parameter detected: "${testLayoutParam}"`, {
        foundMatchingLayout: !!forceTestLayout,
        layoutName: forceTestLayout?.name
      });
    }
  }

  // Use the test layout if specified, otherwise find the appropriate rule
  let selectedRule: LayoutRule;
  if (forceTestLayout) {
    selectedRule = forceTestLayout;
  } else {
    // Find the first rule that matches the height and is not disabled (maxHeight > 0)
    const rule = LAYOUT_RULES.find(rule => height <= rule.maxHeight && rule.maxHeight > 0);
    // Default to the last rule if none match (shouldn't happen with Infinity)
    selectedRule = rule || LAYOUT_RULES.find(r => r.maxHeight === Infinity) || LAYOUT_RULES[0];
  }

  if (DEBUG_CANVAS_DIMENSIONS) {
    console.log(`Layout determined: ${selectedRule.name}`, {
      height,
      selectedComponents: selectedRule.components,
      description: selectedRule.description,
      customProps: selectedRule.componentProps,
      allRules: LAYOUT_RULES.filter(r => r.maxHeight > 0).map(r => ({
        name: r.name,
        maxHeight: r.maxHeight === Infinity ? '∞' : r.maxHeight,
        components: r.components.join(', '),
        description: r.description,
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
export interface TextRenderResult {
  width: number;    // The width actually used for the rendered text
  height: number;   // The height of the rendered text (approximately for canvas text)
  truncated: boolean; // Whether the text needed to be truncated
}

/**
 * Draws text with ellipsis if it doesn't fit in the available width
 * Returns the dimensions of the rendered text
 */
export function drawTruncatedText(
  ctx: CanvasRenderingContext2D,
  text: string,
  x: number,
  y: number,
  maxWidth: number
): TextRenderResult {
  if (!text) return { width: 0, height: 0, truncated: false };

  // If text fits, simply draw it
  const textWidth = ctx.measureText(text).width;
  if (textWidth <= maxWidth) {
    ctx.fillText(text, x, y);
    // Estimate the text height based on font size and characteristics
    // For a typical 12px font, height is approximately 14-16px
    const textHeight = Math.ceil(ctx.measureText('M').width * 1.2); // Approximate height calculation
    return {
      width: textWidth,
      height: textHeight,
      truncated: false
    };
  }

  // Handle ellipsis calculation
  const ellipsis = '…'; // Unicode ellipsis
  const ellipsisWidth = ctx.measureText(ellipsis).width;

  // If we don't even have space for ellipsis alone
  if (maxWidth < ellipsisWidth) {
    // Don't draw anything
    return {
      width: 0,
      height: 0,
      truncated: true
    };
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
  const finalText = truncatedText + ellipsis;
  ctx.fillText(finalText, x, y);

  // Estimate the text height based on font size
  const textHeight = Math.ceil(ctx.measureText('M').width * 1.2); // Approximate height calculation
  const finalWidth = ctx.measureText(finalText).width;

  return {
    width: finalWidth,
    height: textHeight,
    truncated: true
  };
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