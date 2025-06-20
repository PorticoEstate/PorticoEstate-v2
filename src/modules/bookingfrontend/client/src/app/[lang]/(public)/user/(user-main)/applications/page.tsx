import React from 'react';
import { fetchDeliveredApplications } from "@/service/api/api-utils";
import ApplicationsTable from '@/components/user/application/applications-table';
import PageHeader from '@/components/page-header/page-header';
import { getTranslation } from "@/app/i18n";

export default async function ApplicationsPage() {
  const { t } = await getTranslation();

  // Fetch delivered applications on the server using the enhanced function with cookie authentication
  const applications = await fetchDeliveredApplications(true);

  return (
    <main>
      <PageHeader title={t('bookingfrontend.applications')} />
      <ApplicationsTable initialApplications={applications} />
    </main>
  );
}