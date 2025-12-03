import {useEffect, useState} from "react";

export const useIsMobile = () => {
    const [isMobile, setIsMobile] = useState<boolean>(
        typeof window !== 'undefined' ? window.innerWidth < 851 : false
    );

    const handleResize = () => {
        const width = window.innerWidth;
        setIsMobile(width < 851);
    };
    
    // Effect hook to initialize calendar and add resize listener
    useEffect(() => {
        handleResize(); // Initial check
        window.addEventListener('resize', handleResize); // Add resize listener
        return () => {
            window.removeEventListener('resize', handleResize); // Cleanup on unmount
        };
    }, []);
    
    return isMobile;
}

export const useIsDesktopWidth = (breakpoint = 1024) => {
    const [isDesktop, setIsDesktop] = useState<boolean>(
        typeof window !== 'undefined' ? window.innerWidth >= breakpoint : true
    );

    const handleResize = () => {
        const width = window.innerWidth;
        setIsDesktop(width >= breakpoint);
    };
    
    useEffect(() => {
        handleResize(); // Initial check
        window.addEventListener('resize', handleResize);
        return () => {
            window.removeEventListener('resize', handleResize);
        };
    }, [breakpoint]);
    
    return isDesktop;
}

export const useIsMediumWidth = () => {
    const [isMedium, setIsMedium] = useState<boolean>(
        typeof window !== 'undefined' ? window.innerWidth >= 768 && window.innerWidth < 1024 : false
    );

    const handleResize = () => {
        const width = window.innerWidth;
        setIsMedium(width >= 768 && width < 1024);
    };
    
    useEffect(() => {
        handleResize(); // Initial check
        window.addEventListener('resize', handleResize);
        return () => {
            window.removeEventListener('resize', handleResize);
        };
    }, []);
    
    return isMedium;
}