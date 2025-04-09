'use client'
import React, {useState} from 'react';
import {useClientTranslation} from '@/app/i18n/ClientTranslationProvider';
import {ILanguage, languages} from '@/app/i18n/settings';
import Dialog from "@/components/dialog/mobile-dialog";
import {Button} from "@digdir/designsystemet-react";
import {useParams, usePathname, useRouter} from "next/navigation";
import Link from "next/link";
import ReactCountryFlag from "react-country-flag";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faChevronDown} from "@fortawesome/free-solid-svg-icons";
import {phpGWLink} from "@/service/util";

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
	const [isOpen, setIsOpen] = useState(false);

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

	const redirectedPathname = (lang: ILanguage) => {
		if (!pathname) return '/';
		const segments = pathname.split('/');
		segments[1] = lang.key;
		return phpGWLink(['bookingfrontend','client', ...segments.filter(Boolean)]);
	};

	const handleLanguageChange = (lang: ILanguage) => {
		setIsOpen(false);

		// Always use hard reload for language changes to ensure all components get new translations
		if (typeof window !== 'undefined') {
			// Store useful info in sessionStorage for debugging if needed
			sessionStorage.setItem('languageChanged', 'true');
			sessionStorage.setItem('prevLanguage', currentLang.key);
			sessionStorage.setItem('newLanguage', lang.key);

			// Force a full page reload by using window.location
			window.location.replace(redirectedPathname(lang));
		} else {
			// This fallback should never happen in a client component
			router.push(redirectedPathname(lang));
		}
	};
	// console.log(redirectedPathname(languages[1]))

	return (
		<>
			<Button
				onClick={() => setIsOpen(true)}
				variant={"tertiary"}
				color={"accent"}
				data-size={'sm'}
			>
				<ReactCountryFlag countryCode={currentLang.countryCode} svg
				/> <FontAwesomeIcon icon={faChevronDown}/>
			</Button>
			<Dialog open={isOpen} onClose={() => setIsOpen(false)}>

				<div style={{
					display: 'flex',
					flexDirection: 'column',
					justifyContent: 'center',
					alignItems: 'center',
					height: '100%',
					gap: '5px'
				}}>
					{languages.map((lang) => (
						<Button
							asChild
							key={lang.key}
							// onClick={() => handleLanguageChange(lang)}
							variant={currentLang.key === lang.key ? "secondary" : "tertiary"}
							style={{
								width: '200px',
								display: 'flex',
								flexDirection: 'row',
								justifyContent: 'flex-start'
							}}
						>
							<a
								key={lang.key}
								href={redirectedPathname(lang)}
								className={'link-text link-text-unset'}
								style={{width: 200}}
								rel="noopener noreferrer"
							>

								<ReactCountryFlag countryCode={lang.countryCode} svg/> {lang.label}
							</a>

						</Button>
					))}
				</div>
			</Dialog>
		</>
	);
};

export default LanguageSwitcher;