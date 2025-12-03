// import '@digdir/designsystemet-css';
// import '@digdir/designsystemet-theme';
import React, {FC, PropsWithChildren} from "react";
import styles from './layout.module.scss'
import Header from "@/components/layout/header/header";
import Footer from "@/components/layout/footer/footer";
import Providers from "@/app/providers";
import ServerMessageToastHandler from "@/components/server-messages/server-message-toast-handler";
import UserCreation from "@/components/layout/user-creation/user-creation";


interface PublicLayoutProps extends PropsWithChildren {
    params: {
        lang: string
    }
}


const PublicLayout: FC<PublicLayoutProps> = (props) => {
    return (
        <Providers lang={props.params.lang}>
            <Header/>
            {/* Server message handler (converts messages to toasts) */}
            <ServerMessageToastHandler />
			<UserCreation/>
            <div className={styles.mainContent}>
                {/*<InternalNav/>*/}
                {props.children}
            </div>
            <Footer lang={props.params.lang}/>
        </Providers>
    );
}
export default PublicLayout