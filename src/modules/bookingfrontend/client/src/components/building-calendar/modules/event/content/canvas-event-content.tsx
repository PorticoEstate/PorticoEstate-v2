import React, { FC, memo, useRef } from 'react';
import { FCallEvent, FCEventContentArg } from "@/components/building-calendar/building-calendar.types";
import { useColours } from "@/service/hooks/Colours";
import { 
  CanvasEventContentProps, 
  useCanvasDimensions, 
  useFontLoaded, 
  useCanvasRender 
} from './canvas-render';

import './canvas-event-content.css';

/**
 * A canvas-based event content component that renders everything on a canvas for optimal performance.
 */
const CanvasEventContent: FC<CanvasEventContentProps> = memo(function CanvasEventContent(props) {
  const { eventInfo } = props;
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const colours = useColours();
  
  // Use hooks to manage state and rendering
  const dimensions = useCanvasDimensions(containerRef);
  const fontLoaded = useFontLoaded('Roboto');
  
  // Use canvas render hook to handle all drawing logic
  useCanvasRender({
    canvasRef,
    containerRef,
    eventInfo,
    dimensions,
    fontLoaded,
    colours
  });

  return (
    <div ref={containerRef} className="canvas-event-container">
      <canvas
        ref={canvasRef}
        className="canvas-event"
      />
    </div>
  );
});

export default CanvasEventContent;