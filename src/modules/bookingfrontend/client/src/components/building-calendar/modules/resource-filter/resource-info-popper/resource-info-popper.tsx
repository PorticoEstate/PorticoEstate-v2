import React, {FC, useEffect, useRef, useState} from 'react';
import {useIsMobile} from "@/service/hooks/is-mobile";
import ResourceInfoModalContent
    from "@/components/building-calendar/modules/resource-filter/resource-info-popper/resource-info-modal-content";
import MobileDialog from "@/components/dialog/mobile-dialog";
import styles from "@/components/building-calendar/modules/event/popper/event-popper.module.scss";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {Button} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";

interface ResourceInfoPopperProps {
    resource_id: string | null;
    resource_name: string | null;
    onClose: () => void;
}

const ResourceInfoPopper: FC<ResourceInfoPopperProps> = ({resource_id, onClose, resource_name}) => {
    // const [open, setOpen] = useState(Boolean(resource_id));
    const t = useTrans();
    if (!resource_id || !resource_name) {
        return null;
    }

    return (
        <MobileDialog size={'hd'} open={true}
	dialogId={'resource-info-dialog'}
                      onClose={() => {
            // setOpen(false)
            onClose();
        }} title={<h3 className={styles.eventName}><ColourCircle resourceId={+resource_id}
                                                                 size={'medium'}/> {resource_name}
        </h3>
        }

                      footer={<Button onClick={onClose} variant="tertiary" className={'default'} data-size={'sm'}
                                      style={{textTransform: 'capitalize'}}>{t('common.ok').toLowerCase()}</Button>}>
            <ResourceInfoModalContent resource_id={resource_id} onClose={onClose} name={resource_name}/>
        </MobileDialog>
    );
}

export default ResourceInfoPopper


