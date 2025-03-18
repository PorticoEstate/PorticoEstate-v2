import {FC} from 'react';
import ResourceSearch from "@/components/search/resource/resource-search";

interface SearchProps {
}

const Search: FC<SearchProps> = (props) => {
	return (
		<main>
			<ResourceSearch />
		</main>
	);
}

export default Search


