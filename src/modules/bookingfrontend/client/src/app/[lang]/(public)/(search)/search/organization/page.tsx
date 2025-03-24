import {FC} from 'react';
import OrganizationSearch from '@/components/search/organization/organization-search';
import {getTranslation} from '@/app/i18n';
import { fetchSearchData } from "@/service/api/api-utils-static";
import { ISearchDataOptimized } from "@/service/types/api/search.types";

// Revalidate the page every 1 hour
export const revalidate = 3600;

export async function generateMetadata({
										   params: {lang},
									   }: {
	params: { lang: string };
}) {
	const {t} = await getTranslation(lang);

	return {
		title: t('bookingfrontend.search_organizations'),
	};
}

interface OrganizationSearchPageProps {
	params: {
		lang: string;
	};
}

const OrganizationSearchPage: FC<OrganizationSearchPageProps> = async ({params}) => {
	// Fetch search data server-side
	const initialSearchData: ISearchDataOptimized = await fetchSearchData();

	return (
		<div>
			{/*<Heading level={1} data-size="large">*/}
			{/*  {t('bookingfrontend.search_organizations')}*/}
			{/*</Heading>*/}
			<OrganizationSearch initialSearchData={initialSearchData}/>
		</div>
	);
};

export default OrganizationSearchPage;