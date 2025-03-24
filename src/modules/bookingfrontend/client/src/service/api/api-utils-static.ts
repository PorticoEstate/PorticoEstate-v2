import {ISearchDataOptimized} from "@/service/types/api/search.types";
import {phpGWLink} from "@/service/util";
import { unstable_cache } from 'next/cache';
// Use Next.js cache function to memoize the fetch result
export const fetchSearchData = unstable_cache(
	async (): Promise<ISearchDataOptimized> => {
		const url = phpGWLink(['bookingfrontend', 'searchdataalloptimised']);
		const response = await fetch(url);

		if (!response.ok) {
			throw new Error(`Failed to fetch search data: ${response.status}`);
		}

		const result = await response.json();
		console.log("SEARCH DATA FETCHED - Should only see this once per hour");
		return result;
	},
	['search-data'], // A unique cache key
	{ revalidate: 3600 }
);