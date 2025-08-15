'use client'
import React, {FC} from 'react';
import {Card, Heading, Paragraph, Link as DigdirLink} from '@digdir/designsystemet-react';
import {ISearchOrganization} from "@/service/types/api/search.types";
import styles from './organization-result-item.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import Link from "next/link";
import {phpGWLink} from "@/service/util";

interface OrganizationResultItemProps {
	organization: ISearchOrganization;
}

const DividerCircle = () => <svg width="6" height="6" viewBox="0 0 6 6" fill="none" xmlns="http://www.w3.org/2000/svg">
	<path d="M0 3C0 1.34315 1.34315 0 3 0C4.65685 0 6 1.34315 6 3C6 4.65685 4.65685 6 3 6C1.34315 6 0 4.65685 0 3Z"
		  fill="#2B2B2B"/>
</svg>

const OrganizationResultItem: FC<OrganizationResultItemProps> = ({organization}) => {
	const t = useTrans();

	const tags = React.useMemo(() => {
		const tagList = [];

		if (organization.district) {
			tagList.push(organization.district);
		}

		if (organization.city) {
			tagList.push(organization.city);
		}

		return tagList;
	}, [organization]);

	return (
		<Card
			data-color="neutral"
			className={styles.organizationCard}
		>
			<div className={styles.cardContent}>
				<DigdirLink asChild data-color='accent'>
					<Link href={`/organization/${organization.id}`} className={styles.titleLink}>
						<Heading level={3} data-size="xs" className={styles.organizationHeading}>
							{/*<BuildingIcon fontSize="1em"/>*/}
							{organization.name}
						</Heading>
					</Link>
				</DigdirLink>

				<Paragraph data-size="sm" className={styles.organizationTags}>
					{tags.map((tag, index) => {
						if (index === 0) {
							return <span key={'tag' + index}>{tag}</span>
						}
						return <React.Fragment key={'tag' + index}>
							<DividerCircle/>{tag}</React.Fragment>
					})}
				</Paragraph>
			</div>
		</Card>
	);
};

export default OrganizationResultItem;