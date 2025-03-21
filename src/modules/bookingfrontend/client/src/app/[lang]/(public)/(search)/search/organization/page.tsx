import {FC} from 'react';
import OrganizationSearch from '@/components/search/organization/organization-search';
import {getTranslation} from '@/app/i18n';

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
	return (
		<div>
			{/*<Heading level={1} data-size="large">*/}
			{/*  {t('bookingfrontend.search_organizations')}*/}
			{/*</Heading>*/}
			<OrganizationSearch/>
		</div>
	);
};

export default OrganizationSearchPage;