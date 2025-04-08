import { CanvasDrawContext } from "../types";
import { drawTruncatedText, drawDebugOutline } from "../utils";
import { DEBUG_CANVAS_DIMENSIONS, DEBUG_CANVAS_VISUAL } from '../useCanvasDimensions';

/**
 * Renders a vertical list of resources with color circles
 */
export function renderResourceList(
  context: CanvasDrawContext, 
  resources: any[], 
  yPos: number
): void {
  const { ctx, dimensions, colours, layoutType, layoutConfig, layoutComponents } = context;
  // Each component manages its own padding
  const paddingTop = 6; // Padding from previous component
  const yPosWithPadding = yPos + paddingTop;
  
  // Only show resource list if we have space for it
  if (dimensions.height - yPosWithPadding > 20) {
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Rendering resource list component`, {
        resourceCount: resources.length,
        yPos,
        yPosWithPadding,
        paddingTop,
        availableHeight: dimensions.height - yPosWithPadding
      });
    }
    // Calculate available space and clamp to prevent rendering outside container
    const availableHeight = dimensions.height - yPosWithPadding;
    // Each resource needs 24px vertical space
    const maxPossibleResources = Math.max(0, Math.floor(availableHeight / 24));
    
    // If we have more resources than we can show AND we have at least 2 spaces,
    // reserve the last space for the "more" indicator
    let resourcesPerRow = maxPossibleResources;
    if (resources.length > maxPossibleResources && maxPossibleResources >= 2) {
      // Reserve one row for the +more indicator
      resourcesPerRow = maxPossibleResources - 1;
    }
    
    // Ensure we don't try to render resources if there's no space
    const resourcesToShow = resourcesPerRow > 0 ? resources.slice(0, resourcesPerRow) : [];
    
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Resource list calculation`, {
        availableHeight,
        maxPossibleResources,
        resourcesPerRow,
        totalResources: resources.length,
        resourcesToShow: resourcesToShow.length,
        willShowMoreIndicator: resources.length > resourcesPerRow && resourcesPerRow > 0
      });
    }

    resourcesToShow.forEach((resource, idx) => {
      const resourceY = yPosWithPadding + idx * 24;

      // Draw circle
      const circleX = 5;
      const circleY = Math.round(resourceY);

      ctx.beginPath();
      ctx.arc(circleX, circleY, 5, 0, Math.PI * 2);
      ctx.fillStyle = colours ? colours[resource.id % colours.length] : 'gray';
      ctx.fill();

      // Draw name
      ctx.font = "12px 'Roboto', sans-serif";
      ctx.fillStyle = '#000';
      drawTruncatedText(ctx, resource.name, 15, circleY, dimensions.width - 15);
    });

    // Show remaining resources count
    const remainingResources = resources.length - resourcesPerRow;
    // Only show the count if we have resources and there's space for at least one resource
    if (remainingResources > 0 && resourcesPerRow > 0) {
      // Reduce spacing by 2px between last resource and "+more" text
      const moreY = yPosWithPadding + (resourcesPerRow * 24) - 2;
      ctx.font = "12px 'Roboto', sans-serif";
      ctx.fillText(`+${remainingResources} more`, 15, moreY);
    }
    
    // Draw debug outline around the entire resource list area
    // Calculate actual rendered height (including "more" indicator if shown)
    const hasMoreIndicator = remainingResources > 0 && resourcesPerRow > 0;
    
    // Height of rendered resources + "more" indicator if applicable
    // Use 22px for the "more" indicator height since we reduced its spacing by 2px
    const renderedHeight = (resourcesPerRow * 24) + (hasMoreIndicator ? 22 : 0);
    
    // Ensure it doesn't exceed the container height
    const resourceListHeight = Math.min(
      dimensions.height - yPosWithPadding,
      renderedHeight
    );
    
    drawDebugOutline(
      ctx,
      'ResourceList',
      0,
      yPos,
      dimensions.width,
      paddingTop + resourceListHeight
    );
  }
}