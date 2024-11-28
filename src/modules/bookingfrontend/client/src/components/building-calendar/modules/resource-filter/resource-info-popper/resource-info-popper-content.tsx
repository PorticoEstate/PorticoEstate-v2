import React, {FC, Fragment, useMemo} from 'react';
import PopperContentSharedWrapper
    from "@/components/building-calendar/modules/event/popper/content/popper-content-shared-wrapper";
import {useResource} from "@/service/api/building";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import styles from "@/components/building-calendar/modules/event/popper/event-popper.module.scss";
import {Button, Spinner} from "@digdir/designsystemet-react";
import {useClientTranslation} from "@/app/i18n/ClientTranslationProvider";
import parse from "html-react-parser";
import {extractDescriptionText, unescapeHTML} from "@/components/building-page/util/building-text-util";

interface ResourceInfoPopperContentProps {
    resource_id: string
    onClose: () => void;
    name?: string;
}

const ResourceInfoPopperContent: FC<ResourceInfoPopperContentProps> = (props) => {
    const resource = useResource(props.resource_id);
    const {t, i18n} = useClientTranslation();
    const description = useMemo(() => resource?.data?.description_json ? extractDescriptionText(resource.data.description_json, i18n) : null, [resource])

    return (
        <PopperContentSharedWrapper onClose={props.onClose}>
            <div className={styles.eventPopperContent}>

                {resource.isLoading &&
                    <div style={{
                        position: 'absolute',
                        zIndex: 103,
                        // backgroundColor: 'white',
                        borderRadius: '50%',
                        // border: 'white 5px solid',
                        opacity: '75%',
                        top: 5,
                        right: 5
                    }}><Spinner title='Laster ressurs info' size='sm'/></div>
                }
                <h3 className={styles.eventName}><ColourCircle resourceId={+props.resource_id}
                                                               size={'medium'}/> {resource?.data?.name || props.name}
                </h3>

                {!resource.isLoading && (<Fragment>
                    {description && (
                        <div>
                            <h4>{t('bookingfrontend.description')}</h4>
                            <p>{parse(unescapeHTML(description))}</p>
                        </div>
                    )}
                    {resource.data?.opening_hours && (
                        <div>
                            <h4>{t('bookingfrontend.description')}</h4>
                            <p>{parse(unescapeHTML(resource.data.opening_hours))}</p>
                        </div>
                    )}
                    {resource.data?.contact_info && (
                        <div>
                            <h4>{t('bookingfrontend.description')}</h4>
                            <p>{parse(unescapeHTML(resource.data.contact_info))}</p>
                        </div>
                    )}
                </Fragment>)}
            </div>

            <div className={styles.eventPopperFooter}>
                <Button onClick={props.onClose} variant="tertiary" className={'default'} size={'sm'}
                        style={{textTransform: 'capitalize'}}>{t('common.ok').toLowerCase()}</Button>
            </div>
        </PopperContentSharedWrapper>
    );
}

export default ResourceInfoPopperContent


