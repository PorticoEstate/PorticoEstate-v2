'use client'
import React, {FC} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useInvoices} from "@/service/hooks/api-hooks";
import {ColumnDef} from "@/components/gs-table/table.types";
import {DateTime} from "luxon";
import {ICompletedReservation} from "@/service/types/api/invoices.types";
import {GSTable} from "@/components/gs-table";

interface InvoicesTableProps {
	initialInvoices?: ICompletedReservation[];
}

const InvoicesTable: FC<InvoicesTableProps> = ({ initialInvoices }) => {
	const t = useTrans();
	const {data: invoices, isLoading} = useInvoices({
		initialData: initialInvoices
	});

	const columns: ColumnDef<ICompletedReservation>[] = [
		{
			id: 'id',
			accessorFn: (row) => row.id,
			header: '#',
			size: 70,
			meta: {
				size: 0.5
			},
			enableHiding: false, // disable hiding for this column
		},
		{
			id: 'description',
			accessorFn: (row) => row.description,
			header: t('bookingfrontend.description'),
			meta: {
				// size: 0.5
			},
			// cell: info => DateTime.fromSQL(info.getValue()).toFormat('dd.MM.yyyy'),
		},
		{
			id: 'from_',
			accessorFn: (row) => row.from_,
			header: t('bookingfrontend.from'),
			meta: {
				// size: 0.5
				defaultHidden: true,
			},
			cell: info => DateTime.fromISO(info.getValue<string>()).toFormat('dd.MM.yyyy HH:mm'),
		},
		{
			id: 'to_',
			accessorFn: (row) => row.to_,
			header: t('bookingfrontend.to'),
			meta: {
				// size: 0.5
				defaultHidden: true,
			},
			cell: info => DateTime.fromISO(info.getValue<string>()).toFormat('dd.MM.yyyy  HH:mm'),
		},
		{
			id: 'customer_organization_number',
			accessorFn: (row) => row.customer_organization_number,
			header: t('bookingfrontend.organization number'),
			cell: info => info.getValue<string>() || '-',
			// meta: {
			//     // size: 1,
			//     // align: 'end'
			// }
		},
		{
			id: 'cost',
			accessorFn: (row) => row.cost,
			header: t('bookingfrontend.cost'),
			// cell: info => {
			//     const status = info.getValue();
			//     return (
			//         <span className={`status-badge status-${status.toLowerCase()}`}>
			//           {t(`bookingfrontend.${status.toLowerCase()}`)}
			//         </span>
			//     );
			// },
		},
		{
			id: 'exported',
			accessorFn: (row) => row.exported,
			header: t('bookingfrontend.invoiced'),
			cell: info => {
				const exported = info.getValue<boolean>();
				return exported ? t('common.yes') : t('common.no');
			},
		},
		//
		// {
		//     id: 'building_name',
		//     accessorFn: (row) => row.building_name,
		//     header: t('bookingfrontend.where'),
		// },
		//
		// {
		//     id: 'resources',
		//     accessorFn: (row) => row.resources,
		//     header: t('bookingfrontend.resources'),
		//     cell: info => {
		//         const resources = info.getValue<any[]>();
		//         return (
		//             <div className="resources-list" style={{display: 'flex', flexDirection: 'column'}}>
		//                 <ResourceCircles resources={resources} maxCircles={4} size={'small'} expandable />
		//             </div>
		//         );
		//     },
		// },
		//
		// {
		//     id: 'dates',
		//     accessorFn: (row) => row.dates,
		//     header: t('bookingfrontend.from'),
		//     cell: info => {
		//         const dates = info.getValue<any[]>();
		//         if (dates.length === 0) return null;
		//
		//         // Sort dates and get earliest from_ date
		//         const earliestDate = dates
		//             .sort((a, b) =>
		//                 DateTime.fromSQL(a.from_).toMillis() -
		//                 DateTime.fromSQL(b.from_).toMillis()
		//             )[0];
		//
		//         return DateTime.fromSQL(earliestDate.from_).toFormat('dd.MM.yyyy HH:mm');
		//     },
		// },
		//
		// {
		//     id: 'contact_name',
		//     accessorFn: (row) => row.contact_name,
		//     header: t('bookingfrontend.contact'),
		// },
	];

	return (
		<GSTable<ICompletedReservation>
			data={invoices || []}
			columns={columns}
			enableSorting={true}
			enableRowSelection
			enableMultiRowSelection
			enableSearch
			utilityHeader={true}
			storageId={'invoicesTable'}
			exportFileName={"Invoices"}
		/>
	);
}

export default InvoicesTable;