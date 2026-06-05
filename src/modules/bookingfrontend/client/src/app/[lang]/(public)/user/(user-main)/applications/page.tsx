import React from 'react';
import ApplicationsTable from '@/components/user/application/applications-table';

export default function ApplicationsPage() {
  // Data is fetched client-side via useApplications hook to avoid blocking page render.
  // The server-side prefetch was removed because the query is slow (~3500 DB queries for 500+ apps).
  return (
    <main>
      <ApplicationsTable />
    </main>
  );
}
