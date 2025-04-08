import { useEffect, useState, RefObject } from 'react';
import { Dimensions } from './types';

// Debug flags for canvas rendering
export const DEBUG_CANVAS_DIMENSIONS = true;
export const DEBUG_CANVAS_VISUAL = true; // Enable visual debug outlines and labels

/**
 * Hook to manage canvas dimensions and handle resizing
 */
export function useCanvasDimensions(
  containerRef: RefObject<HTMLDivElement>
): Dimensions {
  const [dimensions, setDimensions] = useState<Dimensions>({ width: 0, height: 0 });
  
  useEffect(() => {
    if (!containerRef.current) return;

    // Get the full calendar container
    const container = containerRef.current.closest(".fc-timegrid-event-harness") as HTMLElement;
    if (!container) return;

    const updateDimensions = () => {
      if (!container) return;
      const rect = container.getBoundingClientRect();

      // Round to full pixels to avoid sub-pixel rendering issues
      const width = Math.round(container.offsetWidth || rect.width);
      const height = Math.round(container.offsetHeight || rect.height);

      // Only update if dimensions have actually changed
      if (width !== dimensions.width || height !== dimensions.height) {
        if (DEBUG_CANVAS_DIMENSIONS) {
          console.log(`Canvas resize: ${width}x${height}`, {
            container: container.className,
            containerSize: { w: container.offsetWidth, h: container.offsetHeight },
            boundingRect: rect,
            eventId: container.querySelector('.fc-event-title')?.textContent
          });
        }
        setDimensions({ width, height });
      }
    };

    // Initial measurement
    updateDimensions();

    // Listen for window resize
    window.addEventListener('resize', updateDimensions);

    // Use ResizeObserver for more precise container resize detection
    const resizeObserver = new ResizeObserver(updateDimensions);
    resizeObserver.observe(container);

    // If parent container changes, we need to re-observe
    const mutationObserver = new MutationObserver((mutations) => {
      mutations.forEach(mutation => {
        if (mutation.type === 'attributes' &&
            (mutation.attributeName === 'style' || mutation.attributeName === 'class')) {
          updateDimensions();
        }
      });
    });

    mutationObserver.observe(container, {
      attributes: true,
      attributeFilter: ['style', 'class']
    });

    return () => {
      window.removeEventListener('resize', updateDimensions);
      resizeObserver.disconnect();
      mutationObserver.disconnect();
    };
  }, [containerRef, dimensions.width, dimensions.height]);

  return dimensions;
}