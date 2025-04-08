import { FCallEvent, FCEventContentArg } from "@/components/building-calendar/building-calendar.types";

export interface CanvasEventContentProps {
  eventInfo: FCEventContentArg<FCallEvent>;
}

export interface Dimensions {
  width: number;
  height: number;
}

export type ComponentType = 'time' | 'title' | 'organizer' | 'resourceList' | 'resourceCircles';

export interface LayoutRule {
  name: string;  // Name of the layout rule
  maxHeight: number;  // Maximum height for this rule (use Infinity for unbounded)
  components: ComponentType[];  // Components to render in this layout
  description: string;  // Human-readable description of the rule
  sideBySideComponents?: {  // Optional config for components that should render side-by-side
    time_resourceCircles?: boolean;  // Whether time and resourceCircles should be rendered side-by-side
  };
}

export type LayoutType = string; // Now it's a string matching the rule name

export interface CanvasDrawContext {
  ctx: CanvasRenderingContext2D;
  dimensions: Dimensions;
  colours: string[];
  layoutType: LayoutType;
  layoutComponents: ComponentType[];  // Components enabled in the current layout
  layoutConfig: LayoutRule;  // The full layout configuration
  devicePixelRatio: number;
}