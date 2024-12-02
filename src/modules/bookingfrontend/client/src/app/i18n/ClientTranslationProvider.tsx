'use client'
import React, {createContext, useContext, useEffect, useState} from 'react';
import {createInstance} from 'i18next';
import {initReactI18next} from 'react-i18next/initReactI18next';
import {getOptions} from '@/app/i18n/settings';
import {useLoadingContext} from "@/components/loading-wrapper/LoadingContext";

interface TranslationContextType {
    t: (key: string, options?: any) => string;
    i18n: any;
}

interface TranslationProviderProps {
    children: React.ReactNode;
    lang: string;
    initialTranslations: Record<string, string>;
}

const TranslationContext = createContext<TranslationContextType | null>(null);

export const useClientTranslation = () => {
    const context = useContext(TranslationContext);
    if (!context) {
        throw new Error('useClientTranslation must be used within a TranslationProvider');
    }
    return context;
};

export const useTrans = () => {
    const context = useClientTranslation();
    return context.t;
};

const TranslationProvider: React.FC<TranslationProviderProps> = ({
                                                                     children,
                                                                     lang,
                                                                     initialTranslations
                                                                 }) => {
    const {setLoadingState} = useLoadingContext();
    const [translationContext, setTranslationContext] = useState<TranslationContextType | null>(null);

    useEffect(() => {
        const initializeTranslations = async () => {
            setLoadingState('translation', true, 'hard');

            const i18nInstance = createInstance();
            await i18nInstance
                .use(initReactI18next)
                .init(getOptions({ key: lang } as any));

            // Add the server-side fetched translations
            i18nInstance.addResourceBundle(lang, 'translation', initialTranslations, true, true);

            setTranslationContext({
                t: i18nInstance.getFixedT(lang, 'translation'),
                i18n: i18nInstance
            });

            setLoadingState('translation', false, 'hard');
        };

        initializeTranslations();
    }, [lang, initialTranslations, setLoadingState]);

    return (
        <TranslationContext.Provider value={translationContext}>
            {children}
        </TranslationContext.Provider>
    );
};

export default TranslationProvider;