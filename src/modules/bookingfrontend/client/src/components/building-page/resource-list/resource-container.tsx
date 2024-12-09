'use client'
import React, {FC, PropsWithChildren, useEffect, useRef, useState} from 'react';
import styles from "@/components/building-page/resource-list/building-resources.module.scss";
import {Button} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faChevronDown, faChevronUp} from "@fortawesome/free-solid-svg-icons";

interface ResourceContainerProps extends PropsWithChildren {
}

const ResourceContainer: FC<ResourceContainerProps> = (props) => {
    const [expanded, setExpanded] = useState<boolean>(false);
    const [showMoreButton, setShowMoreButton] = useState<boolean>(false);
    const containerRef = useRef<HTMLDivElement | null>(null);
    const t = useTrans();
    useEffect(() => {
        if (containerRef.current) {
            const {scrollHeight, clientHeight} = containerRef.current;
            setShowMoreButton(scrollHeight > clientHeight);
        }
    }, [containerRef]);

    const toggleExpand = () => {
        setExpanded(!expanded);
    };
    return (
        <>
            <div className={`mb-1 ${styles.resourcesHeader}`}>
                <h3>
                    {t('bookingfrontend.rental_resources')}
                </h3>
                {showMoreButton && ( <Button className={'default text-label'} variant={'tertiary'} data-size={'sm'} color={'neutral'}
                                             onClick={toggleExpand}>
                    {expanded ? (
                        <span>{t('bookingfrontend.show_less')}</span>
                    ) : (
                        <span>{t('bookingfrontend.show_more')}</span>
                    )}
                    <FontAwesomeIcon icon={expanded ? faChevronUp : faChevronDown}/>
                </Button>)}
            </div>
            <div className={`${styles.resourcesContainer} ${expanded ? styles.expanded : ''}`} ref={containerRef}>
                {props.children}

            </div>
        </>

    );
}

export default ResourceContainer


