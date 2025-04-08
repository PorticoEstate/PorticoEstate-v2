import { useEffect, RefObject } from 'react';
import { Dimensions } from './types';
import { formatEventTime } from "@/service/util";
import { FCallEvent, FCEventContentArg } from "@/components/building-calendar/building-calendar.types";
import { determineLayoutType, setupCanvas, drawDebugOutline } from './utils';
import { 
  renderTime, 
  renderTitle, 
  renderOrganizer, 
  renderResources 
} from './renderers';
import { DEBUG_CANVAS_DIMENSIONS, DEBUG_CANVAS_VISUAL } from './useCanvasDimensions';

interface UseCanvasRenderParams {
  canvasRef: RefObject<HTMLCanvasElement>;
  containerRef: RefObject<HTMLDivElement>;
  eventInfo: FCEventContentArg<FCallEvent>;
  dimensions: Dimensions;
  fontLoaded: boolean;
  colours: string[];
}

/**
 * Hook to handle all canvas rendering logic
 */
export function useCanvasRender({
  canvasRef,
  containerRef,
  eventInfo,
  dimensions,
  fontLoaded,
  colours
}: UseCanvasRenderParams): void {
  // Draw the event content on canvas whenever dimensions change or font loads
  useEffect(() => {
    if (!canvasRef.current || !dimensions.width || !dimensions.height || !fontLoaded) return;

    const canvas = canvasRef.current;
    const ctx = canvas.getContext('2d', { alpha: true });
    if (!ctx) return;

    // Get container dimensions
    const containerRect = containerRef.current?.getBoundingClientRect();
    
    // Get actual container dimensions
    const containerWidth = Math.round(containerRect?.width || dimensions.width);
    const containerHeight = Math.round(containerRect?.height || dimensions.height);
    
    // Get device pixel ratio for high DPI displays
    const devicePixelRatio = window.devicePixelRatio || 1;
    
    // Setup canvas with proper dimensions
    const actualDimensions = setupCanvas(canvas, containerWidth, containerHeight, devicePixelRatio);
    
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Canvas render triggered:`, {
        eventTitle: eventInfo.event.title,
        eventId: eventInfo.event.id,
        containerSize: { width: containerWidth, height: containerHeight },
        actualDimensions,
        devicePixelRatio
      });
    }
    
    // Scale for high DPI displays
    ctx.scale(devicePixelRatio, devicePixelRatio);
    
    // Enable font smoothing
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    
    // Clear canvas
    ctx.clearRect(0, 0, actualDimensions.width, actualDimensions.height);
    
    // Determine layout based on container height
    const { layoutType, components, layoutConfig } = determineLayoutType(actualDimensions.height);
    
    // Get event data
    const eventData = eventInfo.event.extendedProps.source;
    const timeText = formatEventTime(eventInfo.event);
    
    // Set text styling
    ctx.font = `12px 'Roboto', sans-serif`;
    ctx.fillStyle = '#000';
    ctx.textBaseline = 'middle';
    
    // Create drawing context
    const drawContext = {
      ctx,
      dimensions: actualDimensions,
      colours,
      layoutType,
      layoutComponents: components,
      layoutConfig, // Pass the full layout configuration
      devicePixelRatio
    };
    
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Canvas rendering components for event "${eventInfo.event.title}"`, {
        eventId: eventInfo.event.id,
        enabledComponents: components,
        layout: layoutType,
        actualDimensions,
        hasResources: !!eventInfo.event.extendedProps.source.resources?.length
      });
    }
    
    // Start from the top of the canvas
    let yPos = 0;
    
    // Draw time (includes its own internal padding)
    const { yPos: newYPos, displayTime } = renderTime(drawContext, timeText, yPos);
    yPos = newYPos;
    
    // Draw title (includes its own internal padding)
    yPos = renderTitle(drawContext, eventInfo.event.title, displayTime, yPos);
    
    // Draw organizer (includes its own internal padding)
    yPos = renderOrganizer(drawContext, eventData, yPos);
    
    // Draw resources
    const resources = eventInfo.event.extendedProps.source.resources;
    
    // Calculate available space for resources based on layout configuration
    const isSideBySide = layoutConfig.sideBySideComponents?.time_resourceCircles === true;
    
    // If rendering side-by-side with time, adjust the available space
    let resourcesX = 0;
    let resourcesWidth = dimensions.width;
    
    if (isSideBySide && components.includes('time')) {
      // When side-by-side, resource circles start after the time component
      resourcesX = 35; // Time component takes 35px
      resourcesWidth = dimensions.width - resourcesX;
      
      if (DEBUG_CANVAS_DIMENSIONS) {
        console.log(`Adjusting resources space for side-by-side rendering`, {
          totalWidth: dimensions.width,
          timeWidth: 35,
          resourcesX,
          resourcesWidth
        });
      }
    }
    
    renderResources(drawContext, resources, yPos, timeText, eventInfo.event.title, resourcesX, resourcesWidth);
    
    // Draw overall canvas debug outline
    if (DEBUG_CANVAS_VISUAL) {
      drawDebugOutline(
        ctx, 
        `${layoutType} (${actualDimensions.width}x${actualDimensions.height})`,
        0, 
        0, 
        actualDimensions.width, 
        actualDimensions.height
      );
    }
    
  }, [canvasRef, containerRef, eventInfo, colours, fontLoaded, dimensions.width, dimensions.height]);
}