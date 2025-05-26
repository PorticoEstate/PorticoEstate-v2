'use client'
import React, {FC} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useApplications} from "@/service/hooks/api-hooks";
import {GSTable} from "@/components/gs-table";
import {IApplication} from "@/service/types/api/application.types";
import {ColumnDef} from "@/components/gs-table/table.types";
import {createColumnHelper} from "@tanstack/table-core";
import {DateTime} from "luxon";
import ResourceCircles from "@/components/resource-circles/resource-circles";
import {default as NXLink} from "next/link";
import {phpGWLink} from "@/service/util";
import {Link} from "@digdir/designsystemet-react";
import {IResource} from "@/service/types/resource.types";

interface ApplicationsProps {
}

// interface ITableApplication extends Pick<IApplication, 'id'> {
//     from: string;
// }

const Applications: FC<ApplicationsProps> = (props) => {
    const t = useTrans();
    const {data: applicationsRaw, isLoading} = useApplications();

    // const applicatiuons = useMemo<ITableApplication[]>(() => {
    //     if(!applicationsRaw) {
    //         return []
    //     }
    //     return applicationsRaw?.list.map<ITableApplication>((app) => ({id:app.id, from: app.dates.find()}))
    //
    // }, [applicationsRaw])


    const columnHelper = createColumnHelper<IApplication>();


    const columns: ColumnDef<IApplication>[] = [
        columnHelper.accessor('id', {
            header: '#',
            size: 70,
            meta: {
                size: 0.5
            },
            enableHiding: false, // disable hiding for this column
            cell: info => {
                const id = info.getValue();
                const secret = info.row.original.secret;
                return (
					<Link
						asChild
						color={'neutral'}
					>
						<NXLink href={phpGWLink('bookingfrontend/', {
							menuaction: 'bookingfrontend.uiapplication.show',
							id: id,
							secret: secret || ''
						}, false)}
								target={'_blank'}
						>
							{id}
						</NXLink>
					</Link>
                );
            },
        }),

        columnHelper.accessor('created', {
            header: t('bookingfrontend.date'),
            meta: {
                size: 0.5
            },
            cell: info => DateTime.fromSQL(info.getValue()).toFormat('dd.MM.yyyy'),
        }),

        columnHelper.accessor('status', {
            header: t('bookingfrontend.status'),
            cell: info => {
                const status = info.getValue();
                return (
                    <span className={`status-badge status-${status.toLowerCase()}`}>
                      {t(`bookingfrontend.${status.toLowerCase()}`)}
                    </span>
                );
            },
        }),

        columnHelper.accessor('building_name', {
            header: t('bookingfrontend.where'),
        }),

        columnHelper.accessor('resources', {
            header: t('bookingfrontend.resources'),
            meta: {
                toStringEx: (v: IResource[]) => v.map(r => r.name)
            },
            cell: info => {
                const resources = info.getValue();
                return (
                    <div className="resources-list" style={{display: 'flex', flexDirection: 'column'}}>
                        <ResourceCircles resources={resources} maxCircles={4} size={'small'} expandable/>
                    </div>
                );
            },
        }),

        columnHelper.accessor('dates', {
            header: t('bookingfrontend.from'),
            cell: info => {
                const dates = info.getValue();
                // console.log(dates);
                if (dates.length === 0) return null;

                // Sort dates and get earliest from_ date
                const earliestDate = dates
                    .sort((a, b) =>
                        DateTime.fromISO(a.from_).toMillis() -
                        DateTime.fromISO(b.from_).toMillis()
                    )[0];

                return DateTime.fromISO(earliestDate.from_).toFormat('dd.MM.yyyy HH:mm');
            },
        }),

        columnHelper.accessor('customer_organization_number', {
            header: t('bookingfrontend.organization number'),
            cell: info => info.getValue() || '-',
            meta: {
                size: 1,
                // align: 'end'
            }
        }),

        columnHelper.accessor('contact_name', {
            header: t('bookingfrontend.contact'),
        }),
    ];

    // const columns: ColumnDef<IApplication>[] = [
    //     {
    //         id: 'id',
    //         accessorFn: row => row.id,
    //         // cell: (info: CellContext<IApplication, string>) => {
    //         //     const status = info.getValue();
    //         //     return (
    //         //         <div>
    //         //             {status ? 'Aktiv' : 'Inaktiv'}
    //         //         </div>
    //         //     );
    //         // },
    //         header: '#',
    //         meta: {
    //             size: 0.5
    //         },
    //         sortingFn: 'alphanumeric'
    //     },
    //     {
    //         id: 'created',
    //         accessorFn: (row) => row.created,
    //         header: 'Dato',
    //         cell: (info: CellContext<IApplication, string>) => {
    //             const status = info.getValue();
    //             return (
    //                 <div>
    //                     {status ? 'Aktiv' : 'Inaktiv'}
    //                 </div>
    //             );
    //         },
    //         sortingFn: 'alphanumeric'
    //     },
    //     {
    //         id: 'status',
    //         accessorFn: (row) => t(`bookingfrontend.${row.status.toLowerCase()}`),
    //         header: 'Status',
    //         // cell: (info: CellContext<IDelegate, boolean>) => {
    //         //     const status = info.getValue();
    //         //     return (
    //         //         <div>
    //         //             {status ? 'Aktiv' : 'Inaktiv'}
    //         //         </div>
    //         //     );
    //         // },
    //         sortingFn: 'alphanumeric'
    //     },
    //     {
    //         id: 'building',
    //         accessorFn: (row) => row.building_name,
    //         header: 'Hvor',
    //
    //         sortingFn: 'alphanumeric'
    //     },
    //     {
    //         id: 'resource',
    //         accessorFn: (row) => row.resources,
    //         header: 'Ressurser',
    //         // cell: (info: CellContext<IDelegate, boolean>) => {
    //         //     const status = info.getValue();
    //         //     return (
    //         //         <div>
    //         //             {status ? 'Aktiv' : 'Inaktiv'}
    //         //         </div>
    //         //     );
    //         // },
    //         sortingFn: 'alphanumeric'
    //     },
    //     {
    //         id: 'dates',
    //         header: 'Fra',
    //         accessorFn: row => row.dates[0].from_,
    //
    //         sortingFn: 'alphanumeric'
    //     },
    //     {
    //         id: 'org_nr',
    //         header: 'Organisasjonnummer',
    //         accessorFn: row => row.customer_organization_number,
    //         meta: {
    //             size: 2
    //         },
    //         sortingFn: 'alphanumeric'
    //     },
    //     {
    //         id: 'contact',
    //         header: 'Kontakt',
    //         accessorFn: row => row.contact_name,
    //
    //         sortingFn: 'alphanumeric'
    //     },
    //
    //
    // ] as const;
    return (
        <main>
            <GSTable<IApplication>
                data={applicationsRaw?.list || []}
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
                exportFileName={"applications"}

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

export default Applications


