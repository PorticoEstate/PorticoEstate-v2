import type { SeverityColorDefinitions } from '@digdir/designsystemet-types';

// Extend ColorDefinitions to include severity colors so they can be used in Button data-color
declare module '@digdir/designsystemet-types' {
  export interface ColorDefinitions extends SeverityColorDefinitions {}
}
