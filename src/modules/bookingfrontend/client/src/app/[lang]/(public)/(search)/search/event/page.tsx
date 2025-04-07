import {FC} from 'react';
import { fetchSearchData, fetchUpcomingEventsStatic } from "@/service/api/api-utils-static";
import EventSearchClient from '@/components/search/event/event-search'

// Revalidate the page every 1 hour
export const revalidate = 3600;

interface EventSearchProps {
    params: {
        lang: string;
    };
}

const EventSearch: FC<EventSearchProps> = async () => {
    // Fetch search data and initial events server-side
    const [initialSearchData, initialEvents] = await Promise.all([
        fetchSearchData(),
        fetchUpcomingEventsStatic()
    ]);

    return (
        <div>
            <EventSearchClient
                initialSearchData={initialSearchData}
                initialEvents={initialEvents}
            />
        </div>
    );
}

export default EventSearch;

