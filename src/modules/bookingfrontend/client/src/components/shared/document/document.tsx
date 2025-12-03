import React, { FC } from 'react';
import { Link as DigdirLink } from '@digdir/designsystemet-react';
import Link from 'next/link';
import { FileImageIcon, FileIcon, TasklistIcon, FileParagraphIcon, FileCheckmarkIcon } from '@navikt/aksel-icons';
import { getDocumentLink } from '@/service/api/building';
import { IDocument } from '@/service/types/api.types';
import styles from './document.module.scss';
import ClientPHPGWLink from "@/components/layout/header/ClientPHPGWLink";
import {phpGWLink} from "@/service/util";

interface DocumentProps {
    document: IDocument;
    type: 'building' | 'resource' | 'organization';
    asLabel?: boolean;
}

const getDocumentIcon = (category: IDocument['category']) => {
    switch (category) {
        case 'drawing':
            return <FileImageIcon fontSize="1.25rem" />;
        case 'price_list':
            return <TasklistIcon fontSize="1.25rem" />;
        case 'regulation':
            return <FileParagraphIcon fontSize="1.25rem" />;
        case 'HMS_document':
            return <FileCheckmarkIcon fontSize="1.25rem" />;
        default:
            return <FileIcon fontSize="1.25rem" />;
    }
};

const Document: FC<DocumentProps> = ({ document, type, asLabel = false }) => {

    return (
        <div className={styles.documentItem}>
            <div className={styles.documentIcon}>
                {getDocumentIcon(document.category)}
            </div>
            <DigdirLink asChild data-color='accent'>
				<ClientPHPGWLink strURL={['bookingfrontend',document.owner_type + 's', 'document', document.id, 'download']}      target="_blank"
								 className={styles.documentLink}>
					{document.name}
				</ClientPHPGWLink>

            </DigdirLink>
            {document.description && (
                <div className={styles.documentDescription}>
                    {document.description}
                </div>
            )}
        </div>
    );
};

export default Document;