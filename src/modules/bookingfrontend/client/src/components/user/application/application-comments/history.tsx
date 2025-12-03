'use client'
import React, {FC} from 'react';
import {Paragraph, Spinner} from "@digdir/designsystemet-react";
import GSAccordion from "@/components/gs-accordion/g-s-accordion";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {GetCommentsResponse} from "@/service/types/api/application.types";
import ApplicationCommentComponent from "./application-comment";

interface HistoryProps {
	commentsData?: GetCommentsResponse;
	isLoading: boolean;
}

const History: FC<HistoryProps> = ({ commentsData, isLoading }) => {
	const t = useTrans();

	return (
		<GSAccordion data-color="neutral">
			<GSAccordion.Heading>
				<h3>{t('bookingfrontend.history')}</h3>
			</GSAccordion.Heading>
			<GSAccordion.Content>
				{isLoading ? (
					<div style={{ display: 'flex', justifyContent: 'center', padding: '1rem' }}>
						<Spinner data-size="sm" aria-label={t('common.loading')} />
					</div>
				) : commentsData && commentsData.comments.length > 0 ? (
					<div>
						{commentsData.comments.map((comment) => (
							<ApplicationCommentComponent key={comment.id} comment={comment} />
						))}
					</div>
				) : (
					<Paragraph>{t('bookingfrontend.no_history_available')}</Paragraph>
				)}
			</GSAccordion.Content>
		</GSAccordion>
	);
};

export default History;