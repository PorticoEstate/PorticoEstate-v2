import {ILanguage} from "@/app/i18n/settings";

import '@digdir/designsystemet-css';
import '@digdir/designsystemet-theme';
import "@/app/globals.scss";
import ReactQueryProvider from "@/app/providers";
import {FC, PropsWithChildren} from "react";
import ClientTranslationProvider from "@/app/i18n/ClientTranslationProvider";
import {LoadingProvider} from "@/components/loading-wrapper/LoadingContext";
import LoadingIndicationWrapper from "@/components/loading-wrapper/LoadingIndicationWrapper";


interface RootLayoutProps extends PropsWithChildren {
    params: {
        lang: string;
    }

}

const RootLayout: FC<RootLayoutProps> = (props) => {

    return (

        <LoadingProvider>
            <ClientTranslationProvider lang={props.params.lang}>
                <ReactQueryProvider>
                    <LoadingIndicationWrapper>
                        {props.children}
                    </LoadingIndicationWrapper>
                </ReactQueryProvider>
            </ClientTranslationProvider>
        </LoadingProvider>
    );
}
export default RootLayout