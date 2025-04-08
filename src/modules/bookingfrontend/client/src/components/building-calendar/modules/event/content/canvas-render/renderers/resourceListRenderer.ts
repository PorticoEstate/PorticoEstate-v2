import { CanvasDrawContext } from "../types";
import { drawTruncatedText } from "../utils";

/**
 * Renders a vertical list of resources with color circles
 */
export function renderResourceList(
  context: CanvasDrawContext, 
  resources: any[], 
  yPos: number
): void {
  const { ctx, dimensions, colours } = context;
  const yPosWithSpacing = yPos + 5; // Add some spacing
  
  // Only show resource list if we have space for it
  if (dimensions.height - yPosWithSpacing > 20) {
    // Resources per row based on available height
    const resourcesPerRow = Math.floor((dimensions.height - yPosWithSpacing) / 24);
    const resourcesToShow = resources.slice(0, resourcesPerRow);

    resourcesToShow.forEach((resource, idx) => {
      const resourceY = yPosWithSpacing + idx * 24;

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
    if (remainingResources > 0) {
      const moreY = yPosWithSpacing + resourcesPerRow * 24;
      ctx.font = "12px 'Roboto', sans-serif";
      ctx.fillText(`+${remainingResources} more`, 15, moreY);
    }
  }
}