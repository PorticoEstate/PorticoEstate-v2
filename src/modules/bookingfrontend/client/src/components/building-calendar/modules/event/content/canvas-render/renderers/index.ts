import { CanvasComponentRenderer, CanvasDrawContext } from '../types';
import { DEBUG_CANVAS_DIMENSIONS } from '../utils';

// Component registry
class ComponentRegistry {
  private components: Map<string, CanvasComponentRenderer> = new Map();

  // Register a component
  register(component: CanvasComponentRenderer): void {
    if (!component) {
      console.error('Attempted to register undefined component');
      return;
    }
    this.components.set(component.name, component);
    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log(`Registered component: ${component.name}`);
    }
  }

  // Get a component by name
  get(name: string): CanvasComponentRenderer | undefined {
    return this.components.get(name);
  }

  // Get all registered component names
  getAllNames(): string[] {
    return Array.from(this.components.keys());
  }

  // Check if a component exists
  has(name: string): boolean {
    return this.components.has(name);
  }
}

// Create and export singleton instance
export const componentRegistry = new ComponentRegistry();

// Register all components in a function to avoid circular dependencies
export function registerAllComponents() {
  // To avoid circular dependencies, import all renderers first
  const { timeRenderer } = require('./timeRenderer');
  const { titleRenderer } = require('./titleRenderer');
  const { organizerRenderer } = require('./organizerRenderer');
  const { resourceCirclesRenderer } = require('./resourceCirclesRenderer');
  const { resourceListRenderer } = require('./resourceListRenderer');

  // Import custom components
  try {
    const { customBadgeRenderer } = require('./customBadgeRenderer');

    // Register built-in components
    componentRegistry.register(timeRenderer);
    componentRegistry.register(titleRenderer);
    componentRegistry.register(organizerRenderer);
    componentRegistry.register(resourceCirclesRenderer);
    componentRegistry.register(resourceListRenderer);

    // Register custom components
    componentRegistry.register(customBadgeRenderer);

    if (DEBUG_CANVAS_DIMENSIONS) {
      console.log('All components registered successfully', {
        components: componentRegistry.getAllNames()
      });
    }
  } catch (err) {
    console.error('Error registering components:', err);
  }
}

// Re-export these for backward compatibility
export * from './timeRenderer';
export * from './titleRenderer';
export * from './organizerRenderer';
export * from './resourceCirclesRenderer';
export * from './resourceListRenderer';