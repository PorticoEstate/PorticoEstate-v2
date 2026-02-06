import React, {FC, useMemo, useCallback} from 'react';
import {Card, Heading, Paragraph, Link as DigdirLink, Tag} from '@digdir/designsystemet-react';
import {ISearchDataBuilding} from "@/service/types/api/search.types";
import styles from './resource-result-item.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {TenancyIcon} from "@navikt/aksel-icons";
import Link from "next/link";
import Image from "next/image";
import DividerCircle from "@/components/util/DividerCircle";
import {useTowns, useMultiDomains, useSearchData} from "@/service/hooks/api-hooks";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {createDomainBuildingUrl} from "@/service/multi-domain-utils";
import BuildingIcon from "@/icons/BuildingIcon";

interface BuildingResultItemProps {
	building: ISearchDataBuilding;
	selectedDate: Date | null;
	resourceCount?: number;
}

const BuildingResultItem: FC<BuildingResultItemProps> = ({building, selectedDate, resourceCount}) => {
	const t = useTrans();
	const {data: searchData} = useSearchData();
	const {data: towns} = useTowns();
	const {data: multiDomains} = useMultiDomains();
	const isMobile = useIsMobile();

	// Get building image data (URL and focal point)
	const buildingImage = useMemo(() => {
		const basePath = process.env.NEXT_PUBLIC_BASE_PATH || '';
		const placeholderUrl = `${basePath}/resource_placeholder_bilde.png`;

		if (!searchData?.building_pictures) {
			return {
				url: placeholderUrl,
				focalPoint: undefined
			};
		}

		// Find picture for this building
		const picture = searchData.building_pictures.find(p => p.owner_id === building.id);

		if (!picture) {
			return {
				url: placeholderUrl,
				focalPoint: undefined
			};
		}

		// In production, use full public URL with domain; in development, use proxy
		const isProduction = process.env.NODE_ENV === 'production';
		const imageUrl = isProduction
			? `${typeof window !== 'undefined' ? window.location.origin : (process.env.NEXT_PUBLIC_API_URL || '')}/bookingfrontend/buildings/document/${picture.id}/download`
			: `${basePath}/fetch-building-image-proxy/${picture.id}`;

		return {
			url: imageUrl,
			focalPoint: picture.metadata?.focal_point
		};
	}, [searchData, building.id]);

	// Calculate CSS object-position from focal point (x and y are percentages)
	const imageStyle = useMemo(() => {
		if (!buildingImage.focalPoint) {
			return undefined;
		}
		return {
			objectPosition: `${buildingImage.focalPoint.x}% ${buildingImage.focalPoint.y}%`
		};
	}, [buildingImage.focalPoint]);

	// Find the town for this building by town_id
	const town = useMemo(() => {
		if (!towns || !building.town_id) return null;
		return towns.find(t => t.id === building.town_id);
	}, [towns, building.town_id]);

	// Find the domain for this building if it's from another domain
	const domain = useMemo(() => {
		if (!multiDomains || !building.domain_name) return null;
		return multiDomains.find(d => d.name === building.domain_name);
	}, [multiDomains, building.domain_name]);

	// Determine if this building is from another domain
	const isExternalDomain = !!building.domain_name && !!domain;

	// Helper function to check if selected date is today
	const isToday = (date: Date): boolean => {
		const today = new Date();
		return date.toDateString() === today.toDateString();
	};

	// Helper function to format date for URL (YYYY-MM-DD)
	const formatDateForUrl = (date: Date): string => {
		return date.toISOString().split('T')[0];
	};

	// Create building URL with date parameter if selected date is not today
	const createBuildingUrl = useCallback((): string => {
		if (isExternalDomain && domain) {
			return createDomainBuildingUrl(domain, building.id, building.original_id);
		}

		if (!selectedDate || isToday(selectedDate)) {
			return `/building/${building.id}`;
		}

		return `/building/${building.id}/${formatDateForUrl(selectedDate)}`;
	}, [isExternalDomain, domain, building.id, building.original_id, selectedDate]);

	const tags = useMemo(() => {
		const capitalizeFirstLetter = (str: string) => str.charAt(0).toUpperCase() + str.slice(1);

		const tagElements = [
			town?.name ? capitalizeFirstLetter(town.name) : undefined,
			isExternalDomain && building.domain_name ? capitalizeFirstLetter(building.domain_name) : undefined
		];

		return tagElements.filter(a => !!a);
	}, [building, town, isExternalDomain]);

	// Build address string
	const address = useMemo(() => {
		const parts = [building.street, building.city].filter(Boolean);
		return parts.length > 0 ? parts.join(', ') : null;
	}, [building.street, building.city]);

	return (
		<Card
			data-color="neutral"
			className={styles.resourceCard}
		>
			<Card.Block className={styles.imageBlock}>
				<div className={styles.imageWrapper}>
					<Image
						src={buildingImage.url}
						alt=""
						fill
						sizes="(max-width: 850px) 100vw, (max-width: 1200px) 50vw, 33vw"
						className={styles.cardImage}
						style={imageStyle}
						priority={false}
					/>
				</div>
			</Card.Block>

			<Card.Block className={styles.contentBlock}>
				<div className={styles.resourceTags}>
					<Tag data-color="brand1" data-size="sm">
						{t('bookingfrontend.building_title')}
					</Tag>
					{tags.map((tag, index) => {
						return <Tag data-color="brand1" data-size="sm" key={index}>{tag}</Tag>;
					})}
				</div>

				<DigdirLink asChild data-color='brand1'>
					<Link
						href={createBuildingUrl()}
						className={styles.titleLink}
						{...(isExternalDomain ? {target: '_blank', rel: 'noopener noreferrer'} : {})}
					>
						<div className={styles.resourceHeadingContainer}>
							<Heading level={3} data-size="xs" className={styles.resourceIcon}>
								<BuildingIcon/>
							</Heading>
							<Heading level={3} data-size="xs" className={styles.resourceTitle}>
								{building.name}
							</Heading>
						</div>
					</Link>
				</DigdirLink>

				{( address) && (
					<Paragraph data-size="xs" className={styles.resourceAddress}>
						<span>{address}</span>
					</Paragraph>
				)}

				{resourceCount !== undefined && resourceCount > 0 && (
					<Paragraph data-size="sm" className={styles.capacity}>
						<strong>{t('bookingfrontend.resources') || 'Ressurser'}:</strong> {resourceCount}
					</Paragraph>
				)}
			</Card.Block>
		</Card>
	);
};

export default BuildingResultItem;
