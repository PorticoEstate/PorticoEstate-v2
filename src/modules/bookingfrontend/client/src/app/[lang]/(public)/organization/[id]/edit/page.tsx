/**
 * Organization Edit Page
 *
 * ACCESS LEVEL: Organization Admins Only
 *
 * FEATURES:
 * - Edit organization basic information (name, homepage, phone, email)
 * - Edit organization address (street, zip, city, district)
 * - Edit organization description (multilingual support)
 * - Manage organization type (personal vs official)
 * - Control portal visibility settings
 * - Organization number validation
 * - Activity assignment
 * - Customer management fields
 * - Save/cancel functionality
 *
 * NOTES:
 * - Requires organization admin permissions
 * - Form validation for Norwegian organization numbers
 * - Integration with Brønnøysund Register for validation
 * - Redirects to organization view after successful edit
 * - Shows access denied for unauthorized users
 */

import { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { fetchOrganization } from '@/service/api/api-utils'
import { getTranslation } from '@/app/i18n'
import OrganizationEditPageClient from './organization-edit-page-client'

type Props = {
  params: { id: string; lang: string }
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const organizationId = parseInt(params.id, 10)

  if (isNaN(organizationId)) {
    return notFound()
  }

  const organization = await fetchOrganization(organizationId)

  if (!organization) {
    return notFound()
  }

  const { t } = await getTranslation(params.lang)

  return {
    title: `${t('common.edit')} ${organization.name}`,
  }
}

export default function OrganizationEditPage({ params }: Props) {
  const { id } = params

  return (
    <OrganizationEditPageClient organizationId={id} />
  )
}