'use client'
import React, {useState, useRef, useEffect, PropsWithChildren} from 'react';
import styles from './collapsible-text.module.scss';
import {Button} from "@digdir/designsystemet-react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faChevronDown, faChevronUp} from "@fortawesome/free-solid-svg-icons";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";

/**
 * Props for the CollapsibleText component.
 */
interface CollapsibleTextProps extends PropsWithChildren {
}

/**
 * CollapsibleText component displays text content that can be expanded or collapsed.
 *
 * @param {CollapsibleTextProps} props - The props for the component.
 * @returns {JSX.Element} The rendered component.
 */
const CollapsibleText: React.FC<CollapsibleTextProps> = ({children}) => {
    const t = useTrans();


    // State to track whether the description is expanded or collapsed.
    const [descriptionExpanded, setDescriptionExpanded] = useState<boolean>(false);

    // Ref to the content element to measure its size.
    const contentElement = useRef<HTMLDivElement | null>(null);

    // State to determine if the toggle button should be active.
    const [isActive, setIsActive] = useState<boolean>(false);

    /**
     * Toggles the expanded state of the description.
     */
    const toggleDescription = () => {
        setDescriptionExpanded((prevState) => !prevState);
    };

    /**
     * Effect to update the isActive state based on content overflow.
     * It checks if the content overflows its container to decide if the toggle button should be shown.
     */
    useEffect(() => {
        const elem = contentElement.current;
        if (elem) {
            const isOverflowing =
                elem.scrollHeight > elem.clientHeight || elem.scrollWidth > elem.clientWidth;
            setIsActive(isOverflowing);
        } else {
            setIsActive(false);
        }
    }, [children, contentElement]);


    return (
        <div>
            <div
                className={`${styles.collapsibleContent} ${!descriptionExpanded ? styles.collapsedDescription : ''} ${!descriptionExpanded && isActive ? styles.collapsedDescriptionFade : ''}`}
                ref={contentElement}>
                {children}
            </div>
            {/* Show the toggle button only if the content overflows and isActive is true */}
            {isActive && (
                <Button className={'default text-label'} variant={'tertiary'} data-size={'sm'} color={'neutral'}
                        onClick={toggleDescription}>
                    {descriptionExpanded ? (
                        <span>{t('bookingfrontend.show_less')}</span>
                    ) : (
                        <span>{t('bookingfrontend.show_more')}</span>
                    )}
                    <FontAwesomeIcon icon={descriptionExpanded ? faChevronUp : faChevronDown}/>
                </Button>
            )}
        </div>
    );
};

export default CollapsibleText;
