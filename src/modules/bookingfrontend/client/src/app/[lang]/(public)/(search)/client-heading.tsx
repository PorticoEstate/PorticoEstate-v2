'use client';
import React, {FC, PropsWithChildren, useMemo} from 'react';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {Heading, Paragraph} from "@digdir/designsystemet-react";
import {useCurrentPath} from "@/service/hooks/path-hooks";

interface ClientHeadingProps extends PropsWithChildren {
}

const ClientHeading: FC<ClientHeadingProps> = (props) => {
	const t = useTrans();
	const actualPath = useCurrentPath();

	const [title, subtitle] = useMemo(() => {
		const pathname = actualPath.replace('search/', '') || '/';
		switch (pathname) {
			case '/':
				return ['rent_premises_facilities_equipment', 'use_filters_to_find_rental_objects'];
			case 'event':
				return ['find_event_or_activity', 'use_filters_to_find_todays_events'];
			case 'organization':
				return ['find_team_or_organization', 'search_for_like_minded_people'];
		}
		return ['a', 'b']

	}, [actualPath])
	if (!t) {
		return props.children
	}
	return (
		<header className="page-heading" style={{paddingBottom: '1rem'}}>
			<Heading data-size={"xl"}
					 className="page-title ds-color-accent">{t('bookingfrontend.'+title)}</Heading>
			<Paragraph data-size={'xl'}
					   className="page-subtitle ds-color-accent">{t('bookingfrontend.'+subtitle)}</Paragraph>
		</header>
	);
}

export default ClientHeading


