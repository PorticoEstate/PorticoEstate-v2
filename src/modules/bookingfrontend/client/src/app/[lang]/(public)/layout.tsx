// import '@digdir/designsystemet-css';
// import '@digdir/designsystemet-theme';
import React, {FC, PropsWithChildren} from "react";
import styles from './layout.module.scss'
import Header from "@/components/layout/header/header";
import Footer from "@/components/layout/footer/footer";
import Providers from "@/app/providers";
import ServerMessages from "@/components/server-messages/server-messages";


interface PublicLayoutProps extends PropsWithChildren {
    params: {
        lang: string
    }
}


const PublicLayout: FC<PublicLayoutProps> = (props) => {
    return (
        <Providers lang={props.params.lang}>
            <Header/>
			{/* Information alert */}
			<ServerMessages />
            <div className={styles.mainContent}>
                {/*<InternalNav/>*/}
                {props.children}
            </div>
            <Footer lang={props.params.lang}/>
        </Providers>
    );
}
export default PublicLayout