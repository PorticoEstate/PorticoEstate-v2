import {FC} from 'react';
import ResourceSearch from "@/components/search/resource/resource-search";
import { fetchSearchData } from "@/service/api/api-utils";
import { ISearchDataOptimized } from "@/service/types/api/search.types";

interface SearchProps {
}

// Revalidate the page every 1 hour
export const revalidate = 3600;

const Search: FC<SearchProps> = async () => {
	// Fetch search data server-side
	const initialSearchData: ISearchDataOptimized = await fetchSearchData();
	
	return (
		<main>
			<ResourceSearch initialSearchData={initialSearchData} />
		</main>
	);
}

export default Search


