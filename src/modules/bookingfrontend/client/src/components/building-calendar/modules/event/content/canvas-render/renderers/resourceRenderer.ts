import { CanvasDrawContext } from "../types";
import { renderResourceCircles } from "./resourceCirclesRenderer";
import { renderResourceList } from "./resourceListRenderer";
import { DEBUG_CANVAS_DIMENSIONS } from '../useCanvasDimensions';

/**
 * Renders resources either as circles or as a list based on layout type
 */
export function renderResources(
  context: CanvasDrawContext, 
  resources: any[], 
  yPos: number, 
  timeText: string, 
  title: string,
  xOffset: number = 0,
  availableWidth: number = 0
): void {
  const { layoutType, layoutComponents, dimensions } = context;
  
  // Skip if no resources or if resource components are not enabled
  if (!resources || resources.length === 0) {
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Resources component skipped - no resources available`);
    }
    return;
  }
  
  // Check if either resource component type is enabled
  const showResourceList = layoutComponents.includes('resourceList');
  const showResourceCircles = layoutComponents.includes('resourceCircles');
  
  if (!showResourceList && !showResourceCircles) {
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Resources component skipped - not in enabled components list`, {
        layout: layoutType,
        enabledComponents: layoutComponents
      });
    }
    return;
  }
  
  // Use the provided width if specified, otherwise use the full width from dimensions
  const actualWidth = availableWidth > 0 ? availableWidth : dimensions.width;
  
  if (DEBUG_CANVAS_DIMENSIONS) {
    console.log(`Rendering resources component`, {
      mode: showResourceList ? 'list' : 'circles',
      resourceCount: resources.length,
      yPos,
      xOffset,
      availableWidth: actualWidth,
      usingProvidedWidth: availableWidth > 0
    });
  }

  // Render the appropriate resource view based on enabled components
  if (showResourceList) {
    renderResourceList(context, resources, yPos);
  } else if (showResourceCircles) {
    renderResourceCircles(context, resources, yPos, timeText, title, xOffset, actualWidth);
  }
}