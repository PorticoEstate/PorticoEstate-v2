'use client'
import {Accordion} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import 'photoswipe/dist/photoswipe.css'
import {Gallery, Item} from 'react-photoswipe-gallery'
import {IDocument} from "@/service/types/api.types";
import {getDocumentLink} from "@/service/api/api-utils";
import styles from './photos-grid.module.scss';
interface PhotosGridProps {
    photos: IDocument[];
}

const PhotosGrid = (props: PhotosGridProps) => {
    const t = useTrans();
    return (
        <Accordion.Item>
            <Accordion.Heading>
                <h3>{t('bookingfrontend.pictures')}</h3>
            </Accordion.Heading>
            <Accordion.Content>

                <Gallery options={{
                    gallery: '#gallery--dynamic-zoom-level'
                }}>
                    <div
                        style={{

                        }}
                        className={styles.photoGrid}
                    >
                        {props.photos.map(photo => {
                            const url = getDocumentLink(photo);
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
            </Accordion.Content>
        </Accordion.Item>
);
}

export default PhotosGrid


