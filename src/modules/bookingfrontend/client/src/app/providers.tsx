// In Next.js, this file would be called: app/providers.jsx

// Since QueryClientProvider relies on useContext under the hood, we have to put 'use client' on top
import {FC, PropsWithChildren} from "react";
import {LoadingProvider} from "@/components/loading-wrapper/LoadingContext";
import ClientTranslationProvider from "@/app/i18n/ClientTranslationProvider";
import PrefetchWrapper from "@/components/loading-wrapper/PrefetchWrapper";
import LoadingIndicationWrapper from "@/components/loading-wrapper/LoadingIndicationWrapper";
import {ReactQueryDevtools} from "@tanstack/react-query-devtools";
import QueryProvider from "@/app/queryProvider";
import {getTranslation} from "@/app/i18n";
import {ToastProvider} from "@/components/toast/toast-context";
import ToastContainer from "@/components/toast/toast";
import {WebSocketProvider} from "@/service/websocket/websocket-context";
import ServiceWorkerProvider from "@/service/websocket/service-worker-provider";
import ShoppingCartProvider from "@/components/layout/header/shopping-cart/shopping-cart-provider";

const Providers: FC<PropsWithChildren & { lang: string }> = async ({children, lang}) => {
	// NOTE: Avoid useState when initializing the query client if you don't
	//       have a suspense boundary between this and the code that may
	//       suspend because React will throw away the client on the initial
	//       render if it suspends and there is no boundary
	// Fetch translations on the server
	const {t, i18n} = await getTranslation(lang);

	// Get the translations object to pass to the client
	const translations = (i18n.getResourceBundle(lang, 'translation') || {}) as Record<string, string>;

	return (
		<LoadingProvider>
			<ClientTranslationProvider lang={lang} initialTranslations={translations}>
				<QueryProvider>
					<ServiceWorkerProvider disableServiceWorker>
						<WebSocketProvider disableServiceWorker>

							<ShoppingCartProvider>
								<ToastProvider>

									<PrefetchWrapper>
										<LoadingIndicationWrapper loadingString={t('common.loading')}>
											{children}
											<ToastContainer/>
										</LoadingIndicationWrapper>
										<ReactQueryDevtools initialIsOpen={false} buttonPosition={'bottom-left'}/>
									</PrefetchWrapper>
								</ToastProvider>

							</ShoppingCartProvider>
						</WebSocketProvider>
					</ServiceWorkerProvider>
				</QueryProvider>
			</ClientTranslationProvider>
		</LoadingProvider>
	)
}

export default Providers;
