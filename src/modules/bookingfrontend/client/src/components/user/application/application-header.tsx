'use client';
import React, {FC, ReactNode} from 'react';
import Link from 'next/link';
import {Button, Heading, Tag} from '@digdir/designsystemet-react';
import {ArrowLeftIcon, Buildings3Icon, CalendarIcon, DoorIcon, FilesIcon} from '@navikt/aksel-icons';
import {useClientTranslation} from '@/app/i18n/ClientTranslationProvider';
import {IApplication, IRecurringSeries, RecurringInfoUtils} from '@/service/types/api/application.types';
import {applicationWhen} from '@/utils/application-when';
import {getStatusColor} from './status-utils';
import styles from './application-header.module.scss';

const FACT_ICON_SIZE = '1.125rem';

interface ApplicationHeaderProps {
    application: IApplication;
    /** Shown inside a modal over the calendar, which has its own back link and title: neither is shown here. */
    embedded: boolean;
    /** The parent of a combined application. */
    isParentApplication: boolean;
    /** The whole series of a recurring application, once it has loaded. */
    series?: IRecurringSeries;
    /** A recurring application whose series is still being read: no date is shown until it is, rather than the ONE stored one. */
    seriesLoading: boolean;
    /** Given when the application can be copied (an accepted, rejected or cancelled one). */
    onCopy?: () => void;
}

/**
 * The top of the application page (GH #1423), as the Min side design has it: «← Tilbake til søknader», «Søknad 84278 ·
 * Personlig», the title, and a status row in larger text: the status, the building, WHEN («Onsdag 23. desember 2026,
 * 08:00–16:00»; with several dates the earliest and «+N datoer») and the number of rooms.
 *
 * The whole-application cancel is NOT offered up here (GH #1392): as the first action it was the one a user reached for
 * when they meant a single time, and lost the whole series. It lives in its own card at the end of the page.
 */
const ApplicationHeader: FC<ApplicationHeaderProps> = ({application, embedded, isParentApplication, series, seriesLoading, onCopy}) => {
    const {t, i18n} = useClientTranslation();
    const isOrg = application.application_type === 'organization';

    // A series is listed by its occurrences, any other application by its stored dates.
    const dates = series ? series.occurrences : application.dates;
    const when = applicationWhen(dates, i18n.language);
    const dateWord = (count: number) => t(count === 1 ? 'bookingfrontend.date' : 'bookingfrontend.num_dates');

    const roomCount = application.resources.length;
    const rooms = roomCount === 1
        ? t('bookingfrontend.application_rooms_one')
        : t('bookingfrontend.application_rooms').replace('%1', String(roomCount));

    const facts: Array<{key: string; node: ReactNode}> = [
        {
            key: 'status',
            node: (
                <Tag data-color={getStatusColor(application.status)} data-size="md">
                    {t(`bookingfrontend.${application.status.toLowerCase()}`)}
                </Tag>
            ),
        },
        {
            key: 'building',
            node: <><Buildings3Icon className={styles.factIcon} fontSize={FACT_ICON_SIZE} aria-hidden="true"/>{application.building_name}</>,
        },
    ];
    if (!seriesLoading) {
        facts.push({
            key: 'when',
            node: (
                <>
                    <CalendarIcon className={styles.factIcon} fontSize={FACT_ICON_SIZE} aria-hidden="true"/>
                    <span>
                        {when ? when.text : `${dates.length} ${dateWord(dates.length)}`}
                        {when && when.moreDates > 0 && <span className={styles.more}> +{when.moreDates} {dateWord(when.moreDates)}</span>}
                    </span>
                </>
            ),
        });
    }
    if (roomCount > 0) {
        facts.push({
            key: 'rooms',
            node: <><DoorIcon className={styles.factIcon} fontSize={FACT_ICON_SIZE} aria-hidden="true"/>{rooms}</>,
        });
    }
    if (RecurringInfoUtils.isRecurring(application)) {
        facts.push({key: 'recurring', node: <Tag data-size="sm" data-color="neutral">{t('bookingfrontend.recurring_application')}</Tag>});
    }
    if (series) {
        facts.push({
            key: 'interval',
            node: series.interval_weeks === 1
                ? t('bookingfrontend.every_week')
                : t('bookingfrontend.every_n_weeks', {n: series.interval_weeks}),
        });
    }

    return (
        <header className={styles.header}>
            {!embedded && (
                <Link href="/user/applications" className={styles.backLink}>
                    <ArrowLeftIcon fontSize="1rem" aria-hidden="true"/>
                    {t('bookingfrontend.back_to_applications')}
                </Link>
            )}

            <div className={styles.top}>
                <div className={styles.titleBlock}>
                    <p className={styles.meta}>
                        {t('booking.application')} {application.id_string} &middot; {isOrg ? application.customer_organization_name : t('bookingfrontend.personal')}
                    </p>
                    {!embedded && <Heading level={1} className={styles.title}>{application.name}</Heading>}
                    {isParentApplication && (
                        <Tag data-size="sm" data-color="info">{t('bookingfrontend.combined_application')}</Tag>
                    )}
                </div>
                {onCopy && (
                    <div className={styles.actions}>
                        <Button variant="secondary" data-size="sm" onClick={onCopy}>
                            <FilesIcon fontSize="0.9rem"/>
                            {t('bookingfrontend.copy_application')}
                        </Button>
                    </div>
                )}
            </div>

            <ul className={styles.facts}>
                {facts.map(fact => <li key={fact.key} className={styles.fact}>{fact.node}</li>)}
            </ul>
        </header>
    );
};

export default ApplicationHeader;
