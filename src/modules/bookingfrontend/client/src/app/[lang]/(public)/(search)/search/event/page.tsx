import {FC} from 'react';
import { fetchSearchData } from "@/service/api/api-utils";
import { ISearchDataOptimized } from "@/service/types/api/search.types";

// Revalidate the page every 1 hour
export const revalidate = 3600;

interface EventSearchProps {
    params: {
        lang: string;
    };
}

const EventSearch: FC<EventSearchProps> = async () => {
    // Fetch search data server-side
    const initialSearchData: ISearchDataOptimized = await fetchSearchData();
    
    return (
        <div>
            {/* Event search component will be implemented later */}
            {/* Will pass initialSearchData to the component */}
        </div>
    );
}

export default EventSearch

