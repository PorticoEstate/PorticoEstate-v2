'use client'
import React, {FC, useMemo, useEffect} from 'react';
import {useRouter} from "next/navigation";
import {Tabs} from "@digdir/designsystemet-react";
import {
	Buildings2Icon,
	CalendarIcon,
	TenancyIcon
} from "@navikt/aksel-icons";
import {LinkTab} from '@/components/util/LinkTab';
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {IServerSettings} from "@/service/types/api.types";
import {useCurrentPath} from "@/service/hooks/path-hooks";

interface ClientLayoutProps {
	serverSettings: IServerSettings
}


const searchPages: {
	icon: typeof Buildings2Icon;
	value: string;
	labelTag: string;
	relativePath: string;
	configValue?: string;
}[] = [
	{
		relativePath: '/',
		value: '/',
		labelTag: 'bookingfrontend.rent',
		icon: Buildings2Icon,
		configValue: 'booking'
	},
	{
		relativePath: '/search/event',
		value: '/event',
		labelTag: 'bookingfrontend.event',
		icon: CalendarIcon,
		configValue: 'event'
	},
	{
		relativePath: '/search/organization',
		value: '/organization',
		labelTag: 'bookingfrontend._organization',
		icon: TenancyIcon,
		configValue: 'organization'
	},
];

const ClientLayout: FC<ClientLayoutProps> = (props) => {
	const actualPath = useCurrentPath();
	const pathname = actualPath.replace('search', '') || '/';
	const router = useRouter();
	const t = useTrans();
	const currentPath = searchPages.find(a => a.value === pathname);
	const links = useMemo(() => {
		return searchPages.filter(a =>
			props.serverSettings.booking_config?.landing_sections?.find(section => section === a.configValue))
	}, [props.serverSettings])

	// Handle hash-based redirects (#event, #organization)
	useEffect(() => {
		const hash = window.location.hash;
		if (hash === '#event' && pathname === '/') {
			router.replace('/search/event');
		} else if (hash === '#organization' && pathname === '/') {
			router.replace('/search/organization');
		}
	}, [pathname, router]);

	// PROTECT DISABLED PAGE
	if(props.serverSettings.booking_config?.landing_sections && currentPath && !props.serverSettings.booking_config.landing_sections.find(section => section === currentPath?.configValue)) {
		router.replace('/');
	}


	return (
		<nav aria-label={t('bookingfrontend.booking_categories')} style={{marginBottom: '1rem'}}>
			<Tabs value={pathname}>
				<Tabs.List>
					{links.map((link) => {
						const SVGIcon = link.icon;
						// const fullPath = '/user' + link.relativePath;

						return (
							<LinkTab href={link.relativePath} value={link.value} key={link.relativePath}>
								<SVGIcon fontSize='1.75rem' aria-hidden/>
								{t(link.labelTag)}
							</LinkTab>
						)
					})}
				</Tabs.List>
			</Tabs>
		</nav>
	);
}

export default ClientLayout
