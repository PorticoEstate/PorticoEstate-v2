import { CanvasComponentRenderer, CanvasDrawContext, ComponentRenderResult } from "../types";
import { drawTruncatedText, drawDebugOutline, DEBUG_CANVAS_DIMENSIONS, DEBUG_CANVAS_VISUAL } from "../utils";

/**
 * Resource list component renderer implementation
 */
export const resourceListRenderer: CanvasComponentRenderer = {
  name: 'resourceList',
  render: (
    context: CanvasDrawContext,
    props: {
      resources: any[],
      maxItems?: number
    },
    x: number,
    y: number,
    availableWidth: number,
    availableHeight: number
  ): ComponentRenderResult => {
    const { resources, maxItems } = props;

    // Default result if no resources to render
    const emptyResult: ComponentRenderResult = {
      usedWidth: 0,
      usedHeight: 0,
      xAdvance: 0,
      yAdvance: 0
    };

    // Skip if no resources
    if (!resources || resources.length === 0) {
      return emptyResult;
    }

    // Call the internal implementation
    const result = renderResourceListInternal(
      context,
      resources,
      y,
      x,
      availableWidth,
      maxItems,
      availableHeight
    );

    return {
      usedWidth: availableWidth, // Resource list uses full width
      usedHeight: result.height, // The actual height used
      xAdvance: 0, // Resource list never advances X
      yAdvance: result.height // Advance Y by the height of the component
    };
  }
};

/**
 * Internal implementation for rendering resource list
 */
function renderResourceListInternal(
  context: CanvasDrawContext,
  resources: any[],
  yPos: number,
  xOffset: number = 0,
  availableWidth: number = 0,
  maxItems?: number,
  availableHeight: number = 0
): { height: number } {
  const { ctx, dimensions, colours, layoutType, layoutConfig, layoutComponents, tInstance } = context;
  
  // Use provided width if specified, otherwise use full dimensions width
  const renderWidth = availableWidth > 0 ? availableWidth : dimensions.width;
  const startX = xOffset;
  
  // Calculate content height based on available space and constraints
  const rowHeight = 24; // Each resource row needs 24px

  // Only show resource list if we have space for it
  // Use the actual available height parameter instead of calculating from dimensions
  const availableSpaceHeight = availableHeight > 0 ? availableHeight : dimensions.height - yPos;
  if (availableSpaceHeight > 20) {
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Rendering resource list component`, {
        resourceCount: resources.length,
        yPos,
        xOffset: startX,
        width: renderWidth,
        usingProvidedWidth: availableWidth > 0,
        rowHeight,
        availableHeight: availableSpaceHeight,
        usingProvidedHeight: availableHeight > 0
      });
    }
    // Each resource needs 24px vertical space
    // Use availableSpaceHeight for calculation to respect the passed parameter
    const maxPossibleResources = Math.max(0, Math.floor(availableSpaceHeight / 24));

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
      // Calculate y position with vertical centering within row
      const rowY = yPos + (idx * rowHeight);
      const circleY = Math.round(rowY + (rowHeight / 2)); // Center circle vertically in row

      // Draw circle
      const circleX = startX + 5;

      ctx.beginPath();
      ctx.arc(circleX, circleY, 5, 0, Math.PI * 2);
      ctx.fillStyle = colours ? colours[resource.id % colours.length] : 'gray';
      ctx.fill();

      // Draw name centered vertically in the row
      ctx.font = "12px 'Roboto', sans-serif";
      ctx.fillStyle = '#000';
      drawTruncatedText(ctx, resource.name, startX + 15, circleY, renderWidth - 15);
    });

    // Show remaining resources count
    const remainingResources = resources.length - resourcesPerRow;
    // Only show the count if we have resources and there's space for at least one resource
    if (remainingResources > 0 && resourcesPerRow > 0) {
      const moreRowY = yPos + (resourcesPerRow * rowHeight);
      const moreY = Math.round(moreRowY + (rowHeight / 2)); // Center text vertically
      ctx.font = "12px 'Roboto', sans-serif";
      
      // Use translation if available, fallback to 'more' if not
      const moreText = tInstance ? tInstance('bookingfrontend.more') : 'more';
      ctx.fillText(`+${remainingResources} ${moreText}`, startX + 15, moreY);
    }

    // Draw debug outline around the entire resource list area
    // Calculate actual rendered height (including "more" indicator if shown)
    const hasMoreIndicator = remainingResources > 0 && resourcesPerRow > 0;

    // Height of rendered resources + "more" indicator if applicable
    const renderedHeight = (resourcesPerRow * rowHeight) + (hasMoreIndicator ? rowHeight : 0);

    // Ensure it doesn't exceed the available height
    const resourceListHeight = Math.min(
      availableSpaceHeight,
      renderedHeight
    );

    // If we're using custom max items limit from component props
    if (maxItems && maxItems > 0 && maxItems < resourcesPerRow) {
      if (DEBUG_CANVAS_DIMENSIONS) {
        console.log(`Using custom maxItems limit: ${maxItems} (was ${resourcesPerRow})`);
      }
      resourcesPerRow = maxItems;
    }

    if (DEBUG_CANVAS_VISUAL) {
      drawDebugOutline(
        ctx,
        'ResourceList',
        startX,
        yPos,
        renderWidth,
        resourceListHeight
      );
    }

    // Return the height that this component occupied
    return {
      height: resourceListHeight
    };
  } else {
    // No resources or not enough height, return minimal height
    return {
      height: 0
    };
  }
}

/**
 * Legacy function for backward compatibility
 */
export function renderResourceList(
  context: CanvasDrawContext,
  resources: any[],
  yPos: number,
  xOffset: number = 0,
  availableWidth: number = 0
): void {
  // Legacy function doesn't use availableHeight
  // Calculate it from dimensions instead for backward compatibility
  const availableHeight = context.dimensions.height - yPos;
  renderResourceListInternal(context, resources, yPos, xOffset, availableWidth, undefined, availableHeight);
}