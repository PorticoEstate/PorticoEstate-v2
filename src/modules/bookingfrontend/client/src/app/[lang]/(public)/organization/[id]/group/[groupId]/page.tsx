/**
 * Organization Group Detail Page
 *
 * ACCESS LEVEL: Public (all users) + Enhanced features for authenticated users
 *
 * FEATURES:
 * PUBLIC USERS:
 * - View group basic info (name, shortname, description)
 * - View group organization context
 * - View group contacts (if public)
 *
 * AUTHENTICATED USERS (additional features):
 * - Enhanced contact details
 * - Activity information
 *
 * ORGANIZATION ADMINS (additional features):
 * - Edit group button
 * - Full contact management
 *
 * NOTES:
 * - Links back to organization page
 * - Conditional rendering based on user permissions
 * - Mobile responsive design
 */

import {notFound} from 'next/navigation'
import {fetchOrganization, fetchOrganizationGroup} from "@/service/api/api-utils"
import styles from './page.module.scss'
import GroupDetailContent from './group-detail-content'

type Props = {
	params: { id: string; groupId: string; lang: string }
	searchParams: { [key: string]: string | string[] | undefined }
}

export default async function GroupPage({params, searchParams}: Props) {
	const {id: organizationId, groupId} = params

	// Check if we should bust the cache (e.g., after an update)
	const shouldBustCache = Boolean(searchParams?.updated)

	const organization = await fetchOrganization(organizationId, shouldBustCache)
	if (!organization) notFound()

	const group = await fetchOrganizationGroup(organizationId, groupId)
	if (!group) notFound()

	return (
		<main className={styles.groupPageGrid}>
			<GroupDetailContent
				organization={organization}
				group={group}
				organizationId={organizationId}
			/>
		</main>
	)
}