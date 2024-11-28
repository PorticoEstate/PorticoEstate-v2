// In Next.js, this file would be called: app/providers.jsx
'use client'

// Since QueryClientProvider relies on useContext under the hood, we have to put 'use client' on top
import {
    QueryClientProvider,
} from '@tanstack/react-query'
import {FC, PropsWithChildren} from "react";
import {getQueryClient} from "@/service/query-client";
import {LoadingProvider} from "@/components/loading-wrapper/LoadingContext";
import ClientTranslationProvider from "@/app/i18n/ClientTranslationProvider";
import PrefetchWrapper from "@/components/loading-wrapper/PrefetchWrapper";
import LoadingIndicationWrapper from "@/components/loading-wrapper/LoadingIndicationWrapper";
import {ReactQueryDevtools} from "@tanstack/react-query-devtools";

const Providers: FC<PropsWithChildren & {lang: string}> = ({children, lang}) => {
    // NOTE: Avoid useState when initializing the query client if you don't
    //       have a suspense boundary between this and the code that may
    //       suspend because React will throw away the client on the initial
    //       render if it suspends and there is no boundary
    const queryClient = getQueryClient()

    return (
        <LoadingProvider>
            <ClientTranslationProvider lang={lang}>
                <QueryClientProvider client={queryClient}>
                    <PrefetchWrapper>
                        <LoadingIndicationWrapper>
                            {children}
                        </LoadingIndicationWrapper>
                        <ReactQueryDevtools initialIsOpen={false} />
                    </PrefetchWrapper>
                </QueryClientProvider>
            </ClientTranslationProvider>
        </LoadingProvider>
    )
}

export default Providers;