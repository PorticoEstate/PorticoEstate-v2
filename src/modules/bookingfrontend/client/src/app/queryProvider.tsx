'use client'
import {FC, PropsWithChildren} from 'react';
import {getQueryClient} from "@/service/query-client";
import {QueryClientProvider} from "@tanstack/react-query";


const QueryProvider: FC<PropsWithChildren> = ({children}) => {
    const queryClient = getQueryClient()

    return (
        <QueryClientProvider client={queryClient}>
            {children}
        </QueryClientProvider>
    );
}

export default QueryProvider


