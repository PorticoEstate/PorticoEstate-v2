'use client'
import React, {FC, useState} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {GSTable} from "@/components/gs-table";
import {ColumnDef} from "@/components/gs-table/table.types";
import {IDelegate} from "@/service/types/api.types";
import {default as NXLink} from "next/link";
import {phpGWLink} from "@/service/util";
import {Link} from '@digdir/designsystemet-react';
import {useBookingUser} from "@/service/hooks/api-hooks";

interface DelegatesProps {
}

const Delegates: FC<DelegatesProps> = (props) => {
    const t = useTrans();
    const {data: user} = useBookingUser();
    const delegates = user?.delegates;
    const [searchTerm, setSearchTerm] = useState('');

    const columns: ColumnDef<IDelegate>[] = [
        {
            id: 'name',
            accessorFn: row => row.name,
            header: 'Navn',
            cell: info => {
                const name = info.getValue<string>();
                const orgId = info.row.original.org_id;
                return (<Link
                    asChild
                    color={'neutral'}
                    // className="link-text link-text-unset normal"

                >
                    <NXLink href={phpGWLink('bookingfrontend/', {
                        menuaction: 'bookingfrontend.uiorganization.show',
                        id: orgId
                    }, false)}
                            target={'_blank'}
                    >
                        {name}
                    </NXLink>
                </Link>);
            },
            meta: {
                size: 2
            },
            sortingFn: 'alphanumeric'
        },
        {
            id: 'organization_number',
            header: 'Organisasjonnummer',
            accessorFn: row => row.organization_number,
            meta: {
                size: 2
            },
            sortingFn: 'alphanumeric'
        },
        {
            id: 'active',
            accessorFn: row => row.active,
            header: 'Status',
            cell: info => {
                const status = info.getValue<boolean>();
                return (
                    <div>
                        {status ? 'Aktiv' : 'Inaktiv'}
                    </div>
                );
            },
            sortingFn: 'alphanumeric'
        },
        // {
        //     id: 'org_id',
        //     accessorFn: row => row.org_id,
        //     header: 'ID',
        //     enableSorting: false,
        // },
    ];

    // Example of UserData columns for reference
    // const columns: ColumnDef<UserData>[] = [
    //     {
    //         id: 'name',
    //         accessorFn: row => row.name,
    //         header: 'User Name',
    //         meta: {
    //             size: 2
    //         },
    //         sortingFn: 'alphanumeric'
    //     },
    //     {
    //         id: 'email',
    //         accessorFn: row => row.email,
    //         meta: {
    //             size: 2
    //         },
    //         sortingFn: 'alphanumeric'
    //     },
    //     {
    //         id: 'status',
    //         accessorFn: row => row.status,
    //         cell: info => {
    //             const status = info.getValue<'active' | 'inactive' | 'pending'>();
    //             return (
    //                 <div>
    //                     {status}
    //                 </div>
    //             );
    //         },
    //         sortingFn: 'alphanumeric'
    //     },
    //     {
    //         id: 'lastLogin',
    //         accessorFn: row => row.lastLogin,
    //         header: 'Last Login',
    //         cell: info => info.getValue<Date>().toLocaleDateString(),
    //         sortingFn: (rowA, rowB) => {
    //             return rowA.original.lastLogin.getTime() - rowB.original.lastLogin.getTime();
    //         }
    //     },
    //     {
    //         id: 'posts',
    //         accessorFn: row => row.posts,
    //         header: 'Total Posts',
    //         meta: {
    //             align: 'end'
    //         },
    //         sortingFn: 'alphanumeric'
    //     },
    //     {
    //         id: 'role',
    //         accessorFn: row => row.role,
    //         sortingFn: 'alphanumeric'
    //     }
    // ];
    return (
        <GSTable<IDelegate>
            data={delegates || []}
            columns={columns}
            enableSorting={true}
            enablePagination={false}
            // renderRowButton={(delegate) => (
            //     <Button asChild variant="tertiary" size="sm">
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
            // enableRowSelection
            // enableMultiRowSelection
            // onSelectionChange={(e) => console.log(e)}
            // enableSearch
            // searchPlaceholder="Search users..."
            // onSearchChange={(value) => {
            //     console.log('Search term:', value);
            // }}
            // utilityHeader={true}
            // // selectedRows={selectedRows}
            // renderExpandedContent={(user) => (
            //     <div style={{display: 'flex', flexDirection: 'column'}}>
            //         <h3 className="font-bold mb-2">User Details</h3>
            //         <p>ID: {user.name}</p>
            //         <p>Email: {user.org_id}</p>
            //         <p>Role: {user.organization_number}</p>
            //         {/*<p>Posts: {user.}</p>*/}
            //         {/*<p>Status: {user.status}</p>*/}
            //         {/*<p>Last Login: {user.lastLogin.toLocaleString()}</p>*/}
            //     </div>
            // )}
        />
    );
}

export default Delegates


