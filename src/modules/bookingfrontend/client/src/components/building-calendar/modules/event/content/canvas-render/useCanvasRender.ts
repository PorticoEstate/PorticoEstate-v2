import { useEffect, RefObject } from 'react';
import { Dimensions } from './types';
import { formatEventTime } from "@/service/util";
import { FCallEvent, FCEventContentArg } from "@/components/building-calendar/building-calendar.types";
import { determineLayoutType, setupCanvas } from './utils';
import { 
  renderTime, 
  renderTitle, 
  renderOrganizer, 
  renderResources 
} from './renderers';

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
    
    // Scale for high DPI displays
    ctx.scale(devicePixelRatio, devicePixelRatio);
    
    // Enable font smoothing
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    
    // Clear canvas
    ctx.clearRect(0, 0, actualDimensions.width, actualDimensions.height);
    
    // Determine layout based on container height
    const layoutType = determineLayoutType(actualDimensions.height);
    
    // Basic styling
    const paddingTop = 2;
    
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
      devicePixelRatio
    };
    
    // Draw time
    let yPos = paddingTop + 12;
    const { yPos: newYPos, displayTime } = renderTime(drawContext, timeText, yPos);
    yPos = newYPos;
    
    // Draw title
    yPos = renderTitle(drawContext, eventInfo.event.title, displayTime, yPos);
    
    // Draw organizer
    yPos = renderOrganizer(drawContext, eventData, yPos);
    
    // Draw resources
    const resources = eventInfo.event.extendedProps.source.resources;
    renderResources(drawContext, resources, yPos, timeText, eventInfo.event.title);
    
  }, [canvasRef, containerRef, eventInfo, colours, fontLoaded, dimensions.width, dimensions.height]);
}