import {useEffect, useState} from "react";

export const useIsMobile = () => {
    const [isMobile, setIsMobile] = useState<boolean>(window.innerWidth < 851);

    const handleResize = () => {
        const width = window.innerWidth;
        setIsMobile(width < 851);

    };
    // Effect hook to initialize calendar and add resize listener
    useEffect(() => {
        window.addEventListener('resize', handleResize); // Add resize listener
        return () => {
            window.removeEventListener('resize', handleResize); // Cleanup on unmount
        };
    }, []);
    return isMobile
}