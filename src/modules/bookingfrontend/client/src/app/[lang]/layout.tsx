import {dir} from 'i18next'
import {languages} from "@/app/i18n/settings";

import type {Metadata} from "next";
import {Roboto, Poppins} from "next/font/google";
import '@digdir/designsystemet-css';
// import '@digdir/designsystemet-theme';
import '@porticoestate/design-tokens';
import "@/app/globals.scss";
import "react-datepicker/dist/react-datepicker.css";
import {FC, PropsWithChildren} from "react";
import {fetchServerSettings} from "@/service/api/api-utils";

export async function generateStaticParams() {
    return languages.map((lng) => ({lng}))
}


export async function generateMetadata(): Promise<Metadata> {
    const serverSettings = await fetchServerSettings();
    return {
        title: {
            template: `%s - ${serverSettings.site_title}`,
            default: `${serverSettings.site_title}`, // a default is required when creating a template
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

    return (
        <html lang={props.params.lang} dir={dir(props.params.lang)}>
        <body className={`${roboto.className} ${poppins.variable}`}>
        <div className={'container-xxl container-fluid'}>
            {props.children}
        </div>
        </body>
        </html>
    );
}
export default RootLayout