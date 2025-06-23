import React, {PropsWithChildren} from 'react';
import ClientLayout from "@/app/[lang]/(public)/(search)/client-layout";
import {fetchServerSettings} from "@/service/api/api-utils";
import {Alert, Heading, Paragraph} from "@digdir/designsystemet-react";
import {getTranslation} from "@/app/i18n";
import ClientHeading from "@/app/[lang]/(public)/(search)/client-heading";
import {parseHtmlToMarkdown} from "@/components/building-page/util/building-text-util";
import ReactMarkdown from 'react-markdown';

export const dynamic = 'force-dynamic';
export default async function Layout(props: PropsWithChildren) {
	const serverSettings = await fetchServerSettings();
	const {t} = await getTranslation();

	const frontImageText = serverSettings.booking_config?.frontimagetext
		? parseHtmlToMarkdown(serverSettings.booking_config.frontimagetext)
		: null;

	const frontPageText = serverSettings.booking_config?.frontpagetext
		? parseHtmlToMarkdown(serverSettings.booking_config.frontpagetext)
		: null;

	return (
		<div>
			{/* Warning alert box with frontimagetext */}
			{frontImageText && frontImageText.markdown && (
				<Alert data-color="warning" style={{marginBottom: '1rem'}}>
					{frontImageText.title && <strong>{frontImageText.title}</strong>}
					{frontImageText.title && frontImageText.markdown && <br/>}
					<ReactMarkdown components={{
						h1: ({children}) => <Heading level={2} data-size="lg">{children}</Heading>,
						h2: ({children}) => <Heading level={3} data-size="md">{children}</Heading>,
						h3: ({children}) => <Heading level={4} data-size="sm">{children}</Heading>,
						h4: ({children}) => <Heading level={5} data-size="xs">{children}</Heading>,
						h5: ({children}) => <Heading level={6} data-size="2xs">{children}</Heading>,
						h6: ({children}) => <Heading level={6} data-size="2xs">{children}</Heading>,
						p: ({children}) => <Paragraph data-size="md">{children}</Paragraph>
					}}>{frontImageText.markdown}</ReactMarkdown>
				</Alert>
			)}

			{/* Info alert box with frontpagetext */}
			{frontPageText && frontPageText.markdown && (
				<div data-color="info" style={{marginBottom: '1rem'}}>
					{frontPageText.title &&
						<Heading level={4} data-size={'md'} style={{margin: 0}}>{frontPageText.title}</Heading>}
					{/*{frontPageText.title && frontPageText.markdown && <br />}*/}
					<ReactMarkdown components={{
						h1: ({children}) => <Heading level={2} data-size="lg">{children}</Heading>,
						h2: ({children}) => <Heading level={3} data-size="md">{children}</Heading>,
						h3: ({children}) => <Heading level={4} data-size="sm">{children}</Heading>,
						h4: ({children}) => <Heading level={5} data-size="xs">{children}</Heading>,
						h5: ({children}) => <Heading level={6} data-size="2xs">{children}</Heading>,
						h6: ({children}) => <Heading level={6} data-size="2xs">{children}</Heading>,
						p: ({children}) => <Paragraph data-size="md">{children}</Paragraph>
					}}>{frontPageText.markdown}</ReactMarkdown>
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