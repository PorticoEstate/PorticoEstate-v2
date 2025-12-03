/**
 * Organization Delegate Detail Page
 *
 * ACCESS LEVEL: Authenticated users only (with delegate access)
 *
 * FEATURES:
 * AUTHENTICATED USERS (with delegate access):
 * - View delegate basic info (name, email, phone)
 * - View delegate organization context
 * - View delegate status
 *
 * ORGANIZATION ADMINS (additional features):
 * - Edit delegate button
 * - Full delegate information including SSN (if available)
 *
 * NOTES:
 * - Links back to organization page
 * - Conditional rendering based on user permissions
 * - Mobile responsive design
 * - Only shows delegates that the user has access to
 */

import {fetchOrganization} from "@/service/api/api-utils"
import styles from './page.module.scss'
import {notFound} from "next/navigation";
import DelegateDetailContent
	from "@/app/[lang]/(public)/organization/[id]/delegate/[delegateId]/delegate-detail-content";

type Props = {
	params: { id: string; delegateId: string; lang: string }
	searchParams: { [key: string]: string | string[] | undefined }
}

export default async function DelegatePage({params, searchParams}: Props) {
	const {id: organizationId, delegateId} = params

	// Check if we should bust the cache (e.g., after an update)
	const shouldBustCache = Boolean(searchParams?.updated)

	const organization = await fetchOrganization(organizationId, shouldBustCache)
	if (!organization) {
		return notFound()
	}

	return (
		<main className={styles.delegatePageGrid}>
			<DelegateDetailContent
				organization={organization}
				organizationId={organizationId}
				delegateId={delegateId}
			/>
		</main>
	)
}