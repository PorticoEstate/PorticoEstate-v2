import React, {PropsWithChildren} from 'react';
import ClientLayout from "@/app/[lang]/(public)/(search)/client-layout";
import {fetchServerSettings} from "@/service/api/api-utils";
import {Alert, Heading, Paragraph} from "@digdir/designsystemet-react";
import {getTranslation} from "@/app/i18n";
import ClientHeading from "@/app/[lang]/(public)/(search)/client-heading";
import ServerMessages from '@/components/server-messages/server-messages';
import parse from 'html-react-parser';
import {unescapeHTML} from "@/components/building-page/util/building-text-util";


const hasValidContent = (content: string | undefined): boolean => {
	if (!content) return false;
	// Remove HTML tags and check if there's actual text content
	const textContent = content.replace(/<[^>]*>/g, '').trim();
	return textContent.length > 0;
};

export const dynamic = 'force-dynamic';
export default async function Layout(props: PropsWithChildren) {
	const serverSettings = await fetchServerSettings();
	const {t} = await getTranslation();

	return (
		<div>
			{/* Warning alert box with frontimagetext */}
			{hasValidContent(serverSettings.booking_config?.frontimagetext) && (
				<Alert data-color="warning" style={{marginBottom: '1rem'}}>
					{parse(unescapeHTML(serverSettings.booking_config.frontimagetext!))}
				</Alert>
			)}

			{/* Info alert box with frontpagetext */}
			{hasValidContent(serverSettings.booking_config?.frontpagetext) && (
				// <Alert data-color="info" style={{marginBottom: '1rem'}}>
				<div>{parse(unescapeHTML(serverSettings.booking_config.frontpagetext!))}</div>
				// </Alert>
			)}
			<ClientHeading>


				<header className="page-heading">
					<Heading data-size={"xl"}
							 className="page-title ds-color-accent">{t('bookingfrontend.rent_premises_facilities_equipment')}</Heading>
					<Paragraph data-size={'xl'}
							   className="page-subtitle ds-color-accent">{t('bookingfrontend.use_filters_to_find_rental_objects')}</Paragraph>
				</header>
			</ClientHeading>


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