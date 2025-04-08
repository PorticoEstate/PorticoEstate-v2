import { FCallEvent, FCEventContentArg } from "@/components/building-calendar/building-calendar.types";

export interface CanvasEventContentProps {
  eventInfo: FCEventContentArg<FCallEvent>;
}

export interface Dimensions {
  width: number;
  height: number;
}

export type ComponentType = string; // Now a string for any registered component

export interface LayoutRule {
  name: string;  // Name of the layout rule
  maxHeight: number;  // Maximum height for this rule (use Infinity for unbounded)
  components: ComponentType[];  // Components to render in this layout
  description: string;  // Human-readable description of the rule
  horizontalRendering?: boolean;  // Whether to render components horizontally (side-by-side)
  componentProps?: Record<string, any>; // Optional props for components
}

export type LayoutType = string; // String matching the rule name

export interface ComponentRenderResult {
  // Actual space used by the component
  usedWidth: number;
  usedHeight: number;

  // How much to advance rendering cursor
  xAdvance: number;
  yAdvance: number;
}

// Interface for canvas component renderers
export interface CanvasComponentRenderer {
  name: string;
  render: (
    context: CanvasDrawContext,
    props: any,
    x: number,
    y: number,
    availableWidth: number,
    availableHeight: number
  ) => ComponentRenderResult;
}

export interface CanvasDrawContext {
  ctx: CanvasRenderingContext2D;
  dimensions: Dimensions;
  colours: string[];
  layoutType: LayoutType;
  layoutComponents: ComponentType[];  // Components enabled in the current layout
  layoutConfig: LayoutRule;  // The full layout configuration
  devicePixelRatio: number;
  event: FCallEvent; // The event being rendered
  tInstance?: (key: string, options?: any) => string; // Translation instance
}