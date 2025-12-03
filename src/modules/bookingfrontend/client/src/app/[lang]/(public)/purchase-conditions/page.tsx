import React from 'react';
import {fetchServerSettings} from "@/service/api/api-utils";
import {Heading, Paragraph} from "@digdir/designsystemet-react";
import {parseHtmlToMarkdown} from "@/components/building-page/util/building-text-util";
import ReactMarkdown from 'react-markdown';

export const dynamic = 'force-dynamic';

// Helper to preprocess markdown and extract link target information
const preprocessMarkdown = (markdown: string) => {
	const linkTargets = new Map();
	let processedMarkdown = markdown;

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
		return extractTextContent(node.props.children);
	}
	return '';
};

// Helper to render inline markdown (for headings with bold text)
const InlineMarkdown = ({children}: {children: React.ReactNode}) => {
	if (React.isValidElement(children)) {
		return <>{children}</>;
	}

	const text = extractTextContent(children);
	return (
		<ReactMarkdown components={{
			p: ({children}) => <>{children}</>,
			strong: ({children}) => <strong>{children}</strong>,
			em: ({children}) => <em>{children}</em>,
			a: ({href, children}) => {
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

export default async function PurchaseConditionsPage() {
	const serverSettings = await fetchServerSettings();
	const purchaseConditions = serverSettings.booking_config?.purchase_conditions
		? parseHtmlToMarkdown(serverSettings.booking_config.purchase_conditions)
		: null;

	const purchaseConditionsProcessed = purchaseConditions?.markdown
		? preprocessMarkdown(purchaseConditions.markdown)
		: null;

	return (
		<div style={{maxWidth: '800px', margin: '0 auto', padding: '2rem'}}>
			{purchaseConditionsProcessed ? (
				<div>
					{purchaseConditions?.title && (
						<Heading level={1} data-size={'xl'} style={{marginBottom: '1rem'}}>
							{purchaseConditions.title}
						</Heading>
					)}
					<ReactMarkdown components={{
						h1: ({children}) => <Heading level={2} data-size="lg"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h2: ({children}) => <Heading level={3} data-size="md"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h3: ({children}) => <Heading level={4} data-size="sm"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h4: ({children}) => <Heading level={5} data-size="xs"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h5: ({children}) => <Heading level={6} data-size="2xs"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						h6: ({children}) => <Heading level={6} data-size="2xs"><InlineMarkdown>{children}</InlineMarkdown></Heading>,
						p: ({children}) => <Paragraph data-size="md">{children}</Paragraph>,
						a: ({href, children}) => {
							const shouldOpenInNewTab = purchaseConditionsProcessed.linkTargets.get(href);

							return shouldOpenInNewTab ? (
								<a href={href} target="_blank" rel="noopener noreferrer">{children}</a>
							) : (
								<a href={href}>{children}</a>
							);
						}
					}}>{purchaseConditionsProcessed.processedMarkdown}</ReactMarkdown>
				</div>
			) : (
				<div>
					<Heading level={1} data-size={'xl'}>Purchase Conditions</Heading>
					<Paragraph>No purchase conditions configured.</Paragraph>
				</div>
			)}
		</div>
	);
}
