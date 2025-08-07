'use client'
import {ReactNode} from 'react'
import GSAccordion from "@/components/gs-accordion/g-s-accordion"
import {Heading} from "@digdir/designsystemet-react"
import styles from './responsive-wrapper.module.scss'
import {useIsMobile} from "@/service/hooks/is-mobile";

interface ResponsiveWrapperProps {
	title: string
	children: ReactNode
	isEmpty?: boolean
	className?: string
}

const ResponsiveWrapper = (props: ResponsiveWrapperProps) => {
	const {title, children, isEmpty = false} = props
	const isMobile = useIsMobile();
	// Don't render if content is empty
	if (isEmpty) {
		return null
	}

	if (isMobile) {
		return (<GSAccordion data-color={'neutral'} className={`${styles.contentSection} ${props.className || ''}`}>
			<GSAccordion.Heading>
				<h3>{title}</h3>
			</GSAccordion.Heading>
			<GSAccordion.Content>{children}</GSAccordion.Content>
		</GSAccordion>)
	}

	return (

		<section className={`${styles.contentSection} ${props.className || ''}`}>
			<Heading level={2} data-size={'md'} className={styles.sectionTitle}>
				{title}
			</Heading>
			<div className={styles.sectionContent}>
				{children}
			</div>
		</section>
	)
}

export default ResponsiveWrapper