'use client'
import {FC, useState, useEffect, useMemo} from 'react'
import {Controller, Control, FieldValues, Path} from 'react-hook-form'
import {
	Field,
	Label,
	Select,
	ValidationMessage
} from '@digdir/designsystemet-react'
import {useTrans, useClientTranslation} from '@/app/i18n/ClientTranslationProvider'
import {languages} from '@/app/i18n/settings'
import TurndownService from 'turndown'
import {unescapeHTML} from '@/components/building-page/util/building-text-util'
import dynamic from 'next/dynamic'
import styles from './multilingual-markdown-editor.module.scss'

// Dynamic import to avoid SSR issues with the markdown editor
const MDEditor = dynamic(
	() => import('@uiw/react-md-editor').then((mod) => mod.default),
	{ ssr: false }
)

interface MultilingualMarkdownEditorProps<T extends FieldValues> {
	name: Path<T>
	control: Control<T>
	label: string
	error?: string
	initialHtmlValue?: string | null
	className?: string
}

const MultilingualMarkdownEditor = <T extends FieldValues>({
	name,
	control,
	label,
	error,
	initialHtmlValue,
	className
}: MultilingualMarkdownEditorProps<T>) => {
	const t = useTrans()
	const {i18n} = useClientTranslation()
	const [selectedLanguage, setSelectedLanguage] = useState<string>(i18n.language)
	const [languageContents, setLanguageContents] = useState<Record<string, string>>({})

	// Initialize Turndown service for HTML → Markdown conversion
	const turndownService = useMemo(() => {
		const service = new TurndownService({
			headingStyle: 'atx',
			bulletListMarker: '-',
			codeBlockStyle: 'fenced'
		})
		
		// Custom rules for better conversion
		service.addRule('lineBreaks', {
			filter: 'br',
			replacement: () => '\n\n'
		})
		
		return service
	}, [])

	// Initialize language contents from HTML JSON
	useEffect(() => {
		if (initialHtmlValue) {
			try {
				const htmlJson = JSON.parse(initialHtmlValue)
				const markdownContents: Record<string, string> = {}
				
				Object.entries(htmlJson).forEach(([lang, htmlContent]) => {
					if (typeof htmlContent === 'string' && htmlContent.trim()) {
						const unescapedHtml = unescapeHTML(htmlContent)
						markdownContents[lang] = turndownService.turndown(unescapedHtml)
					}
				})
				
				setLanguageContents(markdownContents)
			} catch (error) {
				console.warn('Failed to parse initial HTML value:', error)
			}
		}
	}, [initialHtmlValue, turndownService])

	// Convert markdown back to HTML JSON for form submission
	const convertToHtmlJson = (markdownContents: Record<string, string>): string => {
		const htmlJson: Record<string, string> = {}
		
		Object.entries(markdownContents).forEach(([lang, markdown]) => {
			if (markdown.trim()) {
				// Convert markdown to HTML (simple approach)
				let html = markdown
					.replace(/^### (.*$)/gim, '<h3>$1</h3>')
					.replace(/^## (.*$)/gim, '<h2>$1</h2>')
					.replace(/^# (.*$)/gim, '<h1>$1</h1>')
					.replace(/\*\*(.*)\*\*/gim, '<strong>$1</strong>')
					.replace(/\*(.*)\*/gim, '<em>$1</em>')
					.replace(/\n\n/gim, '</p><p>')
					.replace(/\n/gim, '<br>')
				
				// Wrap in paragraphs if not already wrapped
				if (!html.startsWith('<h') && !html.startsWith('<p')) {
					html = `<p>${html}</p>`
				}
				
				htmlJson[lang] = html
			}
		})
		
		return JSON.stringify(htmlJson)
	}

	const handleLanguageChange = (language: string) => {
		setSelectedLanguage(language)
	}

	const handleContentChange = (language: string, content: string) => {
		const newContents = { ...languageContents, [language]: content }
		setLanguageContents(newContents)
		return convertToHtmlJson(newContents)
	}

	const getCurrentContent = () => {
		return languageContents[selectedLanguage] || ''
	}

	return (
		<Field className={className}>
			<Label>{label}</Label>
			
			<div className={styles.editorContainer}>
				{/* Language Selector */}
				<div className={styles.editorHeader}>
					<Select
						value={selectedLanguage}
						onChange={(e) => handleLanguageChange(e.target.value)}
						className={styles.languageSelect}
					>
						{languages.map((lang) => (
							<option key={lang.key} value={lang.key}>
								{lang.label}
							</option>
						))}
					</Select>
				</div>

				{/* Markdown Editor */}
				<div className={styles.editorContent}>
					<Controller
						name={name}
						control={control}
						render={({ field }) => (
							<MDEditor
								value={getCurrentContent()}
								onChange={(value) => {
									const newValue = handleContentChange(selectedLanguage, value || '')
									field.onChange(newValue)
								}}
								preview="edit"
								hideToolbar={false}
								visibleDragbar={false}
								textareaProps={{
									placeholder: t('bookingfrontend.enter_description_markdown'),
									style: {
										fontSize: 14,
										lineHeight: 1.6,
										fontFamily: '"Monaco", "Menlo", "Ubuntu Mono", monospace'
									}
								}}
								height={400}
								data-color-mode="light"
							/>
						)}
					/>
				</div>

				{/* Language Tabs for quick switching */}
				<div className={styles.languageTabs}>
					{languages.map((lang) => {
						const hasContent = languageContents[lang.key]?.trim()
						return (
							<button
								key={lang.key}
								type="button"
								className={`${styles.languageTab} ${
									selectedLanguage === lang.key ? styles.active : ''
								} ${hasContent ? styles.hasContent : ''}`}
								onClick={() => handleLanguageChange(lang.key)}
							>
								{lang.label}
								{hasContent && <span className={styles.contentIndicator}>●</span>}
							</button>
						)
					})}
				</div>

				{/* Help Text */}
				<div className={styles.helpText}>
					<p>{t('bookingfrontend.markdown_help_professional')}</p>
				</div>
			</div>

			{error && <ValidationMessage>{error}</ValidationMessage>}
		</Field>
	)
}

export default MultilingualMarkdownEditor