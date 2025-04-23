'use client'
import React, {FC, useState} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {GSTable} from "@/components/gs-table";
import {CellContext, createColumnHelper} from "@tanstack/table-core";
import {ColumnDef} from "@/components/gs-table/table.types";
import {IDelegate} from "@/service/types/api.types";
import {default as NXLink} from "next/link";
import {phpGWLink} from "@/service/util";
import {Link} from '@digdir/designsystemet-react';
import {useBookingUser} from "@/service/hooks/api-hooks";

interface DelegatesProps {
}


// Define the data type
interface UserData {
    id: number;
    name: string;
    email: string;
    status: 'active' | 'inactive' | 'pending';
    lastLogin: Date;
    posts: number;
    role: string;
}

const userData: UserData[] = [
    {
        id: 1,
        name: "John Smith",
        email: "john.smith@example.com",
        status: "active",
        lastLogin: new Date('2024-03-15T10:30:00'),
        posts: 145,
        role: "Admin"
    },
    {
        id: 2,
        name: "Sarah Johnson",
        email: "sarah.j@example.com",
        status: "inactive",
        lastLogin: new Date('2024-03-10T15:45:00'),
        posts: 67,
        role: "Editor"
    },
    {
        id: 3,
        name: "Michael Chen",
        email: "m.chen@example.com",
        status: "active",
        lastLogin: new Date('2024-03-18T09:15:00'),
        posts: 234,
        role: "Author"
    },
    {
        id: 4,
        name: "Emma Wilson",
        email: "emma.w@example.com",
        status: "pending",
        lastLogin: new Date('2024-03-17T14:20:00'),
        posts: 89,
        role: "Contributor"
    },
    {
        id: 5,
        name: "David Brown",
        email: "david.b@example.com",
        status: "active",
        lastLogin: new Date('2024-03-16T11:50:00'),
        posts: 178,
        role: "Editor"
    }
];

const Delegates: FC<DelegatesProps> = (props) => {
    const t = useTrans();
    const {data: user} = useBookingUser();
    const delegates = user?.delegates;
    const [searchTerm, setSearchTerm] = useState('');


    // Optional: Use TanStack's column helper for better type inference
    const columnHelper = createColumnHelper<IDelegate>();
    const columns: ColumnDef<IDelegate>[] = [
        {
            id: 'name',
            accessorFn: row => row.name,
            header: 'Navn',
            cell: (info: CellContext<IDelegate, string>) => {
                const name = info.getValue();
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
            cell: (info: CellContext<IDelegate, boolean>) => {
                const status = info.getValue();
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
        //
        //     // cell: (info: CellContext<UserData, Date>) =>
        //     //     info.getValue().toLocaleDateString(),
        //     // sortingFn: (rowA, rowB) => {
        //     //     return rowA.original.lastLogin.getTime() - rowB.original.lastLogin.getTime();
        //     // }
        // },
    ] as const;

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
    //         cell: (info: CellContext<UserData, 'active' | 'inactive' | 'pending'>) => {
    //             const status = info.getValue();
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
    //         cell: (info: CellContext<UserData, Date>) =>
    //             info.getValue().toLocaleDateString(),
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
    // ] as const;
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


