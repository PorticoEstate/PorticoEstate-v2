import {FC} from 'react';
import { fetchSearchDataWithMultiDomains, fetchMultiDomainsStatic } from "@/service/api/api-utils-static";
import { ISearchDataOptimized } from "@/service/types/api/search.types";
import ResourceSearch from "@/components/search/resource/resource-search";

interface SearchProps {
}

// Revalidate the page every 1 hour
export const revalidate = 3600;

const Search: FC<SearchProps> = async () => {

	const multiDomains = await fetchMultiDomainsStatic();
	const initialSearchData = await fetchSearchDataWithMultiDomains(multiDomains);
	// Fetch search data from all domains server-side

	return (
		<main>
			<ResourceSearch
				initialSearchData={initialSearchData}
				initialTowns={initialSearchData.towns}
				initialMultiDomains={multiDomains}
			/>
		</main>
	);
}

export default Search


