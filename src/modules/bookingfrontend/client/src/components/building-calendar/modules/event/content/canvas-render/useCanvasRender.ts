import { useEffect, RefObject } from 'react';
import { Dimensions, CanvasDrawContext } from './types';
import { formatEventTime } from "@/service/util";
import { FCallEvent, FCEventContentArg } from "@/components/building-calendar/building-calendar.types";
import { determineLayoutType, setupCanvas, drawDebugOutline, DEBUG_CANVAS_DIMENSIONS, DEBUG_CANVAS_VISUAL } from './utils';
import {
  componentRegistry, // New component registry
  registerAllComponents // Function to register all components
} from './renderers';

// Register all components
registerAllComponents();

interface UseCanvasRenderParams {
  canvasRef: RefObject<HTMLCanvasElement>;
  containerRef: RefObject<HTMLDivElement>;
  eventInfo: FCEventContentArg<FCallEvent>;
  dimensions: Dimensions;
  fontLoaded: boolean;
  colours: string[];
  tInstance?: (key: string, options?: any) => string; // Translation instance
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
  colours,
  tInstance
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
    const drawContext: CanvasDrawContext = {
      ctx,
      dimensions: actualDimensions,
      colours,
      layoutType,
      layoutComponents: components,
      layoutConfig, // Pass the full layout configuration
      devicePixelRatio,
      event: eventInfo.event, // Pass the full event
      tInstance // Pass the translation instance
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

    // --- NEW DYNAMIC RENDERING SYSTEM ---

    // Position trackers
    let currentX = 0;
    let currentY = 0;

    // Prepare component props
    const componentProps = {
      time: { timeText },
      title: { title: eventInfo.event.title, displayTime: timeText },
      organizer: { eventData },
      resourceCircles: {
        resources: eventInfo.event.extendedProps.source.resources,
        timeText,
        title: eventInfo.event.title,
        maxHeight: 24 // Add consistent maxHeight to match text components
      },
      resourceList: {
        resources: eventInfo.event.extendedProps.source.resources
      },
      customBadge: {
        text: 'NEW',
        backgroundColor: '#4a86e8',
        color: '#ffffff'
      }
    };

    // Add any custom props from layout config
    if (layoutConfig.componentProps) {
      for (const compName in layoutConfig.componentProps) {
        if ((componentProps as any)[compName]) {
			(componentProps as any)[compName] = {
            ...(componentProps as any)[compName],
            ...layoutConfig.componentProps[compName]
          };
        }
      }
    }

    // Process components in order
    for (let i = 0; i < components.length; i++) {
      const componentName = components[i];
      const component = componentRegistry.get(componentName);

      if (!component) {
        if (DEBUG_CANVAS_DIMENSIONS) {
          console.warn(`Component "${componentName}" not found in registry`);
        }
        continue;
      }

      // Check if we're in horizontal rendering mode
      const isHorizontalRendering = layoutConfig.horizontalRendering === true;

      // Calculate available space
      const availableWidth = actualDimensions.width - currentX;
      const availableHeight = actualDimensions.height - currentY;

      if (DEBUG_CANVAS_DIMENSIONS) {
        console.log(`Rendering component ${componentName}`, {
          currentX,
          currentY,
          availableWidth,
          availableHeight,
          horizontalRendering: isHorizontalRendering,
          componentProps: (componentProps as any)[componentName]
        });
      }

      // Render the component
      const result = component.render(
        drawContext,
		  (componentProps as any)[componentName],
        currentX,
        currentY,
        availableWidth,
        availableHeight
      );

      // Update position based on render result and rendering mode
      if (isHorizontalRendering) {
        // In horizontal mode, advance X position
        currentX += result.xAdvance;
      } else {
        // In vertical mode, reset X and advance Y
        currentX = 0;
        currentY += result.yAdvance;
      }

      if (DEBUG_CANVAS_DIMENSIONS) {
        console.log(`Component ${componentName} rendered`, {
          usedWidth: result.usedWidth,
          usedHeight: result.usedHeight,
          xAdvance: result.xAdvance,
          yAdvance: result.yAdvance,
          newX: currentX,
          newY: currentY
        });
      }
    }

    // --- END NEW RENDERING SYSTEM ---

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

  }, [canvasRef, containerRef, eventInfo, colours, fontLoaded, dimensions.width, dimensions.height, tInstance]);
}