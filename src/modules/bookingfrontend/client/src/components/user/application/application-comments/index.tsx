'use client'
import React, {FC} from 'react';
import {useApplicationComments} from "@/service/hooks/api-hooks";
import {Paragraph, Spinner} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import ApplicationCommentComponent from "./application-comment";
import Create from "./create";

interface ApplicationCommentsProps {
	applicationId: number;
	secret?: string;
}

const ApplicationComments: FC<ApplicationCommentsProps> = ({ applicationId, secret }) => {
	const { data: commentsData, isLoading: commentsLoading } = useApplicationComments(
		applicationId,
		"comment,ownership,status",
		secret
	);
	const t = useTrans();

	// Get the two latest comments
	const latestComments = commentsData?.comments ? commentsData.comments.slice(-2) : [];

	return (
		<section className="my-2">
			{/* Latest 2 comments - always visible */}
			{commentsLoading ? (
				<div style={{ display: 'flex', justifyContent: 'center', padding: '1rem' }}>
					<Spinner data-size="sm" aria-label={t('common.loading')} />
				</div>
			) : latestComments.length > 0 ? (
				<div style={{ marginBottom: '1rem' }}>
					{latestComments.map((comment, index) => (
						<div key={comment.id} style={{
							marginBottom: index < latestComments.length - 1 ? '1rem' : '0'
						}}>
							<ApplicationCommentComponent comment={comment} />
						</div>
					))}
				</div>
			) : null}
			
			{/* Add comment form */}
			<Create 
				applicationId={applicationId} 
				secret={secret} 
				commentsData={commentsData}
				isLoading={commentsLoading}
			/>
		</section>
	);
};

export default ApplicationComments;