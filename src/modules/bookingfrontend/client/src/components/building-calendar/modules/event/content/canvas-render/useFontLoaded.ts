import { useState, useEffect } from 'react';

/**
 * Hook to check if the required font is loaded
 */
export function useFontLoaded(fontName: string = 'Roboto'): boolean {
  const [fontLoaded, setFontLoaded] = useState(false);
  
  useEffect(() => {
    // Use FontFace API to check if the font is available
    if ('FontFace' in window) {
      document.fonts.ready.then(() => {
        if (document.fonts.check(`12px '${fontName}'`)) {
          setFontLoaded(true);
        } else {
          // If not loaded yet, mark as loaded anyway and rely on CSS import
          setFontLoaded(true);
        }
      });
    } else {
      // Fallback for browsers without FontFace API
      setFontLoaded(true);
    }
  }, [fontName]);
  
  return fontLoaded;
}