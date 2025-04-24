'use client'
import React, {FC} from 'react';
import {IDocument} from '@/service/types/api.types';
import {Checkbox, Link, Heading, Alert} from '@digdir/designsystemet-react';
import {getDocumentLink} from '@/service/api/building';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import styles from './checkout.module.scss';
import {Card} from '@digdir/designsystemet-react';

interface RegulationDocumentsProps {
	documents: IDocument[];
	checkedDocuments: Record<number, boolean>;
	onDocumentCheck: (documentId: number, checked: boolean) => void;
	areAllChecked: boolean;
	showError?: boolean;
}

const RegulationDocuments: FC<RegulationDocumentsProps> = ({
															   documents,
															   checkedDocuments,
															   onDocumentCheck,
															   areAllChecked,
															   showError = false
														   }) => {
	const t = useTrans();

	if (documents.length === 0) {
		return null;
	}

	return (
		<div className={styles.documentsSection}>
			<Heading level={2} data-size="md">
				{t('bookingfrontend.terms and conditions')}
			</Heading>

			<div className={styles.documentsContainer}>
				{documents.map((doc) => (

					<div className={styles.documentCheckbox} key={doc.id}>
						<Checkbox
							checked={checkedDocuments[doc.id] || false}
							onChange={(e) => onDocumentCheck(doc.id, e.target.checked)}
							label={<Link
								href={getDocumentLink(doc, doc.owner_type || 'resource')}
								target="_blank"
								className={styles.documentLink}
							>
								{doc.name}
							</Link>}
							error={showError && !checkedDocuments[doc.id] ?
								t('bookingfrontend.confirm_this_document') : undefined}
						>
							{t('bookingfrontend.confirm_read_document')}
						</Checkbox>
					</div>
				))}
			</div>
		</div>
	);
};

export default RegulationDocuments;