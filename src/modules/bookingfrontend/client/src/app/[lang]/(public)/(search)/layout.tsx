import React, {PropsWithChildren} from 'react';
import ClientLayout from "@/app/[lang]/(public)/(search)/client-layout";
import {fetchServerSettings} from "@/service/api/api-utils";
import {Alert, Heading, Paragraph} from "@digdir/designsystemet-react";
import {getTranslation} from "@/app/i18n";
import ClientHeading from "@/app/[lang]/(public)/(search)/client-heading";
import {normalizeText} from "@/components/building-page/util/building-text-util";

export const dynamic = 'force-dynamic';
export default async function Layout(props: PropsWithChildren) {
	const serverSettings = await fetchServerSettings();
	const {t} = await getTranslation();

	const frontImageText = serverSettings.booking_config?.frontimagetext
		? normalizeText(serverSettings.booking_config.frontimagetext)
		: null;

	const frontPageText = serverSettings.booking_config?.frontpagetext
		? normalizeText(serverSettings.booking_config.frontpagetext)
		: null;

	return (
		<div>
			{/* Warning alert box with frontimagetext */}
			{frontImageText && frontImageText.body && (
				<Alert data-color="warning" style={{marginBottom: '1rem'}}>
					{frontImageText.title && <strong>{frontImageText.title}</strong>}
					{frontImageText.title && frontImageText.body && <br />}
					<span style={{whiteSpace: 'pre-line'}}>{frontImageText.body}</span>
				</Alert>
			)}

			{/* Info alert box with frontpagetext */}
			{frontPageText && frontPageText.body && (
				<div data-color="info" style={{marginBottom: '1rem'}}>
					{frontPageText.title && <Heading level={4} data-size={'md'} style={{margin:0 }}>{frontPageText.title}</Heading>}
					{/*{frontPageText.title && frontPageText.body && <br />}*/}
					<span style={{whiteSpace: 'pre-line'}}>{frontPageText.body}</span>
				</div>
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