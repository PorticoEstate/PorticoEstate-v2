// import '@digdir/designsystemet-css';
// import '@digdir/designsystemet-theme';
import React, {FC, PropsWithChildren} from "react";
import styles from './layout.module.scss'
import Header from "@/components/layout/header/header";
import Footer from "@/components/layout/footer/footer";
import Providers from "@/app/providers";
import ServerMessageToastHandler from "@/components/server-messages/server-message-toast-handler";


interface PublicLayoutProps extends PropsWithChildren {
    params: Promise<{
        lang: string
    }>
}


const PublicLayout: FC<PublicLayoutProps> = async (props) => {
    // Await params in Next.js 15+
    const params = await props.params;
    
    return (
        <Providers lang={params.lang}>
            <Header/>
            {/* Server message handler (converts messages to toasts) */}
            <ServerMessageToastHandler />
            <div className={styles.mainContent}>
                {/*<InternalNav/>*/}
                {props.children}
            </div>
            <Footer lang={params.lang}/>
        </Providers>
    );
}
export default PublicLayout