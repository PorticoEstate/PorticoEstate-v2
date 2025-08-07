import {getTranslation} from "@/app/i18n"
import {IOrganization} from "@/service/types/api/organization.types"
import ResponsiveWrapper from "@/components/shared/responsive-wrapper"
import OrganizationContactContent from "./organization-contact-content"
import styles from "@/components/shared/responsive-wrapper.module.scss";
import {Heading} from "@digdir/designsystemet-react";

interface OrganizationContactWrapperProps {
	organization: IOrganization
	className?: string
}

const OrganizationContactWrapper = async (props: OrganizationContactWrapperProps) => {
	const {organization, className} = props;
	const {t} = await getTranslation();

	// Check if we have any contact info to display
	const hasContactInfo = organization.phone || organization.email || organization.homepage;

	return (
		<section className={`${styles.contentSection} ${className || ''}`}>
			<Heading level={2} data-size={'md'} className={styles.sectionTitle}>
				{t('bookingfrontend.contact information')}
			</Heading>
			<div className={styles.sectionContent}>
				<OrganizationContactContent organization={organization}/>
			</div>
		</section>);
}

export default OrganizationContactWrapper