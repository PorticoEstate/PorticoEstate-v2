'use client';
import React, {FC, useMemo} from 'react';
import {Paragraph} from '@digdir/designsystemet-react';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {IHospitalityOrderContact} from '@/service/types/api/hospitality.types';
import {unescapeHTML} from '@/components/building-page/util/building-text-util';
import {safeHtmlToReact} from '@/utils/safe-html';
import styles from './kitchen-contact.module.scss';

/**
 * Who to ask about a serving: the kitchen's own contact text when it has one, else the building's name, email and phone.
 *
 * The kitchen's contact_info is admin-authored rich text, rendered the way the resource page renders it (unescapeHTML,
 * then parsed) but through safe-html.ts: a tag, attribute and URL-scheme whitelist, and without the editor's empty
 * trailing paragraph that used to leave a tall empty band. A contact_info with nothing in it falls back to the building,
 * as if it had not been written. Renders nothing when there is neither.
 */
const KitchenContact: FC<{contact: IHospitalityOrderContact}> = ({contact}) => {
    const t = useTrans();
    const info = useMemo(() => (contact.info ? safeHtmlToReact(unescapeHTML(contact.info)) : null), [contact.info]);
    const hasBuildingContact = !!(contact.building_email || contact.building_phone);
    if (!info && !hasBuildingContact) return null;

    return (
        <div className={styles.contact} data-testid="catering-contact">
            <Paragraph data-size="sm"><strong>{t('bookingfrontend.catering_kitchen_contact')}</strong></Paragraph>
            {info ? (
                <div className={styles.info}>{info}</div>
            ) : (
                <Paragraph data-size="sm">
                    {contact.building_name && <>{contact.building_name}<br/></>}
                    {contact.building_email && <>{t('common.email')}: {contact.building_email}<br/></>}
                    {contact.building_phone && <>{t('booking.phone')}: {contact.building_phone}</>}
                </Paragraph>
            )}
        </div>
    );
};

export default KitchenContact;
