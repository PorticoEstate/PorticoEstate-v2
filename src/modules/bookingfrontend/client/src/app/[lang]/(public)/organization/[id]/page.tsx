/**
 * Individual Organization View Page
 *
 * ACCESS LEVEL: Public (all users) + Enhanced features for authenticated users
 *
 * FEATURES:
 * PUBLIC USERS:
 * - View organization basic info (name, address, description, contact info)
 * - View organization calendar (read-only)
 * - View organization image gallery
 * - View buildings used by organization
 * - View organization groups (if public)
 *
 * AUTHENTICATED USERS (additional features):
 * - View delegate information
 * - Access to organization-specific contact details
 * - Enhanced calendar view with booking details
 *
 * ORGANIZATION ADMINS (additional features):
 * - Edit organization button
 * - Manage delegates section
 * - Manage groups section
 * - Add/edit organization images
 * - Full calendar management
 *
 * NOTES:
 * - Links to legacy system for edit functionality
 * - Conditional rendering based on user permissions
 * - Mobile responsive design
 * - Map integration for organization address
 */

import {notFound} from 'next/navigation'
import {fetchOrganization, fetchOrganizationBuildings} from "@/service/api/api-utils"
import OrganizationHeader from '@/components/organization-page/header/organization-header'
import DescriptionWrapper from '@/components/building-page/description-wrapper'
import OrganizationContactWrapper from '@/components/organization-page/contact/organization-contact-wrapper'
import OrganizationBuildingsWrapper from '@/components/organization-page/buildings/organization-buildings-wrapper'
import OrganizationGroupsContent from "@/components/organization-page/groups/organization-groups-content";
import styles from './page.module.scss'
import OrganizationDelegatesContent from "@/components/organization-page/delegates/organization-delegates-content";
import BuildingPhotos from "@/components/building-page/building-photos/building-photos";
import {fetchOrganizationDocuments} from "@/service/api/building";
import BuildingCalendar from "@/components/building-calendar";

type Props = {
	params: { id: string; lang: string }
	searchParams: { [key: string]: string | string[] | undefined }
}


export default async function OrganizationPage({params, searchParams}: Props) {
	const {id} = params

	// Check if we should bust the cache (e.g., after an update)
	const shouldBustCache = Boolean(searchParams?.updated)

	const organization = await fetchOrganization(id, shouldBustCache)
	if (!organization) notFound()


	const buildings = await fetchOrganizationBuildings(id);
	const photos = await fetchOrganizationDocuments(id, 'images');
	const avatarPhoto = photos.find(photo => photo.category === 'picture_main');

	return (
		<main className={styles.organizationPageGrid}>
			<OrganizationHeader
				organization={organization}
				avatar={avatarPhoto}
				className={styles.header}
			/>

			<OrganizationContactWrapper
				organization={organization}
				className={styles.contact}
			/>

			<DescriptionWrapper
				description_json={organization.description_json ?? undefined}
				className={styles.description}
			/>

			<OrganizationBuildingsWrapper
				buildings={buildings}
				className={styles.buildings}
			/>


			<OrganizationGroupsContent
				organizationId={id}
				className={styles.groups}
			/>

			<OrganizationDelegatesContent
				organizationId={id}
				className={styles.delegates}
			/>


			<BuildingPhotos
				object={organization}
				type={'organization'}
				photos={photos}
				className={styles.photos}
			/>

			<section className={styles.calendar}>
				<BuildingCalendar
					organization_id={id}
					readOnly={true}
					buildings={buildings}
				/>
			</section>


		</main>
	)
}