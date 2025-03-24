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

    const redirectedPathname = (lang: ILanguage) => {
        if (!pathname) return '/';
        const segments = pathname.split('/');
        segments[1] = lang.key;
        return segments.join('/');
    };

    const handleLanguageChange = (lang: ILanguage) => {
        setIsOpen(false);
        
        // Set a flag in sessionStorage to indicate a language change
        if (typeof window !== 'undefined') {
            sessionStorage.setItem('languageChanged', 'true');
            sessionStorage.setItem('prevLanguage', currentLang.key);
            sessionStorage.setItem('newLanguage', lang.key);
            
            // Use hard reload approach for the most reliable language switch
            // This completely bypasses Next.js client-side navigation
            window.location.href = redirectedPathname(lang);
        } else {
            // Fallback to router navigation (shouldn't happen in client component)
            router.push(redirectedPathname(lang));
        }
    };

    return (
        <>
            <Button
                onClick={() => setIsOpen(true)}
                variant={"tertiary"}
                color={"accent"}
                data-size={'sm'}
            >
                <ReactCountryFlag countryCode={currentLang.countryCode} svg
                /> <FontAwesomeIcon icon={faChevronDown} />
            </Button>
            <Dialog open={isOpen} onClose={() => setIsOpen(false)}>

                <div style={{
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'center',
                    alignItems: 'center',
                    height: '100%',
                    gap:'5px'
                }}>
                    {languages.map((lang) => (
                        <Button
                            key={lang.key}
                            onClick={() => handleLanguageChange(lang)}
                            variant={currentLang.key === lang.key ? "secondary" : "tertiary"}
                            style={{
                                width: '200px', 
                                display: 'flex',
                                flexDirection: 'row',
                                justifyContent: 'flex-start'
                            }}
                        >
                            <ReactCountryFlag countryCode={lang.countryCode} svg /> {lang.label}
                        </Button>
                    ))}
                </div>
            </Dialog>
        </>
    );
};

export default LanguageSwitcher;