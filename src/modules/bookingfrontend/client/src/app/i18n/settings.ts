import {InitOptions} from 'i18next';
import {phpGWLink} from "@/service/util";

export interface ILanguage {
    key: string;
    label: string;
    countryCode: string;
}

export const fallbackLng: ILanguage = {key: 'no', label: 'Bokmål', countryCode: "no"};
export const languages: ILanguage[] = [
    fallbackLng,
    {key: 'en', label: 'English', countryCode: "gb"},
    {key: 'nn', label: 'Nynorsk', countryCode: "no"}
];
export const defaultNS = 'translation';
export const cookieName = 'selected_lang';

export function getOptions(lng: ILanguage = fallbackLng, ns: string | string[] = defaultNS): InitOptions {
    return {
        supportedLngs: languages.map(e => e.key),
        fallbackLng: fallbackLng.key,
        lng: lng.key,
        fallbackNS: defaultNS,
        defaultNS: defaultNS,
        ns: ns,
        interpolation: {
            escapeValue: false,
        },
        saveMissing: true,
        parseMissingKeyHandler: (key: string) => {
            return `TRANSLATION MISSING FOR "${key}"`;
        }
    }
}

export const getTranslationURL = (lang: ILanguage): string => {
    return phpGWLink(["bookingfrontend", 'lang', lang.key], null, true);
};
