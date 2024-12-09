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

const ResourceInfoModalContent: FC<ResourceInfoPopperContentProps> = (props) => {
    const resource = useResource(props.resource_id);
    const {t, i18n} = useClientTranslation();
    const description = useMemo(() => resource?.data?.description_json ? extractDescriptionText(resource.data.description_json, i18n) : null, [resource])

    return (
        <>
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
                    }}><Spinner aria-label='Laster ressurs info'/></div>
                }

                {!resource.isLoading && (<Fragment>
                    {description && (
                        <div>
                            <h4>{t('bookingfrontend.description')}</h4>
                            <div>{parse(unescapeHTML(description))}</div>
                        </div>
                    )}
                    {resource.data?.opening_hours && (
                        <div>
                            <h4>{t('bookingfrontend.description')}</h4>
                            <div>{parse(unescapeHTML(resource.data.opening_hours))}</div>
                        </div>
                    )}
                    {resource.data?.contact_info && (
                        <div>
                            <h4>{t('bookingfrontend.description')}</h4>
                            <div>{parse(unescapeHTML(resource.data.contact_info))}</div>
                        </div>
                    )}
                </Fragment>)}
            </div>
        </>
    );
}

export default ResourceInfoModalContent


