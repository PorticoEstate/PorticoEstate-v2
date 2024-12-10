'use client'
import {Details} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import 'photoswipe/dist/photoswipe.css'
import {Gallery, Item} from 'react-photoswipe-gallery'
import {IDocument} from "@/service/types/api.types";
import styles from './photos-grid.module.scss';
import {getDocumentLink} from "@/service/api/building";
interface PhotosGridProps {
    photos: IDocument[];
    type: 'building' | 'resource';
}

const PhotosGrid = (props: PhotosGridProps) => {
    const t = useTrans();
    return (
        <Details data-color={'brand1'}>
            <Details.Summary>
                <h3>{t('bookingfrontend.pictures')}</h3>
            </Details.Summary>
            <Details.Content>

                <Gallery options={{
                    gallery: '#gallery--dynamic-zoom-level'
                }}>
                    <div
                        style={{

                        }}
                        className={styles.photoGrid}
                    >
                        {props.photos.map(photo => {
                            const url = getDocumentLink(photo, props.type);
                            return (<Item
                                key={photo.id}
                                original={url}
                                cropped
                            >
                                {({ref, open}) => (
                                    <img style={{
                                        cursor: 'pointer',
                                        objectFit: 'cover',
                                        width: '220px',
                                        height: '100px',
                                        borderRadius: 12
                                    }} ref={ref} onClick={open} src={url} alt={photo.description}/>
                                )}
                            </Item>)
                        })}
                    </div>
                </Gallery>
            </Details.Content>
        </Details>
);
}

export default PhotosGrid


