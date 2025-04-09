import React, {PropsWithChildren} from 'react';
import ClientLayout from "@/app/[lang]/(public)/(search)/client-layout";
import {fetchServerSettings} from "@/service/api/api-utils";
import {Alert, Heading, Paragraph} from "@digdir/designsystemet-react";
import {getTranslation} from "@/app/i18n";
import ClientHeading from "@/app/[lang]/(public)/(search)/client-heading";
import ServerMessages from '@/components/server-messages/server-messages';


export const dynamic = 'force-dynamic';
export default async function Layout(props: PropsWithChildren) {
	const serverSettings = await fetchServerSettings();
	const {t} = await getTranslation();

	return (
		<div>
			<ClientHeading>
				<header className="page-heading">
					<Heading data-size={"xl"}
							 className="page-title ds-color-accent">{t('bookingfrontend.rent_premises_facilities_equipment')}</Heading>
					<Paragraph data-size={'xl'}
							   className="page-subtitle ds-color-accent">{t('bookingfrontend.use_filters_to_find_rental_objects')}</Paragraph>
				</header>
			</ClientHeading>

			{/* Information alert */}
			<ServerMessages />

			{/* Navigation tabs - with proper WCAG attributes */}
			<ClientLayout serverSettings={serverSettings}/>

			{/* Tab content placeholder */}
			{/*<div id={`panel-${pathname === '/' ? 'leie' : pathname.substring(1)}`}*/}
			{/*	 role="tabpanel"*/}
			{/*	 aria-labelledby={`tab-${pathname === '/' ? 'leie' : pathname.substring(1)}`}>*/}
			{props.children}
			{/*</div>*/}
		</div>
	);
}