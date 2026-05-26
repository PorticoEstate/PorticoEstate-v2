'use client'
import React, {FC, useMemo, useCallback} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useApplications} from "@/service/hooks/api-hooks";
import {IApplication, IApplicationDate} from "@/service/types/api/application.types";
import {ColumnDef} from "@/components/gs-table/table.types";
import {GSTable} from "@/components/gs-table";
import {DateTime} from "luxon";
import ResourceCircles from "@/components/resource-circles/resource-circles";
import {default as NXLink} from "next/link";
import {Button, Heading, Link, Tag, Spinner} from "@digdir/designsystemet-react";
import {
    ArrowCirclepathIcon,
    ArrowsCirclepathIcon,
    PersonFillIcon,
    TenancyIcon,
    PlusIcon,
    MagnifyingGlassIcon,
} from '@navikt/aksel-icons';
import {useSearchParams, useRouter, usePathname} from 'next/navigation';
import {getStatusColor, FilterKey} from './status-utils';
import {ENABLE_COMBINED_APPLICATIONS} from '@/service/feature-flags';
import styles from './applications-table.module.scss';

interface ApplicationsTableProps {
    initialApplications?: { list: IApplication[], total_sum: number };
}

interface FilterCounts {
    all: number;
    new: number;
    pending: number;
    accepted: number;
    rejected: number;
    cancelled: number;
}

const FILTER_KEYS: FilterKey[] = ['all', 'new', 'pending', 'accepted', 'rejected', 'cancelled'];

const ApplicationsTable: FC<ApplicationsTableProps> = ({initialApplications}) => {
    const t = useTrans();
    const router = useRouter();
    const pathname = usePathname();
    const searchParams = useSearchParams();

    // URL-synced filter state
    const currentFilter = (searchParams.get('filter') as FilterKey) || 'all';

    const setFilter = useCallback((key: FilterKey) => {
        const params = new URLSearchParams(searchParams.toString());
        if (key === 'all') {
            params.delete('filter');
        } else {
            params.set('filter', key);
        }
        router.replace(`${pathname}?${params.toString()}`, {scroll: false});
    }, [searchParams, router, pathname]);

    const {data: applicationsRaw, isFetching, refetch} = useApplications({
        initialData: initialApplications,
        includeOrganizations: true,
    });

    const applications = applicationsRaw?.list || [];

    // When combined applications are enabled, hide child applications
    const visibleApplications = useMemo(() => {
        if (!ENABLE_COMBINED_APPLICATIONS) return applications;
        return applications.filter(a => !a.parent_id || a.parent_id === a.id);
    }, [applications]);

    // Compute counts from the full (unfiltered) list
    const counts: FilterCounts = useMemo(() => {
        const c: FilterCounts = {all: 0, new: 0, pending: 0, accepted: 0, rejected: 0, cancelled: 0};
        c.all = visibleApplications.length;
        visibleApplications.forEach(a => {
            const s = a.status.toLowerCase() as keyof FilterCounts;
            if (s in c) c[s]++;
        });
        return c;
    }, [visibleApplications]);

    // Pre-filter by chip selection (GSTable handles its own search separately)
    const filtered = useMemo(() => {
        if (currentFilter === 'all') return visibleApplications;
        return visibleApplications.filter(a => a.status.toLowerCase() === currentFilter);
    }, [visibleApplications, currentFilter]);

    // Column definitions for GSTable
    const columns: ColumnDef<IApplication>[] = useMemo(() => [
        {
            id: 'application_type',
            accessorFn: (row) => row.application_type,
            header: '',
            enableSorting: false,
            enableHiding: false,
            meta: {size: 'icon' as const, hideHeader: true},
            cell: info => {
                const type = info.getValue<string>();
                const row = info.row.original;
                const isOrg = type === 'organization';
                return (
                    <span
                        className={`${styles.typeIcon} ${!isOrg ? styles.personal : ''}`}
                        title={isOrg ? (row.customer_organization_name || t('bookingfrontend.organization')) : t('bookingfrontend.personal')}
                    >
                        {isOrg ? <TenancyIcon fontSize="1.25rem"/> : <PersonFillIcon fontSize="1.25rem"/>}
                    </span>
                );
            },
        },
        {
            id: 'name',
            accessorFn: (row) => row.name,
            header: t('bookingfrontend.application'),
            meta: {size: 2},
            enableHiding: false,
            cell: info => {
                const name = info.getValue<string>();
                const app = info.row.original;
                const isOrg = app.application_type === 'organization';
                const isRecurring = !!app.recurring_info;
                const displayName = name
                    || `${app.building_name} — ${app.resources?.map(r => r.name).join(', ') || app.id_string}`;
                return (
                    <div style={{minWidth: 0, flexDirection: 'column', alignItems: 'flex-start'}}>
                        <span style={{display: 'inline-flex', alignItems: 'center', gap: 6}}>
                            <Link asChild color="neutral">
                                <NXLink href={`/user/applications/${app.id}`}>
                                    <span className={styles.appTitle} title={displayName}>{displayName}</span>
                                </NXLink>
                            </Link>
                            {isRecurring && (
                                <ArrowCirclepathIcon
                                    fontSize="0.9rem"
                                    title={t('bookingfrontend.repeating')}
                                    style={{color: 'var(--ds-color-accent-base-default)', flexShrink: 0}}
                                />
                            )}
                        </span>
                        <span className={styles.appSubtitle}>
                            <span>{isOrg ? app.customer_organization_name : t('bookingfrontend.personal')}</span>
                            <span>&middot;</span>
                            <span>{app.id_string}</span>
                            {app.dates.length > 1 && (
                                <>
                                    <span>&middot;</span>
                                    <span>{app.dates.length} {t('bookingfrontend.num_dates')}</span>
                                </>
                            )}
                        </span>
                    </div>
                );
            },
        },
        {
            id: 'dates',
            accessorFn: (row) => row.dates,
            header: t('bookingfrontend.first_date'),
            sortingFn: (rowA, rowB) => {
                const datesA = rowA.getValue('dates') as IApplicationDate[];
                const datesB = rowB.getValue('dates') as IApplicationDate[];
                if (datesA.length === 0 && datesB.length === 0) return 0;
                if (datesA.length === 0) return 1;
                if (datesB.length === 0) return -1;
                const earliestA = [...datesA].sort((a, b) => DateTime.fromISO(a.from_).toMillis() - DateTime.fromISO(b.from_).toMillis())[0];
                const earliestB = [...datesB].sort((a, b) => DateTime.fromISO(a.from_).toMillis() - DateTime.fromISO(b.from_).toMillis())[0];
                return DateTime.fromISO(earliestA.from_).toMillis() - DateTime.fromISO(earliestB.from_).toMillis();
            },
            cell: info => {
                const dates = info.getValue<IApplicationDate[]>();
                if (dates.length === 0) return <span style={{color: 'var(--ds-color-neutral-text-subtle)'}}>—</span>;
                const earliest = [...dates].sort((a, b) => DateTime.fromISO(a.from_).toMillis() - DateTime.fromISO(b.from_).toMillis())[0];
                const dt = DateTime.fromISO(earliest.from_);
                return (
                    <div style={{flexDirection: 'column', alignItems: 'flex-start'}}>
                        <span>{dt.toFormat('dd.MM.yyyy')}</span>
                        <span style={{fontSize: 13, color: 'var(--ds-color-neutral-text-subtle)'}}>kl. {dt.toFormat('HH:mm')}</span>
                    </div>
                );
            },
        },
        {
            id: 'building_name',
            accessorFn: (row) => row.building_name,
            header: t('bookingfrontend.place'),
            enableSorting: false,
            cell: info => {
                const building = info.getValue<string>();
                const resources = info.row.original.resources;
                return (
                    <div style={{minWidth: 0, flexDirection: 'column', alignItems: 'flex-start'}}>
                        <span style={{fontSize: 14}}>{building}</span>
                        {resources.length > 0 && (
                            <span style={{marginTop: 4}}>
                                <ResourceCircles resources={resources} maxCircles={3} size="small"/>
                            </span>
                        )}
                    </div>
                );
            },
        },
        {
            id: 'status',
            accessorFn: (row) => row.status,
            header: t('bookingfrontend.status'),
            meta: {
                size: 0.5,
                filter: {
                    type: 'select' as const,
                    getUniqueValues: (data: IApplication[]) => {
                        const uniqueStatuses = Array.from(new Set(data.map(app => app.status)));
                        return uniqueStatuses.map(status => ({
                            label: t(`bookingfrontend.${status.toLowerCase()}`),
                            value: status
                        }));
                    }
                }
            },
            cell: info => {
                const status = info.getValue<string>();
                return (
                    <Tag data-color={getStatusColor(status)} data-size="sm">
                        {t(`bookingfrontend.${status.toLowerCase()}`)}
                    </Tag>
                );
            },
        },
        {
            id: 'created',
            accessorFn: (row) => row.created,
            header: t('bookingfrontend.sent'),
            meta: {size: 0.5},
            cell: info => DateTime.fromSQL(info.getValue<string>()).toFormat('dd.MM.yyyy'),
        },
    ], [t]);

    return (
        <div>
            {/* Page header */}
            <div className={styles.pageHeader}>
                <div>
                    <Heading level={1} data-size="lg">{t('bookingfrontend.applications')}</Heading>
                    <p className={styles.subtitle}>
                        {t('bookingfrontend.applications_description')}
                    </p>
                </div>
                <Button variant="primary" data-size="sm" asChild>
                    <NXLink href="/user/applications/new">
                        <PlusIcon fontSize="1.1rem"/>
                        {t('bookingfrontend.new application')}
                    </NXLink>
                </Button>
            </div>

            {/* Quick stats */}
            <div className={styles.quickStats}>
                <div className={styles.quickStat}>
                    <span className={`${styles.num} ${styles.accent}`}>{counts.all}</span>
                    <span className={styles.lab}>{t('bookingfrontend.total')}</span>
                </div>
                <div className={styles.quickStat}>
                    <span className={`${styles.num} ${styles.warning}`}>{counts.new + counts.pending}</span>
                    <span className={styles.lab}>{t('bookingfrontend.waiting_for_response')}</span>
                </div>
                <div className={styles.quickStat}>
                    <span className={`${styles.num} ${styles.success}`}>{counts.accepted}</span>
                    <span className={styles.lab}>{t('bookingfrontend.accepted')}</span>
                </div>
                <div className={styles.quickStat}>
                    <span className={styles.num}>{counts.rejected + counts.cancelled}</span>
                    <span className={styles.lab}>{t('bookingfrontend.finished')}</span>
                </div>
            </div>

            {/* Chip filters */}
            <div className={styles.chipRow}>
                {FILTER_KEYS.map(key => (
                    <button
                        key={key}
                        className={styles.chip}
                        aria-pressed={currentFilter === key}
                        onClick={() => setFilter(key)}
                    >
                        {t(`bookingfrontend.${key}`)}
                        <span className={styles.count}>{counts[key]}</span>
                    </button>
                ))}
            </div>

            {/* GSTable with built-in search, sorting, pagination */}
            <GSTable<IApplication>
                data={filtered}
                columns={columns}
                enableSorting={true}
                enableSearch
                searchPlaceholder={t('bookingfrontend.search_applications_placeholder')}
                enableColumnFilters={true}
                isLoading={isFetching}
                storageId="applications-table"
                defaultColumnVisibility={{}}
                utilityHeader={{
                    right: (
                        <Button
                            variant="tertiary"
                            data-size="sm"
                            onClick={() => refetch()}
                            disabled={isFetching}
                        >
                            <ArrowsCirclepathIcon/>
                        </Button>
                    )
                }}
                exportFileName="applications"
            />
        </div>
    );
};

export default ApplicationsTable;
