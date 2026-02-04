import React, {FC} from 'react';
import {Card, Heading, Link as DigdirLink} from '@digdir/designsystemet-react';
import styles from './event-result-item.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {CalendarIcon, LocationPinIcon, TenancyIcon, PersonFillIcon} from "@navikt/aksel-icons";
import {format} from 'date-fns';
import {nb, enUS} from 'date-fns/locale';
import Link from 'next/link';

import {IShortEvent} from "@/service/pecalendar.types";
import {useSearchData} from "@/service/hooks/api-hooks";
import BuildingIcon from "@/icons/BuildingIcon";

interface EventResultItemProps {
    event: IShortEvent;
}

const EventResultItem: FC<EventResultItemProps> = ({event}) => {
    const t = useTrans();
    const {data: searchData} = useSearchData();

    // Parse dates
    const fromDate = new Date(event.from_);
    const toDate = new Date(event.to_);
    const currentYear = new Date().getFullYear();

    // Format date/time according to specifications
    const formatDateTime = () => {
        const locale = nb; // Assuming Norwegian locale based on format example

        // Same day
        if (fromDate.toDateString() === toDate.toDateString()) {
            const dayMonth = format(fromDate, 'd. MMM', { locale });
            const fromTime = format(fromDate, 'HH:mm', { locale });
            const toTime = format(toDate, 'HH:mm', { locale });
            const year = fromDate.getFullYear() !== currentYear ? ` ${fromDate.getFullYear()}` : '';
            return `${dayMonth}${year} kl ${fromTime}-${toTime}`;
        }

        // Different days
        const fromDayMonth = format(fromDate, 'd. MMM', { locale });
        const toDayMonth = format(toDate, 'd. MMM', { locale });
        const fromTime = format(fromDate, 'HH:mm', { locale });
        const toTime = format(toDate, 'HH:mm', { locale });
        const fromYear = fromDate.getFullYear() !== currentYear ? ` ${fromDate.getFullYear()}` : '';
        const toYear = toDate.getFullYear() !== currentYear && toDate.getFullYear() !== fromDate.getFullYear() ? ` ${toDate.getFullYear()}` : '';

        return `${fromDayMonth}${fromYear} kl. ${fromTime} - ${toDayMonth}${toYear} kl. ${toTime}`;
    };

    // Get district name from search data
    const getDistrictName = () => {
        if (!searchData?.buildings || !searchData?.towns) return '';

        const building = searchData.buildings.find(b => b.id === event.building_id);
        if (!building?.town_id) return '';

        const town = searchData.towns.find(t => t.id === building.town_id);
        return town?.name || '';
    };

    const organizerName = event.customer_organization_name || event.organizer || '';
    const districtName = getDistrictName();

    return (
        <Card
            data-color="neutral"
            className={styles.eventCard}
        >
            <div className={styles.cardContent}>
                {/* Event Title */}
                <Heading level={3} data-size="sm" className={styles.eventTitle}>
                    {event.name}
                </Heading>

                {/* Building */}
                <div className={styles.eventDetail}>
                    <BuildingIcon className={styles.detailIcon} />
                    <DigdirLink asChild data-color="brand1">
                        <Link href={`/building/${event.building_id}`}>
                            <span className={styles.detailText}>{event.building_name}</span>
                        </Link>
                    </DigdirLink>
                </div>

                {/* Date/Time */}
                <div className={styles.eventDetail}>
                    <CalendarIcon className={styles.detailIcon} />
                    <span className={styles.detailText}>{formatDateTime()}</span>
                </div>

                {/* Organization/Organizer (if available) */}
                {organizerName && (
                    <div className={styles.eventDetail}>
                        {event.customer_organization_id ? (
                            <>
                                <TenancyIcon className={styles.detailIcon} />
                                <DigdirLink asChild data-color="brand1">
                                    <Link href={`/organization/${event.customer_organization_id}`}>
                                        <span className={styles.detailText}>{organizerName}</span>
                                    </Link>
                                </DigdirLink>
                            </>
                        ) : (
                            <>
                                <PersonFillIcon className={styles.detailIcon} />
                                <span className={styles.detailText}>{organizerName}</span>
                            </>
                        )}
                    </div>
                )}

                {/* District (if available) */}
                {districtName && (
                    <div className={styles.eventDetail}>
                        <LocationPinIcon className={styles.detailIcon} />
                        <span className={styles.detailText}>{districtName}</span>
                    </div>
                )}
            </div>
        </Card>
    );
};

export default EventResultItem;