import React, {FC, useMemo} from 'react';
import {Card, Heading, Paragraph, Link as DigdirLink} from '@digdir/designsystemet-react';
import {ISearchDataActivity, ISearchDataBuilding, ISearchDataTown, ISearchResource} from "@/service/types/api/search.types";
import styles from './resource-result-item.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {LayersIcon} from "@navikt/aksel-icons";
import Link from "next/link";
import DividerCircle from "@/components/util/DividerCircle";
import {useSearchData, useTowns} from "@/service/hooks/api-hooks";
import {useIsMobile} from "@/service/hooks/is-mobile";

interface ResourceResultItemProps {
	resource: ISearchResource & { building?: ISearchDataBuilding };
}

const ResourceResultItem: FC<ResourceResultItemProps> = ({resource}) => {
	const t = useTrans();
	const {data: searchData} = useSearchData();
	const {data: towns} = useTowns();
	const isMobile = useIsMobile();
	
	// Find activity associated with this resource
	const activity = useMemo(() =>
		resource.activity_id ?
			searchData?.activities.find(a => a.id === resource.activity_id) :
			undefined,
		[resource.activity_id, searchData?.activities]
	);
	
	// Find the town for this building by town_id
	const town = useMemo(() => {
		if (!towns || !resource.building?.town_id) return null;
		return towns.find(t => t.id === resource.building?.town_id);
	}, [towns, resource.building?.town_id]);

	const tags = useMemo(() => {
		const tagElements = [
			<DigdirLink key='building-link' asChild className={styles.buildingLink} data-color='brand1'>
				<Link href={'/building/' + resource.building?.id}>{resource.building?.name}</Link>
			</DigdirLink>,
			town?.name // Display town name instead of district
		];

		// // Add activity tag if available
		// if (activity) {
		// 	tagElements.push(activity.name);
		// }

		return tagElements.filter(a => !!a);
	}, [resource, town, activity]);

	return (
		<Card
			data-color="neutral"
			className={styles.resourceCard}
		>
			<div className={styles.cardContent}>
				<DigdirLink asChild data-color='accent'>
					<Link href={'/resource/' + resource.id} className={styles.titleLink}>
						<div className={styles.resourceHeadingContainer}>
							<Heading level={3} data-size="xs" className={styles.resourceIcon}>
								<LayersIcon fontSize="1em"/>
							</Heading>
							<Heading level={3} data-size="xs" className={styles.resourceTitle}>
								{resource.name}
							</Heading>
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