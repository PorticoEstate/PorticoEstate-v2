import {createInstance, i18n} from 'i18next';
import {initReactI18next} from 'react-i18next/initReactI18next';
import {cookieName, defaultNS, fallbackLng, getOptions, getTranslationURL, ILanguage, languages} from './settings';
import { cache } from 'react';

// Store the current language from layout params
let currentLayoutLang: string | undefined;

// Simple in-memory cache for translations - but create a new instance for each request
const createTranslationCache = () => new Map<string, i18n>();

// Use React's cache to ensure a fresh cache for each request
const getRequestCache = cache(() => createTranslationCache());

const initI18next = async (lng: ILanguage, ns?: string | string[]): Promise<i18n> => {
	const i18nInstance = createInstance();
	await i18nInstance
		.use(initReactI18next)
		.init(getOptions(lng, ns));

	// Fetch translations from the API, with cache-busting only in development
	const translationUrl = getTranslationURL(lng);
	let url = new URL(translationUrl);

	// Only add cache-busting in development mode
	if (process.env.NODE_ENV === 'development') {
		url.searchParams.append('t', Date.now().toString());
	}

	const response = await fetch(url, {
		cache: process.env.NODE_ENV === 'production' ? 'force-cache' : 'no-store'
	});
	const translations: Record<string, string> = await response.json();

	// Add the fetched resources to i18next
	i18nInstance.addResourceBundle(lng.key, (Array.isArray(ns) ? ns[0] : ns) as any, translations, true, true);

	return i18nInstance;
};

// Call this function from layout to store the language
export function setLayoutLanguage(lang: string) {
	currentLayoutLang = lang;
	// console.log("Layout language set to:", lang);
}

export async function getTranslation(lng?: string, ns?: string | string[]): Promise<{
	t: (key: string) => string,
	i18n: i18n
}> {
	// Priority: 1. Explicitly passed language, 2. Layout language, 3. Cookie
	let choosenLngString = lng;

	// Use language from URL path param if it's available
	if (choosenLngString) {
		// console.log("Using explicitly passed language:", choosenLngString);
	} else if (currentLayoutLang) {
		// Use the language stored from layout params
		choosenLngString = currentLayoutLang;
		// console.log("Using language from layout params:", choosenLngString);
	} else if (typeof window === 'undefined') {
		// Last resort: fallback to cookie when not in browser and no other language is available
		const cookies = require("next/headers").cookies
		const cookieStore = cookies();
		choosenLngString = cookieStore.get(cookieName as any)?.value
		// console.log("Using language from cookie (fallback):", choosenLngString)
	}

	let language = languages.find(e => e.key === choosenLngString);

	if(!language) {
		// console.log("Language not found, using fallback");
		language = fallbackLng;
	}

	// Get the cache for this request
	// const i18nCache = getRequestCache();

	// Check cache for the specific language and namespace combination
	// const cacheKey = `${language.key}-${Array.isArray(ns) ? ns.join(',') : ns || defaultNS}`;

	// Always use cache in production for better performance, but allow disabling in dev
	// const isDev = process.env.NODE_ENV === 'development';

	// if (i18nCache.has(cacheKey)) {
	// 	// Return cached instance if available
	// 	const cachedInstance = i18nCache.get(cacheKey)!;
	// 	return {
	// 		t: cachedInstance.getFixedT(language.key as any, (Array.isArray(ns) ? ns[0] : ns) as any),
	// 		i18n: cachedInstance
	// 	};
	// }

	// console.log("using language", language.key)
	const i18nextInstance = await initI18next(language, ns || defaultNS);
	// i18nCache.set(cacheKey, i18nextInstance); // Store instance in cache
	return {
		t: i18nextInstance.getFixedT(language.key as any, (Array.isArray(ns) ? ns[0] : ns) as any),
		i18n: i18nextInstance
	};
}