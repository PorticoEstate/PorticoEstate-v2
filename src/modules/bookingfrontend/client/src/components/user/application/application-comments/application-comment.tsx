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
			marginBottom: '1rem',
			paddingBottom: '1rem',
			borderBottom: '1px solid var(--digdir-border-default)'
		}}>
			<div style={{marginBottom: '0.5rem'}}>
				<div style={{display: 'flex', alignItems: 'center', gap: '0.5rem'}}>

					<Heading level={4} data-size="xs">{comment.author}</Heading>
					<Paragraph data-size={'xs'}>
						<TimeAgo
							datetime={comment.time}
							locale={i18n.language}
						/>
						{' · '}
						{DateTime.fromISO(comment.time).toFormat('dd.MM.yyyy HH:mm')}
					</Paragraph>
				</div>

				<div style={{color: 'var(--digdir-text-subtle)', fontSize: '0.875rem'}}>
					{comment.type !== 'comment' && (
						<span style={{
							marginLeft: '0.5rem',
							padding: '0.25rem 0.5rem',
							backgroundColor: 'var(--digdir-surface-subtle)',
							borderRadius: '4px',
							fontSize: '0.75rem'
						}}>
							{t(`bookingfrontend.${comment.type}`)}
						</span>
					)}
				</div>
			</div>
			<Paragraph>{comment.comment}</Paragraph>
		</div>
	);
};

export default ApplicationCommentComponent;