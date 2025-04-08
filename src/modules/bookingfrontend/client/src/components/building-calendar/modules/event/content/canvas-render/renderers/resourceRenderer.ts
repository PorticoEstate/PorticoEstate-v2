import { CanvasDrawContext } from "../types";
import { renderResourceCircles } from "./resourceCirclesRenderer";
import { renderResourceList } from "./resourceListRenderer";

/**
 * Renders resources either as circles or as a list based on layout type
 */
export function renderResources(
  context: CanvasDrawContext, 
  resources: any[], 
  yPos: number, 
  timeText: string, 
  title: string
): void {
  const { layoutType, dimensions } = context;
  
  // Decide whether to show resource list or circles
  const showResourceList = layoutType === 'large' && dimensions.height > 110;

  if (showResourceList) {
    renderResourceList(context, resources, yPos);
  } else {
    renderResourceCircles(context, resources, yPos, timeText, title);
  }
}