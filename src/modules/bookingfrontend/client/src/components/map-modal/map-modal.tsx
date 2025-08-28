'use client'
import {FC, useMemo, useState} from 'react';
import styles from './map-modal.module.scss'
import {Button} from "@digdir/designsystemet-react";
import MobileDialog from "@/components/dialog/mobile-dialog";
interface MapModalProps {
    street: string;
    zip: string;
    city: string;
}

const MapModal: FC<MapModalProps> = (props) => {
    const [isOpen, setIsOpen] = useState<boolean>(false);
    const frameUrl = useMemo(() => {
        const mapParts = [props.street, props.zip, props.city].filter(a => !!a);
        return `https://maps.google.com/maps?f=q&source=s_q&hl=no&output=embed&geocode=&q=${mapParts.join(',')}`
    }, [props])
    return (
        <div>
            <Button onClick={() => setIsOpen(true)} className={`${styles.mapModalButton} text-overline default underline`} data-color={'brand1'} variant="tertiary">
                {props.street && `${props.street}, `}{props.zip}
            </Button>
            <MobileDialog open={isOpen} onClose={() => setIsOpen(false)} size={'hd'} dialogId={'map-modal'}>
                <div style={{display: 'flex', justifyContent: 'center', alignItems: 'stretch', width: '100%', height: '100%'}}>
                    <iframe id="iframeMap" frameBorder={0} scrolling="no" marginHeight={0} marginWidth={0}
                            style={{width: '100%'}} src={frameUrl}>
                    </iframe>
                </div>
            </MobileDialog>
        </div>
    );
}

export default MapModal


