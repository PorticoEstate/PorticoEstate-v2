import React, {PropsWithChildren} from 'react';
import ClientLayout from "@/app/[lang]/(public)/(search)/client-layout";
import {fetchServerSettings} from "@/service/api/api-utils";
import {Alert, Heading, Paragraph} from "@digdir/designsystemet-react";
import {getTranslation} from "@/app/i18n";
import ClientHeading from "@/app/[lang]/(public)/(search)/client-heading";
import {parseHtmlToMarkdown} from "@/components/building-page/util/building-text-util";
import ReactMarkdown from 'react-markdown';

export const dynamic = 'force-dynamic';

// Helper to preprocess markdown and extract link target information
const preprocessMarkdown = (markdown: string) => {
	// Find all instances of [text](url){:target="_blank"} and convert them to standard markdown
	// while keeping track of which links should open in new tab
	const linkTargets = new Map();
	let processedMarkdown = markdown;
	
	// Replace the special syntax with standard markdown and store target info
	processedMarkdown = processedMarkdown.replace(
		/\[([^\]]+)\]\(([^)]+)\)\{:target="_blank"\}/g,
		(match, text, url) => {
			linkTargets.set(url, true);
			return `[${text}](${url})`;
		}
	);
	
	return { processedMarkdown, linkTargets };
};

// Helper to extract text content from React elements
const extractTextContent = (node: React.ReactNode): string => {
	if (typeof node === 'string') return node;
	if (typeof node === 'number') return String(node);
	if (Array.isArray(node)) return node.map(extractTextContent).join('');
	if (React.isValidElement(node)) {
		return extractTextContent((node.props as any).children);
	}
	return '';
};

// Helper to render inline markdown (for headings with bold text)
const InlineMarkdown = ({children}: {children: React.ReactNode}) => {
	// If children is already a React element (like <strong>), just return it
	if (React.isValidElement(children)) {
		return <>{children}</>;
	}
	
	// Otherwise, treat as text and parse markdown
	const text = extractTextContent(children);
	return (
		<ReactMarkdown components={{
			p: ({children}) => <>{children}</>,
			strong: ({children}) => <strong>{children}</strong>,
			em: ({children}) => <em>{children}</em>,
			a: ({href, children}) => {
				// Check if link should open in new tab based on our special syntax
				const shouldOpenInNewTab = href?.includes('{:target="_blank"}');
				const cleanHref = href?.replace('{:target="_blank"}', '') || '';
				
				return shouldOpenInNewTab ? (
					<a href={cleanHref} target="_blank" rel="noopener noreferrer">{children}</a>
				) : (
					<a href={cleanHref}>{children}</a>
				);
			}
		}}>{text}</ReactMarkdown>
	);
};

export default async function Layout(props: PropsWithChildren) {
	const serverSettings = await fetchServerSettings();
	const {t} = await getTranslation();

	const frontImageText = serverSettings.booking_config?.frontimagetext
		? parseHtmlToMarkdown(serverSettings.booking_config.frontimagetext)
		: null;

	const frontPageText = serverSettings.booking_config?.frontpagetext
		? parseHtmlToMarkdown(serverSettings.booking_config.frontpagetext)
		: null;

	// Process the markdown to handle special link syntax
	const frontImageProcessed = frontImageText?.markdown 
		? preprocessMarkdown(frontImageText.markdown)
		: null;
	
	const frontPageProcessed = frontPageText?.markdown 
		? preprocessMarkdown(frontPageText.markdown)
		: null;

	return (
		<div>
			{/* Warning alert box with frontimagetext */}
			{frontImageProcessed && (
				<Alert data-color="warning" style={{marginBottom: '1rem'}}>
					{frontImageText?.title && <strong>{frontImageText.title}</strong>}
					{frontImageText?.title && frontImageProcessed.processedMarkdown && <br/>}
					<ReactMarkdown components={{
						h1: ({children}) => <Heading level={2} data-size="lg"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h2: ({children}) => <Heading level={3} data-size="md"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h3: ({children}) => <Heading level={4} data-size="sm"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h4: ({children}) => <Heading level={5} data-size="xs"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h5: ({children}) => <Heading level={6} data-size="2xs"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h6: ({children}) => <Heading level={6} data-size="2xs"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						p: ({children}) => <Paragraph data-size="md">{children}</Paragraph>,
						a: ({href, children}) => {
							// Use the preprocessed link target information
							const shouldOpenInNewTab = frontImageProcessed.linkTargets.get(href);
							
							return shouldOpenInNewTab ? (
								<a href={href} target="_blank" rel="noopener noreferrer">{children}</a>
							) : (
								<a href={href}>{children}</a>
							);
						}
					}}>{frontImageProcessed.processedMarkdown}</ReactMarkdown>
				</Alert>
			)}

			{/* Info alert box with frontpagetext */}
			{frontPageProcessed && (
				<div data-color="info" style={{marginBottom: '1rem'}}>
					{frontPageText?.title &&
						<Heading level={4} data-size={'md'} style={{margin: 0}}>{frontPageText.title}</Heading>}
					{/*{frontPageText.title && frontPageText.markdown && <br />}*/}
					<ReactMarkdown components={{
						h1: ({children}) => <Heading level={2} data-size="lg"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h2: ({children}) => <Heading level={3} data-size="md"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h3: ({children}) => <Heading level={4} data-size="sm"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h4: ({children}) => <Heading level={5} data-size="xs"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h5: ({children}) => <Heading level={6} data-size="2xs"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h6: ({children}) => <Heading level={6} data-size="2xs"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						p: ({children}) => <Paragraph data-size="md">{children}</Paragraph>,
						a: ({href, children}) => {
							// Use the preprocessed link target information
							const shouldOpenInNewTab = frontPageProcessed.linkTargets.get(href);
							
							return shouldOpenInNewTab ? (
								<a href={href} target="_blank" rel="noopener noreferrer">{children}</a>
							) : (
								<a href={href}>{children}</a>
							);
						}
					}}>{frontPageProcessed.processedMarkdown}</ReactMarkdown>
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