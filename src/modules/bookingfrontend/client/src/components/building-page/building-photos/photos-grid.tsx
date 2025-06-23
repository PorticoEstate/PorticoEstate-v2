'use client'
import 'photoswipe/dist/photoswipe.css'
import './photoswipe-custom.css'
import {Gallery, Item} from 'react-photoswipe-gallery'
import {IDocument} from "@/service/types/api.types";
import styles from './photos-grid.module.scss';
import {getDocumentLink} from "@/service/api/building";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {Button} from "@digdir/designsystemet-react";
import {Fragment, useEffect, useState} from "react";
import {useIsMobile} from "@/service/hooks/is-mobile";

interface PhotosGridProps {
	photos: IDocument[];
	type: 'building' | 'resource';
}

const PhotosGrid = (props: PhotosGridProps) => {
	const t = useTrans();
	const isMobile = useIsMobile();
	const [visibleCount, setVisibleCount] = useState(3);

	// Adjust visible photo count based on screen width
	useEffect(() => {
		const updateVisibleCount = () => {
			const width = window.innerWidth;
			if (width < 768) {
				// Mobile: show fewer images
				setVisibleCount(1);
			} else if (width < 992) {
				// Tablet: medium amount
				setVisibleCount(2);
			} else {
				// Desktop: show max 3
				setVisibleCount(3);
			}
		};

		// Initial calculation
		updateVisibleCount();

		// Recalculate on resize
		window.addEventListener('resize', updateVisibleCount);
		return () => window.removeEventListener('resize', updateVisibleCount);
	}, []);

	// Calculate visible photos based on screen size
	const photosToShow = Math.min(visibleCount, props.photos.length);
	const visiblePhotos = props.photos.slice(0, photosToShow);
	const hasMorePhotos = props.photos.length > photosToShow;

	return (
		<div className={styles.photosContainer}>
			<Gallery options={{
				showHideAnimationType: 'fade',
				imageClickAction: 'zoom',
				tapAction: 'zoom',
				doubleTapAction: 'zoom',
				secondaryZoomLevel: 2,
				maxZoomLevel: 4,
				preloaderDelay: 0
			}}>
				<div className={styles.photoGrid}>
					{/* Render ALL photos as gallery items, but only show some visually */}
					{props.photos.map((photo, index) => {
						const url = getDocumentLink(photo, props.type);
						const isVisible = index < photosToShow;
						const isLast = index === photosToShow - 1 && hasMorePhotos;

						return (
							<div 
								key={photo.id} 
								className={`${styles.photoItem} ${!isVisible ? styles.hidden : ''}`}
							>
								<Item
									original={url}
									thumbnail={url}
									width={1200}
									height={800}
								>
									{({ref, open}) => (
										<div className={styles.imageContainer}>
											<img
												ref={ref}
												onClick={open}
												src={url}
												alt={photo.description}
												className={styles.photo}
											/>
											{isLast && (
												<div
													onClick={open}
													className={styles.viewAllOverlay}
												>
													<Button asChild variant="secondary" data-size="md">
														<span>
														{t('bookingfrontend.view_all_images')}</span>
													</Button>
												</div>
											)}
										</div>
									)}
								</Item>
							</div>
						);
					})}
				</div>
			</Gallery>
		</div>
	);
}

export default PhotosGrid