import { FCallEvent, FCEventContentArg } from "@/components/building-calendar/building-calendar.types";

export interface CanvasEventContentProps {
  eventInfo: FCEventContentArg<FCallEvent>;
}

export interface Dimensions {
  width: number;
  height: number;
}

export type LayoutType = 'minimal' | 'short' | 'medium' | 'standard' | 'large';

export interface CanvasDrawContext {
  ctx: CanvasRenderingContext2D;
  dimensions: Dimensions;
  colours: string[];
  layoutType: LayoutType;
  devicePixelRatio: number;
}