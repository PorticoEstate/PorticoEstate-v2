# Canvas-Based Event Rendering System

This module provides a high-performance, configurable rendering system for calendar events using HTML Canvas. The architecture is designed to be extensible, allowing for easy addition of new components and layouts.

## Overview

- **Component-Based Architecture**: Each visual element (time, title, organizer, etc.) is implemented as a separate renderer
- **Responsive Layouts**: Different combinations of components are shown based on available space
- **Registry System**: Components are registered with a central registry for extensibility
- **Side-by-Side Support**: Components can be rendered next to each other or stacked vertically
- **High-DPI Support**: Proper scaling for retina displays

## Core Components

1. **Component Registry**: Central registry for all renderable components
2. **Layout Rules**: Configuration for which components to display based on height
3. **Canvas Rendering Hook**: Main rendering pipeline that coordinates everything
4. **Component Renderers**: Individual renderers for each visual element

### Built-in Components

- `time`: Renders the event time (start-end)
  - Props: `timeText`, `maxWidth`, `maxHeight`
- `title`: Renders the event title with truncation
  - Props: `title`, `displayTime`, `maxHeight`
- `organizer`: Renders the event organizer 
  - Props: `eventData`, `maxHeight`
- `resourceCircles`: Renders resource indicators as colored circles with overflow counter
  - Props: `resources`, `timeText`, `title`, `maxHeight`
- `resourceList`: Renders resources as a vertical list with colored circles and names
  - Props: `resources`, `maxItems`
  - Uses translation for "more" text from `tInstance('bookingfrontend.more')`
- `customBadge`: Example custom component showing a colored badge with text
  - Props: `text`, `color`, `backgroundColor`, `borderRadius`, `padding`

## Using the System

### Creating a New Component

To create a new component renderer:

```typescript
import { CanvasComponentRenderer, CanvasDrawContext, ComponentRenderResult } from "../types";
import { drawDebugOutline } from "../utils";

export const myCustomRenderer: CanvasComponentRenderer = {
  name: 'myCustomComponent', // Unique name to reference the component
  render: (
    context: CanvasDrawContext,
    props: any, // Define your props type here
    x: number,
    y: number,
    width: number
  ): ComponentRenderResult => {
    const { ctx } = context;
    
    // Your drawing code here
    // ...
    
    // Return component dimensions and how much to advance
    return {
      height: componentHeight,
      width: componentWidth,
      xAdvance: 0, // How much to advance X (usually 0 unless side-by-side)
      yAdvance: componentHeight // How much to advance Y (usually component height)
    };
  }
};
```

### Registering the Component

Register your component in the component registry:

```typescript
// In your component file
import { componentRegistry } from './index';
componentRegistry.register(myCustomRenderer);

// Or in renderers/index.ts
import { myCustomRenderer } from './myCustomRenderer';
componentRegistry.register(myCustomRenderer);
```

### Adding to Layout Rules

To use your component in layouts, modify the `LAYOUT_RULES` array in `utils.ts`:

```typescript
export const LAYOUT_RULES: LayoutRule[] = [
  // ...existing rules
  {
    name: 'custom',
    maxHeight: 120,
    components: ['time', 'title', 'myCustomComponent'], // Include your component
    description: 'Custom layout with my component',
    sideBySideComponents: {
      // Define any side-by-side arrangements
      time_myCustomComponent: true
    },
    // Optional custom props for components
    componentProps: {
      myCustomComponent: {
        customOption: true
      }
    }
  }
];
```

## Layout Configuration

### Layout Rule Properties

- `name`: String identifier for the layout
- `maxHeight`: Maximum container height for this rule to apply (use Infinity for unbounded)
- `components`: Array of component names to render in order
- `description`: Human-readable description
- `horizontalRendering`: Whether components should flow horizontally (side-by-side) instead of vertically
- `componentProps`: Custom props to pass to specific components in this layout

### Horizontal vs. Vertical Rendering

The layout system offers two rendering modes:

1. **Vertical Rendering** (default): Components are stacked vertically, each taking full width
2. **Horizontal Rendering**: Components are arranged side by side, flowing horizontally

To enable horizontal rendering, set the `horizontalRendering` property to `true` in your layout rule:

```typescript
{
  name: 'compact',
  maxHeight: 30,
  components: ['time', 'resourceCircles'],
  description: 'Compact side-by-side layout',
  horizontalRendering: true,
  componentProps: {
    time: {
      maxWidth: 35 // Limit time component width in horizontal mode
    }
  }
}
```

When in horizontal mode, each component can control how much horizontal space it takes by returning appropriate `xAdvance` values.

## Debugging and Testing

The system includes built-in visual debugging:

- `DEBUG_CANVAS_DIMENSIONS`: Enables detailed console logging
- `DEBUG_CANVAS_VISUAL`: Draws component outlines for visual debugging

These constants can be toggled in `useCanvasDimensions.ts`.

### Testing Custom Layouts

You can test custom layouts or force specific layouts by adding a query parameter to the URL:

```
?testLayout=custom
```

This will enable a layout named "custom" regardless of event height.

You can also specify a numeric height to force a specific layout tier:

```
?testLayout=100
```

This will choose the first layout with maxHeight >= 100.

## Example Custom Layout

```typescript
{
  name: 'myCustomLayout',
  maxHeight: 150,
  components: ['title', 'time', 'myCustomComponent', 'resourceCircles'],
  description: 'A custom layout with my component',
  horizontalRendering: true,
  componentProps: {
    time: {
      maxWidth: 35,  // Limit time width in horizontal mode
      maxHeight: 24  // Enforce consistent height and vertical centering
    },
    myCustomComponent: {
      highlightColor: '#ff0000',
      maxItems: 3
    },
    resourceCircles: {
      maxCircles: 5,
      maxHeight: 24  // Enforce consistent height and vertical centering
    }
  }
}
```

Another example with vertical layout and resource list:

```typescript
{
  name: 'detailedView',
  maxHeight: 200,
  components: ['time', 'title', 'organizer', 'resourceList'],
  description: 'Detailed view with full resource list',
  horizontalRendering: false, // Stack components vertically (default)
  componentProps: {
    time: {
      maxHeight: 20  // Lower height for time component
    },
    title: {
      maxHeight: 26  // Taller height for title component
    },
    organizer: {
      maxHeight: 22  // Custom height for organizer component
    },
    resourceList: {
      maxItems: 3  // Only show 3 resources before "+more" indicator
    }
  }
}
```

The component order in the array determines their rendering order, and the `horizontalRendering` flag controls whether they flow horizontally or stack vertically.