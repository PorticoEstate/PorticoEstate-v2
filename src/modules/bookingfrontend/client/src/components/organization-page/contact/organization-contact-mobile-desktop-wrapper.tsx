import { getTranslation } from "@/app/i18n"
import { IOrganization } from "@/service/types/api/organization.types"
import { Heading } from "@digdir/designsystemet-react"
import OrganizationContactContent from "./organization-contact-content"
import styles from './organization-contact-mobile-desktop-wrapper.module.scss'

interface OrganizationContactMobileDesktopWrapperProps {
    organization: IOrganization
}

const OrganizationContactMobileDesktopWrapper = async (props: OrganizationContactMobileDesktopWrapperProps) => {
    const { organization } = props
    const { t } = await getTranslation()

    // Check if we have any contact info to display
    const hasContactInfo = organization.phone || organization.email || organization.homepage

    if (!hasContactInfo) {
        return null
    }

    return (
        <div className={styles.contactWrapper}>
            {/* Mobile: Show above information as desktop-style section */}
            <div className={styles.mobileOnly}>
                <section className={styles.contentSection}>
                    <Heading level={2} data-size={'md'} className={styles.sectionTitle}>
                        {t('bookingfrontend.contact information')}
                    </Heading>
                    <div className={styles.sectionContent}>
                        <OrganizationContactContent organization={organization} />
                    </div>
                </section>
            </div>

            {/* Desktop: Show below information with responsive wrapper behavior */}
            <div className={styles.desktopOnly}>
                <section className={styles.contentSection}>
                    <Heading level={2} data-size={'md'} className={styles.sectionTitle}>
                        {t('bookingfrontend.contact information')}
                    </Heading>
                    <div className={styles.sectionContent}>
                        <OrganizationContactContent organization={organization} />
                    </div>
                </section>
            </div>
        </div>
    )
}

export default OrganizationContactMobileDesktopWrapper