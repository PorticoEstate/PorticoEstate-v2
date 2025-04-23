import React, {FC} from 'react';
import {Card, Heading, Paragraph, Link as DigdirLink} from '@digdir/designsystemet-react';
import styles from './event-result-item.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {CalendarIcon} from "@navikt/aksel-icons";
import Link from "next/link";
import DividerCircle from "@/components/util/DividerCircle";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {format} from 'date-fns';
import {nb, enUS} from 'date-fns/locale';
import {useRouter} from 'next/navigation';

import {IShortEvent} from "@/service/pecalendar.types";
import {formatDateRange, formatDateStamp} from "@/service/util";

interface EventResultItemProps {
    event: IShortEvent;
}

const EventResultItem: FC<EventResultItemProps> = ({event}) => {
    const t = useTrans();
    const isMobile = useIsMobile();

    const eventName = event.name;
    const locationName = event.building_name;
    const organizerName = event.customer_organization_name || event.organizer || '';

    // Parse dates
    const fromDate = new Date(event.from_);
    const toDate = new Date(event.to_);

    const displayDateRange = () => {
		return formatDateRange(fromDate, toDate, true);
        // Same day
        // if (fromDate.toDateString() === toDate.toDateString()) {
        //     return `${formatDate(fromDate)}, ${formatTime(fromDate)} - ${formatTime(toDate)}`;
        // }
        // // Different days
        // return `${formatDate(fromDate)} ${formatTime(fromDate)} - ${formatDate(toDate)} ${formatTime(toDate)}`;
    };

    const tags = [
        locationName,
        organizerName,
        displayDateRange()
    ].filter(Boolean);

    return (
        <Card
            data-color="neutral"
            className={styles.eventCard}
        >
            <div className={styles.cardContent}>
                {/*<DigdirLink asChild data-color='accent'>*/}
                {/*    <Link href={`/event/${event.id}`} className={styles.titleLink}>*/}
                        <div className={styles.eventHeadingContainer}>
                            <Heading level={3} data-size="xs" className={styles.eventIcon}>
                                <CalendarIcon fontSize="1em"/>
                            </Heading>
                            <Heading level={3} data-size="xs" className={styles.eventTitle}>
                                {eventName}
                            </Heading>
                        </div>
                    {/*</Link>*/}
                {/*</DigdirLink>*/}

                <Paragraph data-size={isMobile ? 'xs' : "sm"} className={styles.eventTags}>
                    {tags.map((tag, index) => {
                        if (index === 0) {
                            return <span key={'tag' + index}>{tag}</span>
                        }
                        return <React.Fragment key={'tag' + index}>
                            <DividerCircle/>{tag}</React.Fragment>
                    })}
                </Paragraph>
            </div>
        </Card>
    );
};

export default EventResultItem;