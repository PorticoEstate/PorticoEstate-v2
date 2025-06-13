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
import { ArrowsCirclepathIcon } from '@navikt/aksel-icons';

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
			id: 'created',
			accessorFn: (row) => row.created,
			header: t('bookingfrontend.date'),
			meta: {
				size: 0.5
			},
			cell: info => DateTime.fromSQL(info.getValue<string>()).toFormat('dd.MM.yyyy'),
		},

		{
			id: 'status',
			accessorFn: (row) => row.status,
			header: t('bookingfrontend.status'),
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
			id: 'application_type',
			accessorFn: (row) => row.application_type,
			header: t('bookingfrontend.type'),
			meta: {
				size: 0.5
			},
			cell: info => {
				const applicationType = info.getValue<string>();
				const row = info.row.original;

				if (applicationType === 'organization') {
					return (
						<span className="application-type-badge" title={row.customer_organization_name || 'Organization'}>
							{t('bookingfrontend.organization')}
						</span>
					);
				}
				return (
					<span className="application-type-badge">
						{t('bookingfrontend.personal')}
					</span>
				);
			},
		},

		{
			id: 'building_name',
			accessorFn: (row) => row.building_name,
			header: t('bookingfrontend.where'),
		},

		{
			id: 'resources',
			accessorFn: (row) => row.resources,
			header: t('bookingfrontend.resources'),
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
			id: 'dates',
			accessorFn: (row) => row.dates,
			header: t('bookingfrontend.from'),
			cell: info => {
				const dates = info.getValue<IApplicationDate[]>();
				if (dates.length === 0) return null;

				// Sort dates and get earliest from_ date
				const earliestDate = dates
					.sort((a, b) =>
						DateTime.fromISO(a.from_).toMillis() -
						DateTime.fromISO(b.from_).toMillis()
					)[0];

				return DateTime.fromISO(earliestDate.from_).toFormat('dd.MM.yyyy HH:mm');
			},
		},

		{
			id: 'customer_organization_number',
			accessorFn: (row) => row.customer_organization_number,
			header: t('bookingfrontend.organization number'),
			cell: info => info.getValue<string | null>() || '-',
			meta: {
				size: 1
			}
		},

		{
			id: 'contact_name',
			accessorFn: (row) => row.contact_name,
			header: t('bookingfrontend.contact'),
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
				isLoading={isFetching}
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