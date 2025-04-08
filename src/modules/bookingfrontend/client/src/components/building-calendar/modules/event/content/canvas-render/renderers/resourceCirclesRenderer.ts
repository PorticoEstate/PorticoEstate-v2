import { CanvasDrawContext } from "../types";
import { drawRemainingCount } from "../utils";

/**
 * Renders horizontal resource circles with count indicator
 */
export function renderResourceCircles(
  context: CanvasDrawContext, 
  resources: any[], 
  yPos: number,
  timeText: string,
  title: string
): void {
  const { ctx, dimensions, colours, layoutType } = context;
  const circleRadius = 7;
  
  // Calculate vertical position
  let circleY;
  if (layoutType === 'minimal' || layoutType === 'short') {
    circleY = Math.round(dimensions.height / 2);
  } else {
    circleY = Math.round(yPos + circleRadius);
  }

  // Setup for circles
  const circleDiameter = circleRadius * 2;
  const circleSpacing = 4;

  // Pre-calculate width needed for "+X" text
  ctx.font = "600 12px 'Roboto', sans-serif";
  const countTextWidth = ctx.measureText("+99").width + 8;
  const leftMargin = 5;
  const rightMargin = 5;

  // Calculate usable width
  const totalWidth = dimensions.width - leftMargin - rightMargin;

  // Account for time text width if in short layout
  let effectiveLeftMargin = leftMargin;
  if (layoutType === 'minimal' || layoutType === 'short') {
    // Handle short layout with time and title on same line
    const displayTime = (layoutType === 'minimal' || dimensions.width < 100)
      ? timeText.split(' - ')[0]
      : timeText;
    
    const timeWidth = ctx.measureText(displayTime).width + 10;
    const titleWidth = layoutType === 'short' ? ctx.measureText(title).width + 10 : 0;
    effectiveLeftMargin = Math.max(effectiveLeftMargin, timeWidth + titleWidth + 10);
  }

  // Calculate available width for circles
  const availableWidth = dimensions.width - effectiveLeftMargin - rightMargin;

  // Calculate dynamic spacing based on available width
  let dynamicSpacing = circleSpacing;
  if (availableWidth < 100) {
    dynamicSpacing = 2;
  } else if (availableWidth < 150) {
    dynamicSpacing = 3;
  }

  // Calculate how many circles would fit
  const maxCirclesInFullWidth = Math.floor((availableWidth + dynamicSpacing) / (circleDiameter + dynamicSpacing));
  const countTextSpace = countTextWidth + 4;
  const adjustedWidth = availableWidth - countTextSpace;
  const maxCirclesWithCounter = Math.floor((adjustedWidth + dynamicSpacing) / (circleDiameter + dynamicSpacing));

  // Determine how many circles to show and whether to display a "+X" count
  let circlesToShow = resources.length;
  let showCount = false;
  
  if (resources.length <= maxCirclesInFullWidth) {
    circlesToShow = resources.length;
    showCount = false;
  } else {
    circlesToShow = Math.max(1, maxCirclesWithCounter);
    showCount = true;

    // If we're only showing 1-2 circles but there's clearly room for more,
    // try to show at least 3 if possible
    if (circlesToShow <= 2 && availableWidth > (circleDiameter * 3 + dynamicSpacing * 2 + countTextSpace)) {
      circlesToShow = 3;
    }
  }

  // Only proceed if we have circles to show
  if (circlesToShow > 0 && availableWidth > circleDiameter) {
    // Select resources to display
    const displayResources = resources.slice(0, circlesToShow);

    // Start from left edge (after effective margin)
    let currentX = effectiveLeftMargin;

    // Draw each visible circle
    for (let i = 0; i < displayResources.length; i++) {
      const resource = displayResources[i];

      // Draw circle with exact integer positioning
      const circleX = Math.round(currentX);

      ctx.beginPath();
      ctx.arc(circleX + circleRadius, circleY, circleRadius, 0, Math.PI * 2);
      ctx.fillStyle = colours ? colours[resource.id % colours.length] : 'gray';
      ctx.fill();

      // Move right for next circle with optimized spacing
      currentX += circleDiameter + dynamicSpacing;
    }

    // Draw count indicator if needed
    if (showCount) {
      const remainingCount = resources.length - circlesToShow;
      if (remainingCount > 0) {
        const indicatorX = Math.round(currentX);
        drawRemainingCount(ctx, remainingCount, indicatorX, circleY);
      }
    }
  }
}