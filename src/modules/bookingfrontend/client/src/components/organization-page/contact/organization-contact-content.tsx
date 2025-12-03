import { IOrganization } from "@/service/types/api/organization.types"
import { PhoneIcon, PersonIcon } from "@navikt/aksel-icons"
import { getTranslation } from "@/app/i18n"
import styles from './organization-contact-content.module.scss'

interface OrganizationContactContentProps {
    organization: IOrganization
}

const OrganizationContactContent = async (props: OrganizationContactContentProps) => {
    const { organization } = props
    const { t } = await getTranslation()

    return (
        <div className={styles.contactContent}>
            {organization.phone && (
                <div className={styles.contactItem}>
                    <span className={styles.contactLabel}>{t('booking.phone')}:</span>
                    <a href={`tel:${organization.phone}`} className={styles.contactValue}>
                        {organization.phone}
                    </a>
                </div>
            )}
            {organization.email && (
                <div className={styles.contactItem}>
                    {/*<EnvelopeIcon className={styles.contactIcon} />*/}
                    <span className={styles.contactLabel}>{t('common.email')}:</span>
                    <a href={`mailto:${organization.email}`} className={styles.contactValue}>
                        {organization.email}
                    </a>
                </div>
            )}
            {organization.homepage && (
                <div className={styles.contactItem}>
                    <span className={styles.contactLabel}>{t('common.website')}:</span>
                    <a href={organization.homepage} target="_blank" rel="noopener noreferrer" className={styles.contactValue}>
                        {organization.homepage}
                    </a>
                </div>
            )}
        </div>
    )
}

export default OrganizationContactContent