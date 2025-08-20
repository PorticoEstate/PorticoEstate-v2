import React from 'react';
import InvoicesTable from '@/components/user/invoices/invoices-table';
import { fetchInvoices } from '@/service/api/api-utils';

async function InvoicesPage() {
    // Server-side data fetching
    const invoices = await fetchInvoices();

    return (
        <main>
            <InvoicesTable initialInvoices={invoices} />
        </main>
    );
}

export default InvoicesPage;

