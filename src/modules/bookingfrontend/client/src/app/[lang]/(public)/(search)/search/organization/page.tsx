import {FC} from 'react';
import OrganizationSearch from '@/components/search/organization/organization-search';
import {getTranslation} from '@/app/i18n';
import { fetchOrganizationsStatic } from "@/service/api/api-utils-static";

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
	// Fetch organizations data server-side with caching
	const initialOrganizations = await fetchOrganizationsStatic();

	return (
		<div>
			{/*<Heading level={1} data-size="large">*/}
			{/*  {t('bookingfrontend.search_organizations')}*/}
			{/*</Heading>*/}
			<OrganizationSearch initialOrganizations={initialOrganizations}/>
		</div>
	);
};

export default OrganizationSearchPage;