import {FC} from 'react';

interface SearchProps {
}

const Search: FC<SearchProps> = (props) => {
	return (
		<main>
			<div>SERVER INFO</div>
			<section>(searchBox) (filter) (area) (extra filters)</section>
			<section>ResultList</section>
		</main>
	);
}

export default Search


