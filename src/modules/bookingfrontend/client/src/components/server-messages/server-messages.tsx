'use client'
import React, {FC} from 'react';
import {useDeleteServerMessage, useServerMessages} from "@/service/hooks/api-hooks";
import {Alert, Button, Paragraph} from "@digdir/designsystemet-react";
import {SeverityColors} from "@digdir/designsystemet-react/colors";
import styles from "./server-messages.module.scss";
import {XMarkIcon} from "@navikt/aksel-icons";
interface ServerMessagesProps {
}

const ServerMessages: FC<ServerMessagesProps> = (props) => {
	const {data: messages} = useServerMessages();
	const deleteMessages = useDeleteServerMessage();
	if(!messages || messages.length === 0) return null;
	return (
		<section className={'py-1'}>
			{messages.map((message) => {
				let color: SeverityColors;
				if(message.type === 'error') {
					color = 'danger';
				} else {
					color = message.type
				}
				return (
					<Alert data-color={color} className={styles.messageCard} key={message.id}>
						<div>
							{/*<Heading level={2} data-size='xs' style={{*/}
							{/*	marginBottom: 'var(--ds-size-2)'*/}
							{/*}}>*/}
							{/*	Har du husket å bestille passtime?*/}
							{/*</Heading>*/}
							<Paragraph dangerouslySetInnerHTML={{ __html: message.text }}>
							</Paragraph>
						</div>
						<Button icon data-color='neutral' variant='tertiary' aria-label='Lukk melding' onClick={() => {deleteMessages.mutate(message.id)}}>
							<XMarkIcon aria-hidden />
						</Button>

					</Alert>
				)
			})}


		</section>
	);
}

export default ServerMessages


