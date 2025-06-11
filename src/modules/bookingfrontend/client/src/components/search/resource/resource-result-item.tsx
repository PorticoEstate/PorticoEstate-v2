import React, {FC, useMemo, useCallback} from 'react';
import {Card, Heading, Paragraph, Link as DigdirLink} from '@digdir/designsystemet-react';
import {ISearchDataActivity, ISearchDataBuilding, ISearchDataTown, ISearchResource} from "@/service/types/api/search.types";
import styles from './resource-result-item.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {LayersIcon} from "@navikt/aksel-icons";
import Link from "next/link";
import DividerCircle from "@/components/util/DividerCircle";
import {useTowns, useMultiDomains} from "@/service/hooks/api-hooks";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {createDomainResourceUrl, createDomainBuildingUrl, redirectToDomain} from "@/service/multi-domain-utils";

interface ResourceResultItemProps {
	resource: ISearchResource & { building?: ISearchDataBuilding };
	selectedDate: Date | null;
	isAvailable?: boolean;
}

const ResourceResultItem: FC<ResourceResultItemProps> = ({resource, selectedDate, isAvailable}) => {
	const t = useTrans();
	// const {data: searchData} = useSearchData(); // Commented out since activity lookup is not currently used
	const {data: towns} = useTowns();
	const {data: multiDomains} = useMultiDomains();
	const isMobile = useIsMobile();

	// Find activity associated with this resource (currently unused but kept for potential future use)
	// const activity = useMemo(() =>
	// 	resource.activity_id ?
	// 		searchData?.activities.find(a => a.id === resource.activity_id) :
	// 		undefined,
	// 	[resource.activity_id, searchData?.activities]
	// );

	// Find the town for this building by town_id
	const town = useMemo(() => {
		if (!towns || !resource.building?.town_id) return null;
		return towns.find(t => t.id === resource.building?.town_id);
	}, [towns, resource.building?.town_id]);

	// Find the domain for this resource if it's from another domain
	const domain = useMemo(() => {
		if (!multiDomains || !resource.domain_name) return null;
		return multiDomains.find(d => d.name === resource.domain_name);
	}, [multiDomains, resource.domain_name]);

	// Determine if this resource is from another domain
	const isExternalDomain = !!resource.domain_name && !!domain;

	// Helper function to check if selected date is today
	const isToday = (date: Date): boolean => {
		const today = new Date();
		return date.toDateString() === today.toDateString();
	};

	// Helper function to format date for URL (YYYY-MM-DD)
	const formatDateForUrl = (date: Date): string => {
		return date.toISOString().split('T')[0];
	};

	// Create URLs with date parameter if selected date is not today
	const createResourceUrl = useCallback((): string => {
		if (isExternalDomain && domain) {
			return createDomainResourceUrl(domain, resource.id, resource.original_id);
		}

		if (!selectedDate || isToday(selectedDate)) {
			return `/resource/${resource.id}`;
		}

		return `/resource/${resource.id}/${formatDateForUrl(selectedDate)}`;
	}, [isExternalDomain, domain, resource.id, resource.original_id, selectedDate]);

	const createBuildingUrl = useCallback((): string => {
		if (isExternalDomain && domain && resource.building) {
			return createDomainBuildingUrl(domain, resource.building.id, resource.building.original_id);
		}

		if (!selectedDate || isToday(selectedDate)) {
			return `/building/${resource.building?.id}`;
		}

		return `/building/${resource.building?.id}/${formatDateForUrl(selectedDate)}`;
	}, [isExternalDomain, domain, resource.building, selectedDate]);

	const tags = useMemo(() => {
		const capitalizeFirstLetter = (str: string) => str.charAt(0).toUpperCase() + str.slice(1);

		const tagElements = [
			<DigdirLink key='building-link' asChild className={styles.buildingLink} data-color='brand1'>
				<Link
					href={createBuildingUrl()}
					{...(isExternalDomain ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
				>
					{resource.building?.name}
				</Link>
			</DigdirLink>,
			town?.name ? capitalizeFirstLetter(town.name) : undefined, // Display town name instead of district
			isExternalDomain && resource.domain_name ? capitalizeFirstLetter(resource.domain_name) : undefined // Add domain name if from another domain
		];

		// // Add activity tag if available
		// if (activity) {
		// 	tagElements.push(activity.name);
		// }

		return tagElements.filter(a => !!a);
	}, [resource, town, isExternalDomain, createBuildingUrl]);

	// // Handle click for external domain resources
	// const handleResourceClick = (e: React.MouseEvent) => {
	// 	if (isExternalDomain && domain) {
	// 		e.preventDefault();
	// 		const externalUrl = createDomainResourceUrl(domain, resource.id, resource.original_id);
	// 		redirectToDomain(externalUrl);
	// 	}
	// };

	return (
		<Card
			data-color="neutral"
			className={styles.resourceCard}
		>
			<div className={styles.cardContent}>
				<DigdirLink asChild data-color='accent'>
					<Link
						href={createResourceUrl()}
						className={styles.titleLink}
						// onClick={handleResourceClick}
						{...(isExternalDomain ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
					>
						<div className={styles.resourceHeadingContainer}>
							<Heading level={3} data-size="xs" className={styles.resourceIcon}>
								<LayersIcon fontSize="1em"/>
							</Heading>
							<Heading level={3} data-size="xs" className={styles.resourceTitle}>
								{resource.name}
							</Heading>
							{/* Availability indicator */}
							{selectedDate && isAvailable !== undefined && (
								<span
									className={`${styles.availabilityIndicator} ${isAvailable ? styles.available : styles.unavailable}`}
									title={isAvailable ? t('bookingfrontend.available') : t('bookingfrontend.leased')}
								>
									{/*{isAvailable ? '✓' : '✗'}*/}
								</span>
							)}
						</div>
					</Link>
				</DigdirLink>


				<Paragraph data-size={isMobile ? 'xs' : "sm"} className={styles.resourceTags}>
					{tags.map((tag, index) => {
						if (index === 0) {
							return <span key={'tag' + index}>{tag}</span>
						}
						return <React.Fragment key={'tag' + index}>
							<DividerCircle/>{tag}</React.Fragment>
					})}
				</Paragraph>
			</div>

			{/*<div className={styles.cardAction}>*/}
			{/*	<DigdirLink asChild data-color='accent'>*/}

			{/*		<Link href={'/resource/' + resource.id} className={styles.viewLink}>*/}
			{/*			{t('search.view_details') || 'View details'}*/}
			{/*		</Link>*/}
			{/*	</DigdirLink>*/}
			{/*</div>*/}
		</Card>
		// <div className={styles.resourceCard}>
		// 	<div className={styles.resourceHeader}>
		// 		<ColourCircle resourceId={resource.id} size="medium" />
		// 		<h3 className={styles.resourceName}>{resource.name}</h3>
		// 	</div>
		//
		// 	<div className={styles.resourceDetails}>
		// 		{resource.building && (
		// 			<>
		// 				<p className={styles.buildingName}>
		// 					<span className={styles.detailLabel}>{t('search.location')}:</span> {resource.building.name}
		// 				</p>
		// 				{resource.building.district && (
		// 					<p className={styles.district}>
		// 						<span className={styles.detailLabel}>{t('search.district')}:</span> {resource.building.district}
		// 					</p>
		// 				)}
		// 			</>
		// 		)}
		//
		// 		{resource.capacity && (
		// 			<p className={styles.capacity}>
		// 				<span className={styles.detailLabel}>{t('search.capacity')}:</span> {resource.capacity}
		// 			</p>
		// 		)}
		//
		// 		{resource.opening_hours && (
		// 			<p className={styles.openingHours}>
		// 				<span className={styles.detailLabel}>{t('search.opening_hours')}:</span> {resource.opening_hours}
		// 			</p>
		// 		)}
		// 	</div>
		//
		// 	<div className={styles.resourceActions}>
		// 		<Button
		// 			onClick={() => window.location.href = `/buildings/${resource.building?.id}`}
		// 			variant="primary"
		// 		>
		// 			{t('search.view_details')}
		// 		</Button>
		// 	</div>
		// </div>
	);
};

export default ResourceResultItem;