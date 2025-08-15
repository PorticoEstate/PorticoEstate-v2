'use client'
import React, {FC, useState, useMemo} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useApplications} from "@/service/hooks/api-hooks";
import {GSTable} from "@/components/gs-table";
import {IApplication, IApplicationDate} from "@/service/types/api/application.types";
import {IShortResource} from "@/service/pecalendar.types";
import {ColumnDef} from "@/components/gs-table/table.types";
import {DateTime} from "luxon";
import ResourceCircles from "@/components/resource-circles/resource-circles";
import {default as NXLink} from "next/link";
import {Link, Checkbox, Button} from "@digdir/designsystemet-react";
import { ArrowsCirclepathIcon, PersonFillIcon, TenancyIcon } from '@navikt/aksel-icons';

interface ApplicationsTableProps {
	initialApplications?: { list: IApplication[], total_sum: number };
}

const ApplicationsTable: FC<ApplicationsTableProps> = ({initialApplications}) => {
	const t = useTrans();
	const [includeOrganizations, setIncludeOrganizations] = useState(true);

	const {data: applicationsRaw, isFetching, refetch} = useApplications({
		initialData: initialApplications,
		includeOrganizations: true
	});

	const filteredApplications = useMemo(() => {
		if (!applicationsRaw?.list) return [];
		if (includeOrganizations) return applicationsRaw.list;
		return applicationsRaw.list.filter(app => app.application_type !== 'organization');
	}, [applicationsRaw?.list, includeOrganizations]);

	const columns: ColumnDef<IApplication>[] = [
		{
			id: 'id',
			accessorFn: (row) => row.id,
			header: '#',
			meta: {
				size: 0.5
			},
			enableHiding: false,
			cell: info => {
				const id = info.getValue<number>();
				return (
					<Link
						asChild
						color={'neutral'}
					>
						<NXLink href={`/user/applications/${id}`}>
							{id}
						</NXLink>
					</Link>
				);
			},
		},

		{
			id: 'dates',
			accessorFn: (row) => row.dates,
			header: t('bookingfrontend.timestamp'),
			sortingFn: (rowA, rowB) => {
				const datesA = rowA.getValue('dates') as IApplicationDate[];
				const datesB = rowB.getValue('dates') as IApplicationDate[];
				
				if (datesA.length === 0 && datesB.length === 0) return 0;
				if (datesA.length === 0) return 1;
				if (datesB.length === 0) return -1;

				// Get earliest date from each row
				const earliestA = datesA
					.sort((a, b) => DateTime.fromISO(a.from_).toMillis() - DateTime.fromISO(b.from_).toMillis())[0];
				const earliestB = datesB
					.sort((a, b) => DateTime.fromISO(a.from_).toMillis() - DateTime.fromISO(b.from_).toMillis())[0];

				return DateTime.fromISO(earliestA.from_).toMillis() - DateTime.fromISO(earliestB.from_).toMillis();
			},
			cell: info => {
				const dates = info.getValue<IApplicationDate[]>();
				if (dates.length === 0) return null;

				// Sort dates and get earliest from_ date for sorting
				const earliestDate = dates
					.sort((a, b) =>
						DateTime.fromISO(a.from_).toMillis() -
						DateTime.fromISO(b.from_).toMillis()
					)[0];
				if(earliestDate) {
					return DateTime.fromISO(earliestDate.from_).toFormat('dd.MM.yyyy HH:mm');
				}
				return null;
			},
			// header: t('bookingfrontend.from'),
			// cell: info => {
			// 	const timestamp = info.getValue<number | null>();
			// 	if (timestamp === null) return null;
			//
			// 	return DateTime.fromMillis(timestamp).toFormat('dd.MM.yyyy HH:mm');
			// },
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
					<span className={`status-badge status-${status.toLowerCase()}`}>
            {t(`bookingfrontend.${status.toLowerCase()}`)}
          </span>
				);
			},
		},

		{
			id: 'building_name',
			accessorFn: (row) => row.building_name,
			header: t('bookingfrontend.place'),
			enableSorting: false,
		},

		{
			id: 'resources',
			accessorFn: (row) => row.resources,
			header: t('bookingfrontend.resources'),
			enableSorting: false,
			meta: {
				toStringEx: (v: any) => v.map((r: any) => r.name)
			},
			cell: info => {
				const resources = info.getValue<IShortResource[]>();
				return (
					<div className="resources-list" style={{display: 'flex', flexDirection: 'column'}}>
						<ResourceCircles resources={resources} maxCircles={4} size={'small'} expandable/>
					</div>
				);
			},
		},

		{
			id: 'customer_organization_number',
			accessorFn: (row) => row.customer_organization_number,
			header: t('bookingfrontend.organization number'),
			cell: info => info.getValue<string | null>() || '-',
			sortingFn: (rowA, rowB) => {
				const a = rowA.original.customer_organization_number;
				const b = rowB.original.customer_organization_number;

				// Handle null values - put them at the end
				if (!a && !b) return 0;
				if (!a) return 1;
				if (!b) return -1;

				// Compare as numbers if they're numeric, otherwise as strings
				const numA = parseInt(a);
				const numB = parseInt(b);

				if (!isNaN(numA) && !isNaN(numB)) {
					return numA - numB;
				}

				return a.localeCompare(b);
			},
			meta: {
				size: 1,
				defaultHidden: true
			}
		},

		{
			id: 'contact_name',
			accessorFn: (row) => row.contact_name,
			header: t('bookingfrontend.contact'),
		},

		{
			id: 'application_type',
			accessorFn: (row) => row.application_type,
			header: t('bookingfrontend.type'),
			meta: {
				size: 0.5,
				filter: {
					type: 'select' as const,
					options: [
						{ label: t('bookingfrontend.personal'), value: 'personal' },
						{ label: t('bookingfrontend.organization'), value: 'organization' }
					]
				}
			},
			cell: info => {
				const applicationType = info.getValue<string>();
				const row = info.row.original;

				if (applicationType === 'organization') {
					return (
						<span className="application-type-badge" title={row.customer_organization_name || t('bookingfrontend.organization')}>
							<TenancyIcon fontSize="1.25rem" />
						</span>
					);
				}
				return (
					<span className="application-type-badge" title={t('bookingfrontend.personal')}>
						<PersonFillIcon fontSize="1.25rem" />
					</span>
				);
			},
		},

		{
			id: 'created',
			accessorFn: (row) => row.created,
			header: t('bookingfrontend.created'),
			meta: {
				size: 0.5
			},
			cell: info => DateTime.fromSQL(info.getValue<string>()).toFormat('dd.MM.yyyy'),
		},
	];

	return (
		<div>
			<GSTable<IApplication>
				data={filteredApplications}
				columns={columns}
				enableSorting={true}
				enableRowSelection
				enableMultiRowSelection
				enableSearch
				enableColumnFilters={true}
				isLoading={isFetching}
				storageId="applications-table"
				defaultColumnVisibility={{
					'customer_organization_number': false
				}}
				utilityHeader={{
					right: (
						<>
							<Checkbox
								checked={includeOrganizations}
								onChange={(e) => setIncludeOrganizations(e.target.checked)}
								label={t('bookingfrontend.show_organizations')}
							>
							</Checkbox>
							<Button
								variant="tertiary"
								data-size="sm"
								onClick={() => refetch()}
								disabled={isFetching}
							>
								<ArrowsCirclepathIcon />
							</Button>
						</>
					)
				}}
				exportFileName={"applications"}
			/>
		</div>
	);
}

export default ApplicationsTable;