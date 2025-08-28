import {dir} from 'i18next'
import {languages} from "@/app/i18n/settings";
import {setLayoutLanguage} from "@/app/i18n";

import type {Metadata} from "next";
import {Roboto, Poppins} from "next/font/google";
import '@digdir/designsystemet-css';
// import '@digdir/designsystemet-theme';
import '@porticoestate/design-tokens';
import "@/app/globals.scss";
import {FC, PropsWithChildren} from "react";
import {fetchServerSettings} from "@/service/api/api-utils";

export async function generateStaticParams() {
    return languages.map((lng) => ({lng}))
}


export async function generateMetadata(): Promise<Metadata> {
    const serverSettings = await fetchServerSettings();
	const basePath = process.env.NEXT_PUBLIC_BASE_PATH || '';
    return {
        title: {
            template: `%s - ${serverSettings.site_title}`,
            default: `${serverSettings.site_title}`, // a default is required when creating a template
        },
		icons: {
			icon: [
				{ url: `${basePath}/favicon-32x32.png`, sizes: '32x32', type: 'image/png' },
				{ url: `${basePath}/favicon-192x192.png`, sizes: '192x192', type: 'image/png' },
			],
			apple: [
				{ url: `${basePath}/apple-touch-icon.png`, sizes: '180x180', type: 'image/png' },
			],
			other: [
				{
					rel: 'msapplication-TileImage',
					url: `${basePath}/mstile-270x270.png`,
				},
			],
		},

    }
}



const roboto = Roboto({weight: ['100', '300', '400', '500', '700', '900'], subsets: ['latin']});
const poppins = Poppins({weight: ['100', '300', '400', '500', '700', '900'], subsets: ['latin'], variable: '--font-poppins'});

export const revalidate = 120;



interface RootLayoutProps extends PropsWithChildren {
    params: {
        lang: string;
    }

}

const RootLayout: FC<RootLayoutProps> = (props) => {
    // Store the language from URL params for future use
    setLayoutLanguage(props.params.lang);

    return (
        <html lang={props.params.lang} dir={dir(props.params.lang)}>
        <head>
            <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, viewport-fit=cover" />
            <script
                async
                type="text/javascript"
                src="https://checkout.vipps.no/checkout-button/v1/vipps-checkout-button.js"
            />
        </head>
        <body className={`${roboto.className} ${poppins.variable}`}>
        <div className={'container-xxl container-fluid'}>
            {props.children}
        </div>
        </body>
        </html>
    );
}
export default RootLayout