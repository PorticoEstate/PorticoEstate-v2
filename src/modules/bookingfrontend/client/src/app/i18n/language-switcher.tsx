'use client'
import React, {useState} from 'react';
import {useClientTranslation} from '@/app/i18n/ClientTranslationProvider';
import {ILanguage, languages} from '@/app/i18n/settings';
import Dialog from "@/components/dialog/mobile-dialog";
import {Button} from "@digdir/designsystemet-react";
import {useParams, usePathname} from "next/navigation";
import Link from "next/link";
import ReactCountryFlag from "react-country-flag";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faChevronDown} from "@fortawesome/free-solid-svg-icons";

const LanguageSwitcher: React.FC = () => {
    const pathname = usePathname();
    const params = useParams();
    const {i18n, t} = useClientTranslation();
    const [isOpen, setIsOpen] = useState(false);

    const currentLang = languages.find(a => a.key === params.lang)!;

    const redirectedPathname = (lang: ILanguage) => {
        if (!pathname) return '/';
        const segments = pathname.split('/');
        segments[1] = lang.key;
        return segments.join('/');
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
                        <Link
                            key={lang.key}
                            href={redirectedPathname(lang)}
                            locale={lang.key}
                            onClick={() => setIsOpen(false)}
                            className={'link-text link-text-unset'}
                            style={{width: 200}}
                        >
                            <Button
                                variant={currentLang.key === lang.key ? "secondary" : "tertiary"}
                                style={{width: '100%', display: 'flex',
                                    flexDirection: 'row',
                                    justifyContent: 'flex-start'}}
                            >
                                <ReactCountryFlag countryCode={lang.countryCode} svg
                                /> {lang.label}
                            </Button>
                        </Link>
                    ))}
                </div>
                {/*</DialogContent>*/}
            </Dialog>
        </>
    );
};

export default LanguageSwitcher;