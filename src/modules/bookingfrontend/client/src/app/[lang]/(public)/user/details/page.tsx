'use client'
import {FC} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import PageHeader from "@/components/page-header/page-header";
import {IBookingUser} from "@/service/types/api.types";
import {useBookingUser} from "@/service/hooks/api-hooks";
import {Table} from "@digdir/designsystemet-react";

interface DetailsProps {
}

const Details: FC<DetailsProps> = (props) => {
    const t = useTrans();
    const {data: user} = useBookingUser();

    const fields: {label: string, key: keyof IBookingUser}[] = [
        {label: t('common.name'), key: 'name'},
        {label: t('bookingfrontend.ssn'), key: 'ssn'},
        // {label: t('bookingfrontend.customer number'), key: 'customer_number'},
        {label: t('bookingfrontend.homepage'), key: 'homepage'},
        {label: t('bookingfrontend.contact_email'), key: 'email'},
        {label: t('bookingfrontend.phone'), key: 'phone'},
        {label: t('bookingfrontend.street'), key: 'street'},
        {label: t('bookingfrontend.zip code'), key: 'zip_code'},
        {label: t('bookingfrontend.city'), key: 'city'}
    ]

    return (
        <main>
            {/*<PageHeader title={t('common.user data')}/>*/}

            <Table
                style={{
                    tableLayout: 'fixed'
                }}
            >
                <Table.Body>
                    {fields.map(a => <Table.Row key={a.key}><Table.Cell>{a.label}</Table.Cell><Table.Cell>{user?.[a.key] as string}</Table.Cell></Table.Row>)}
                </Table.Body>
            </Table>
        </main>
    );
}

export default Details


