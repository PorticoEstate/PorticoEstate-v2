import {FC} from 'react';
import { fetchSearchData, fetchUpcomingEventsStatic } from "@/service/api/api-utils-static";
import EventSearchClient from '@/components/search/event/event-search'

// Revalidate the page every 1 hour
export const revalidate = 3600;

interface EventSearchProps {
    params: {
        lang: string;
    };
    searchParams: {
        fromDate?: string;
        toDate?: string;
    };
}

const EventSearch: FC<EventSearchProps> = async ({ searchParams }) => {
    // Fetch search data and initial events server-side
    const [initialSearchData, initialEvents] = await Promise.all([
        fetchSearchData(),
        fetchUpcomingEventsStatic(searchParams.fromDate, searchParams.toDate)
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

