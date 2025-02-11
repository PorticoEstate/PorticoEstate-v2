'use client'
import React, {FC} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import PageHeader from "@/components/page-header/page-header";
import {useInvoices} from "@/service/hooks/api-hooks";
import {createColumnHelper} from "@tanstack/table-core";
import {IApplication} from "@/service/types/api/application.types";
import {ColumnDef} from "@/components/gs-table/table.types";
import {DateTime} from "luxon";
import ResourceCircles from "@/components/resource-circles/resource-circles";
import {ICompletedReservation} from "@/service/types/api/invoices.types";
import {GSTable} from "@/components/gs-table";

interface InvoicesProps {
}

const Invoices: FC<InvoicesProps> = (props) => {
    const t = useTrans();
    const {data: invoices, isLoading} = useInvoices();

    const columnHelper = createColumnHelper<ICompletedReservation>();


    const columns: ColumnDef<ICompletedReservation>[] = [
        columnHelper.accessor('id', {
            header: '#',
            size: 70,
            meta: {
                size: 0.5
            },
            enableHiding: false, // disable hiding for this column
        }),

        columnHelper.accessor('description', {
            header: t('bookingfrontend.description'),
            meta: {
                // size: 0.5
            },
            // cell: info => DateTime.fromSQL(info.getValue()).toFormat('dd.MM.yyyy'),
        }),
        columnHelper.accessor('from_', {
            header: t('bookingfrontend.from'),
            meta: {
                // size: 0.5
                defaultHidden: true,
            },
            cell: info => DateTime.fromISO(info.getValue() as string).toFormat('dd.MM.yyyy HH:mm'),
        }),
        columnHelper.accessor('to_', {
            header: t('bookingfrontend.to'),
            meta: {
                // size: 0.5
                defaultHidden: true,
            },
            cell: info => DateTime.fromISO(info.getValue() as string).toFormat('dd.MM.yyyy  HH:mm'),
        }),
        columnHelper.accessor('customer_organization_number', {
            header: t('bookingfrontend.organization number'),
            cell: info => info.getValue() || '-',
            // meta: {
            //     // size: 1,
            //     // align: 'end'
            // }
        }),

        columnHelper.accessor('cost', {
            header: t('bookingfrontend.cost'),
            // cell: info => {
            //     const status = info.getValue();
            //     return (
            //         <span className={`status-badge status-${status.toLowerCase()}`}>
            //           {t(`bookingfrontend.${status.toLowerCase()}`)}
            //         </span>
            //     );
            // },
        }),
        columnHelper.accessor('exported', {
            header: 'Fakturert',
            cell: info => {
                const exported = info.getValue();
                return exported ? t('common.yes') : t('common.no');
            },
        }),
        //
        // columnHelper.accessor('building_name', {
        //     header: t('bookingfrontend.where'),
        // }),
        //
        // columnHelper.accessor('resources', {
        //     header: t('bookingfrontend.resources'),
        //     cell: info => {
        //         const resources = info.getValue();
        //         return (
        //             <div className="resources-list" style={{display: 'flex', flexDirection: 'column'}}>
        //                 <ResourceCircles resources={resources} maxCircles={4} size={'small'} expandable />
        //             </div>
        //         );
        //     },
        // }),
        //
        // columnHelper.accessor('dates', {
        //     header: t('bookingfrontend.from'),
        //     cell: info => {
        //         const dates = info.getValue();
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
        // }),
        //
        //
        //
        // columnHelper.accessor('contact_name', {
        //     header: t('bookingfrontend.contact'),
        // }),
    ];

    // console.log(invoices);
    return (
        <main>
            <GSTable<ICompletedReservation>
                data={invoices || []}
                columns={columns}
                enableSorting={true}
                // renderRowButton={(delegate) => (
                //     <Button asChild variant="tertiary">
                //         <Link
                //             href={phpGWLink('bookingfrontend/', {menuaction: 'bookingfrontend.uiorganization.show', id: delegate.org_id}, false)}
                //             className="link-text link-text-unset normal" target={'_blank'}
                //
                //         >
                //             Vis
                //         </Link>
                //     </Button>
                //
                // )}
                enableRowSelection
                enableMultiRowSelection
                // onSelectionChange={(e) => console.log(e)}
                enableSearch
                // searchPlaceholder="Search users..."
                // onSearchChange={(value) => {
                //     console.log('Search term:', value);
                // }}
                utilityHeader={true}
                storageId={'invoicesTable'}
                exportFileName={"Invoices"}
                // selectedRows={selectedRows}
                // renderExpandedContent={(user) => (
                //     <div className="p-4 bg-gray-50">
                //         <h3 className="font-bold mb-2">User Details</h3>
                //         <p>ID: {user.id}</p>
                //         <p>Email: {user.email}</p>
                //         <p>Role: {user.role}</p>
                //         <p>Posts: {user.posts}</p>
                //         <p>Status: {user.status}</p>
                //         <p>Last Login: {user.lastLogin.toLocaleString()}</p>
                //     </div>
                // )}
            />
        </main>
    );
}

export default Invoices


