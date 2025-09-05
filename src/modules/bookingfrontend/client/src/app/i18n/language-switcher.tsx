'use client'
import React from 'react';
import {useClientTranslation} from '@/app/i18n/ClientTranslationProvider';
import {ILanguage, languages} from '@/app/i18n/settings';
import {Button, Dropdown} from "@digdir/designsystemet-react";
import {useParams, usePathname, useRouter} from "next/navigation";
import ReactCountryFlag from "react-country-flag";
import {phpGWLink} from "@/service/util";
import {ChevronDownIcon} from "@navikt/aksel-icons";
import InlineResponsiveDropdown from "@/components/common/inline-responsive-dropdown/inline-responsive-dropdown";

// Create a utility function to refresh translations
const refreshPage = () => {
	// Force a hard refresh of the page to ensure all components get new translations
	window.location.reload();
};

const LanguageSwitcher: React.FC = () => {
	const pathname = usePathname();
	const params = useParams();
	const router = useRouter();
	const {i18n, t} = useClientTranslation();

	const currentLang = languages.find(a => a.key === params.lang)!;

	// const redirectedPathname = (lang: ILanguage) => {
	// 	if (!pathname) return '/';
	//
	// 	// Handle the correct URL structure for your application
	// 	// Get the full origin (protocol + hostname + port)
	// 	const origin = window.location.origin;
	//
	// 	// Replace the language part in the path
	// 	const path = pathname.replace(`/${currentLang.key}/`, `/${lang.key}/`);
	//
	// 	// Return full URL with the correct path
	// 	return `${origin}${path}`;
	// };

	const redirectedPathname = (lang: string) => {
		if (!pathname) return '/';
		const segments = pathname.split('/');
		segments[1] = lang;
		return phpGWLink(['bookingfrontend', 'client', ...segments.filter(Boolean)]);
	};

	const handleLanguageChange = (lang: ILanguage) => {
		// Always use hard reload for language changes to ensure all components get new translations
		if (typeof window !== 'undefined') {
			// Store useful info in sessionStorage for debugging if needed
			sessionStorage.setItem('languageChanged', 'true');
			sessionStorage.setItem('prevLanguage', currentLang.key);
			sessionStorage.setItem('newLanguage', lang.key);

			// Force a full page reload by using window.location
			window.location.replace(redirectedPathname(lang.key));
		} else {
			// This fallback should never happen in a client component
			router.push(redirectedPathname(lang.key));
		}
	};
	// console.log(redirectedPathname(languages[1]))
	const dropdownOptions = languages.map(ver => ({
		value: ver.key,
		label: t(ver.label),
		icon: <ReactCountryFlag countryCode={ver.countryCode} svg/>
	}));
	return (
		<InlineResponsiveDropdown
			triggerContent={
				<ReactCountryFlag countryCode={currentLang.countryCode} svg/>
			}
			title={t('preferences.language')}
			options={dropdownOptions}
			currentValue={currentLang.key}
			onValueChange={(value) => redirectedPathname(value as any)}
		/>
	);
};

export default LanguageSwitcher;