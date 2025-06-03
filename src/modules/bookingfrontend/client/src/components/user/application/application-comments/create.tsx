'use client'
import React, {FC, useState} from 'react';
import {Textarea, Button, Spinner} from "@digdir/designsystemet-react";
import GSAccordion from "@/components/gs-accordion/g-s-accordion";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useAddApplicationComment} from "@/service/hooks/api-hooks";

interface CreateProps {
	applicationId: number;
	secret?: string;
}

const Create: FC<CreateProps> = ({ applicationId, secret }) => {
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
						<div style={{ color: 'var(--digdir-error-text)', fontSize: '0.875rem' }}>
							{t('bookingfrontend.failed to add comment')}
						</div>
					)}
				</form>
			</GSAccordion.Content>
		</GSAccordion>
	);
};

export default Create;