'use client'
import React, {FC, useState} from 'react';
import {Textarea, Button, Spinner, Paragraph} from "@digdir/designsystemet-react";
import GSAccordion from "@/components/gs-accordion/g-s-accordion";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useAddApplicationComment} from "@/service/hooks/api-hooks";
import {GetCommentsResponse} from "@/service/types/api/application.types";
import ApplicationCommentComponent from "./application-comment";

interface CreateProps {
	applicationId: number;
	secret?: string;
	commentsData?: GetCommentsResponse;
	isLoading: boolean;
}

const Create: FC<CreateProps> = ({ applicationId, secret, commentsData, isLoading }) => {
	const addCommentMutation = useAddApplicationComment();
	const [newComment, setNewComment] = useState('');
	const t = useTrans();

	const handleSubmitComment = async (e: React.FormEvent) => {
		e.preventDefault();
		if (!newComment.trim()) return;

		try {
			await addCommentMutation.mutateAsync({
				applicationId,
				commentData: {
					comment: newComment.trim(),
					type: 'comment'
				},
				secret
			});
			setNewComment('');
		} catch (error) {
			console.error('Failed to add comment:', error);
		}
	};

	return (
		<GSAccordion data-color="neutral">
			<GSAccordion.Heading>
				<h3>{t('bookingfrontend.add comment')}</h3>
			</GSAccordion.Heading>
			<GSAccordion.Content>
				<form onSubmit={handleSubmitComment} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
					<div>
						<label htmlFor="comment-textarea" style={{ marginBottom: '0.5rem', display: 'block', fontWeight: 500 }}>
							{t('bookingfrontend.comment')}
						</label>
						<Textarea
							id="comment-textarea"
							placeholder={t('bookingfrontend.enter your comment here')}
							value={newComment}
							onChange={(e) => setNewComment(e.target.value)}
							rows={4}
							maxLength={10000}
							disabled={addCommentMutation.isPending}
						/>
					</div>
					<div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.5rem' }}>
						<Button
							type="button"
							variant="secondary"
							onClick={() => setNewComment('')}
							disabled={!newComment.trim() || addCommentMutation.isPending}
						>
							{t('common.clear')}
						</Button>
						<Button
							type="submit"
							disabled={!newComment.trim() || addCommentMutation.isPending}
						>
							{addCommentMutation.isPending ? (
								<>
									<Spinner data-size="xs" aria-hidden="true" style={{ marginRight: '0.5rem' }} />
									{t('common.saving')}
								</>
							) : (
								t('bookingfrontend.add comment')
							)}
						</Button>
					</div>
					{addCommentMutation.isError && (
						<div style={{ color: 'var(--ds-color-danger-text)', fontSize: '0.875rem' }}>
							{t('bookingfrontend.failed to add comment')}
						</div>
					)}
				</form>

				{commentsData && commentsData.comments.length > 0 && (
					<details style={{ marginTop: '1.5rem' }}>
						<summary style={{ 
							cursor: 'pointer', 
							fontWeight: 500,
							marginBottom: '1rem',
							userSelect: 'none'
						}}>
							{t('bookingfrontend.history')} ({commentsData.comments.length})
						</summary>
						{isLoading ? (
							<div style={{ display: 'flex', justifyContent: 'center', padding: '1rem' }}>
								<Spinner data-size="sm" aria-label={t('common.loading')} />
							</div>
						) : (
							<div>
								{commentsData.comments.map((comment) => (
									<ApplicationCommentComponent key={comment.id} comment={comment} />
								))}
							</div>
						)}
					</details>
				)}
			</GSAccordion.Content>
		</GSAccordion>
	);
};

export default Create;