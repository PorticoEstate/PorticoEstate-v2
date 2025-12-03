import React, { FC } from 'react';
import { getTranslation } from '@/app/i18n';
import { IDocument } from '@/service/types/api.types';
import GSAccordion from "@/components/gs-accordion/g-s-accordion";
import Document from "@/components/shared/document/document";
import styles from './documents-section.module.scss';

interface DocumentsSectionProps {
    documents: IDocument[];
    type: 'building' | 'resource' | 'organization';
    className?: string;
}

const DocumentsSection: FC<DocumentsSectionProps> = async ({ documents, type, className }) => {
    const { t } = await getTranslation();

    if (!documents || documents.length === 0) {
        return null; // Don't show anything if no documents
    }

    return (
        <GSAccordion className={className} data-color={'neutral'}>
            <GSAccordion.Heading>
                <h3>{t('bookingfrontend.documents')}</h3>
            </GSAccordion.Heading>
            <GSAccordion.Content>
                <div className={styles.documentsContainer}>
                    <ul className={styles.documentsList}>
                        {documents.map((doc) => (
                            <li key={doc.id}>
                                <Document document={doc} type={type} />
                            </li>
                        ))}
                    </ul>
                </div>
            </GSAccordion.Content>
        </GSAccordion>
    );
};

export default DocumentsSection;