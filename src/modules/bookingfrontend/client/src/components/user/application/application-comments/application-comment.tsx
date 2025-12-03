'use client'
import React, {FC} from 'react';
import {Heading, Paragraph} from "@digdir/designsystemet-react";
import {DateTime} from "luxon";
import {useTrans, useClientTranslation} from "@/app/i18n/ClientTranslationProvider";
import {ApplicationComment} from "@/service/types/api/application.types";
import TimeAgo from 'timeago-react';
import * as timeago from 'timeago.js';
import nb from 'timeago.js/lib/lang/nb_NO';
import nn from 'timeago.js/lib/lang/nn_NO';
import en from 'timeago.js/lib/lang/en_US';

timeago.register('no', nb);
timeago.register('nn', nn);
timeago.register('en', en);

interface ApplicationCommentProps {
	comment: ApplicationComment;
}

const ApplicationCommentComponent: FC<ApplicationCommentProps> = ({comment}) => {
	const t = useTrans();
	const {i18n} = useClientTranslation();


	return (
		<div style={{
			padding: '1rem',
			border: '1px solid var(--ds-color-border-subtle)',
			borderRadius: '4px',
			marginBottom: '0.5rem'
		}}>
			<div style={{ 
				display: 'flex', 
				alignItems: 'center', 
				gap: '0.5rem',
				marginBottom: '0.5rem'
			}}>
				<Heading level={4} data-size="xs">
					{comment.author}
				</Heading>
				<Paragraph data-size="xs">
					<TimeAgo
						datetime={comment.time}
						locale={i18n.language}
					/>
					{' · '}
					{DateTime.fromISO(comment.time).toFormat('dd.MM.yyyy HH:mm')}
				</Paragraph>
				{comment.type !== 'comment' && (
					<span style={{
						padding: '0.125rem 0.5rem',
						backgroundColor: 'var(--ds-color-surface-subtle)',
						borderRadius: '4px',
						fontSize: '0.75rem'
					}}>
						{t(`bookingfrontend.${comment.type}`)}
					</span>
				)}
			</div>
			<Paragraph>
				{comment.comment}
			</Paragraph>
		</div>
	);
};

export default ApplicationCommentComponent;