import { CanvasDrawContext } from "../types";
import { drawRemainingCount, drawDebugOutline } from "../utils";
import { DEBUG_CANVAS_DIMENSIONS, DEBUG_CANVAS_VISUAL } from '../useCanvasDimensions';

/**
 * Renders horizontal resource circles with count indicator
 */
export function renderResourceCircles(
  context: CanvasDrawContext, 
  resources: any[], 
  yPos: number,
  timeText: string,
  title: string,
  xOffset: number = 0,
  availableWidth: number = 0
): void {
  const { ctx, dimensions, colours, layoutType, layoutConfig, layoutComponents } = context;
  const circleRadius = 7;
  
  // Check if time and resource circles should be rendered side-by-side
  const isSideBySide = layoutConfig.sideBySideComponents?.time_resourceCircles === true;
  
  // Each component manages its own padding
  const paddingTop = layoutType === 'minimal' || layoutType === 'short' 
    ? 0 // For minimal/short layouts, we don't need top padding as circles are centered vertically
    : 8; // Padding from previous component for other layouts
  
  // Calculate vertical position based on side-by-side configuration
  let circleY;
  if (isSideBySide) {
    // When side-by-side with time, center vertically in the container
    circleY = Math.round(dimensions.height / 2);
  } else {
    // When stacked vertically, position after the previous component with padding
    circleY = Math.round(yPos + paddingTop + circleRadius);
  }
  
  // Use provided width if specified, otherwise use full dimensions width
  const renderWidth = availableWidth > 0 ? availableWidth : dimensions.width;

  if (DEBUG_CANVAS_DIMENSIONS) {
    console.log(`Rendering resource circles component`, {
      layout: layoutType,
      resourceCount: resources.length,
      sideBySide: isSideBySide,
      yPos,
      xOffset,
      availableWidth: renderWidth,
      usingProvidedDimensions: availableWidth > 0,
      paddingTop,
      circleY,
      effectivePosition: isSideBySide
        ? 'centered (side-by-side with time)' 
        : 'stacked (after previous component)',
      fullDimensions: dimensions
    });
  }

  // Setup for circles
  const circleDiameter = circleRadius * 2;
  const circleSpacing = 4;

  // Pre-calculate width needed for "+X" text
  ctx.font = "600 12px 'Roboto', sans-serif";
  const countTextWidth = ctx.measureText("+99").width + 8;
  // When using provided dimensions, we don't need additional margins for time component
  // as that's already been accounted for in the parent component
  const leftMargin = 0;
  const rightMargin = 5;
  
  if (DEBUG_CANVAS_DIMENSIONS) {
    console.log(`Circle margin details:`, {
      xOffset,
      leftMargin,
      rightMargin,
      effectiveStartPosition: xOffset + leftMargin,
      totalWidth: renderWidth
    });
  }

  // Calculate usable width - now using the provided renderWidth
  const totalWidth = renderWidth - leftMargin - rightMargin;

  // Start position is the provided xOffset plus the leftMargin
  let effectiveLeftMargin = xOffset + leftMargin;
    
  if (DEBUG_CANVAS_DIMENSIONS) {
    console.log(`Resource circles positioning`, {
      xOffset, 
      effectiveLeftMargin,
      availableWidth: renderWidth - rightMargin
    });
  }

  // Calculate usable width for circles
  const usableWidth = renderWidth - leftMargin - rightMargin;

  // Calculate dynamic spacing based on available width
  let dynamicSpacing = circleSpacing;
  if (usableWidth < 100) {
    dynamicSpacing = 2;
  } else if (usableWidth < 150) {
    dynamicSpacing = 3;
  }

  // Calculate how many circles would fit
  // We need to account for the width of each circle plus spacing
  const circleWithSpacing = circleDiameter + dynamicSpacing;
  
  // Maximum circles if we use the full width (no counter)
  const maxCirclesInFullWidth = Math.floor((usableWidth + dynamicSpacing) / circleWithSpacing);
  
  // Space needed for the "+X" counter text
  const countTextSpace = countTextWidth + 8; // Add more buffer for better appearance
  
  // Width available if we also need to show the counter
  const adjustedWidth = Math.max(0, usableWidth - countTextSpace);
  
  // Maximum circles if we need to show the counter
  const maxCirclesWithCounter = Math.max(1, Math.floor((adjustedWidth + dynamicSpacing) / circleWithSpacing));

  // Determine how many circles to show and whether to display a "+X" count
  let circlesToShow = resources.length;
  let showCount = false;
  
  if (DEBUG_CANVAS_DIMENSIONS) {
    console.log(`Resource circles fit calculation`, {
      usableWidth,
      circleWidth: circleDiameter,
      spacing: dynamicSpacing,
      totalResources: resources.length,
      maxCirclesInFullWidth,
      maxCirclesWithCounter
    });
  }
  
  if (resources.length <= maxCirclesInFullWidth) {
    // All resources fit without needing a counter
    circlesToShow = resources.length;
    showCount = false;
  } else {
    // Not all resources fit, need to show a counter
    circlesToShow = maxCirclesWithCounter;
    showCount = true;
    
    // Make sure we show at least 1 circle
    circlesToShow = Math.max(1, circlesToShow);
    
    // If we're in a constrained space but still have resources, ensure we show at least 1 circle + counter
    if (circlesToShow === 0 && resources.length > 0) {
      circlesToShow = 1;
    }
    
    // If the counter would show just 1 additional resource, show that resource instead
    if (resources.length - circlesToShow === 1) {
      circlesToShow = resources.length;
      showCount = false;
    }
    
    // Always ensure we have at least 2 resources remaining for a counter to make sense
    // If we only have 1 remaining, just show it instead of a counter
    if (resources.length - circlesToShow < 2) {
      // If we can fit all resources without the counter, do so
      if (resources.length <= maxCirclesInFullWidth) {
        circlesToShow = resources.length;
        showCount = false;
      }
    }
  }

  // Only proceed if we have circles to show
  if (circlesToShow > 0 && usableWidth > circleDiameter) {
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
      // Only show counter if we have at least 2 remaining resources
      if (remainingCount >= 2) {
        const indicatorX = Math.round(currentX);
        drawRemainingCount(ctx, remainingCount, indicatorX, circleY);
        
        if (DEBUG_CANVAS_DIMENSIONS) {
          console.log(`Drawing +${remainingCount} indicator at x=${indicatorX}`, {
            circlesToShow,
            totalResources: resources.length,
            remainingCount 
          });
        }
      } else if (remainingCount === 1 && circlesToShow < resources.length) {
        // If there's only 1 resource remaining and we have space, show it instead of a counter
        const resource = resources[circlesToShow];
        const circleX = Math.round(currentX);
        
        ctx.beginPath();
        ctx.arc(circleX + circleRadius, circleY, circleRadius, 0, Math.PI * 2);
        ctx.fillStyle = colours ? colours[resource.id % colours.length] : 'gray';
        ctx.fill();
        
        if (DEBUG_CANVAS_DIMENSIONS) {
          console.log(`Showing last resource instead of +1 counter`, {
            resourceId: resource.id
          });
        }
      }
    }
    
    // Draw debug outline around the entire component (including padding)
    // For side-by-side mode, we want to highlight only the portion with circles
    // For stacked mode, we want to highlight from yPos to the bottom of the circles
    const circlesAreaWidth = currentX - effectiveLeftMargin;
    const componentHeight = isSideBySide
      ? dimensions.height  // In side-by-side mode, take the full height
      : paddingTop + circleDiameter + 4; // In stacked mode, padding + circles + buffer
      
    const componentY = isSideBySide
      ? 0  // Start from the top in side-by-side mode
      : yPos; // Start from yPos in stacked mode
      
    drawDebugOutline(
      ctx,
      'ResourceCircles',
      effectiveLeftMargin,
      componentY,
      Math.max(circlesAreaWidth, renderWidth - (xOffset + leftMargin)),
      componentHeight
    );
  }
}