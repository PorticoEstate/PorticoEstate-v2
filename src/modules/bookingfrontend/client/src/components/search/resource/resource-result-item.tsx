import React, {FC} from 'react';
import {Button, Card, Heading, Paragraph} from '@digdir/designsystemet-react';
import {ISearchDataBuilding} from "@/service/types/api/search.types";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import styles from './resource-search.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {CircleFillIcon} from "@navikt/aksel-icons";

interface ResourceResultItemProps {
	resource: IResource & { building?: ISearchDataBuilding };
}

const ResourceResultItem: FC<ResourceResultItemProps> = ({resource}) => {
	const t = useTrans();

	return (
		<Card
			data-color="neutral"
			style={{
				maxWidth: '320px'
			}}
		>
			<Heading>
				{resource.name}
			</Heading>
			{/*<Paragraph>*/}
			{/*	Most provide as with carried business are much better more the perfected designer. Writing slightly explain desk unable at supposedly about this*/}
			{/*</Paragraph>*/}
			<Paragraph data-size="sm">
				{[resource.building?.district, resource.building?.name].reduce<Element | null>((res: Element | null, curr) =>
					<React.Fragment>{res}{res ?
						<CircleFillIcon title="a11y-title" fontSize="1.5rem"/> : ''}{curr}</React.Fragment>, null)}
			</Paragraph>
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