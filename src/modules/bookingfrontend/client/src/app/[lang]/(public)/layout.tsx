import '@digdir/designsystemet-css';
import '@digdir/designsystemet-theme';
import "@/app/globals.scss";
import {FC, PropsWithChildren} from "react";
import styles from './layout.module.scss'
import Header from "@/components/layout/header/header";
import InternalNav from "@/components/layout/header/internal-nav/internal-nav";
import Footer from "@/components/layout/footer/footer";
import Providers from "@/app/providers";


interface PublicLayoutProps extends PropsWithChildren {
    params: {
        lang: string
    }
}


const PublicLayout: FC<PublicLayoutProps> = (props) => {

    return (
        <Providers lang={props.params.lang}>
            <Header/>
            <div className={styles.mainContent}>
                <InternalNav/>
                {props.children}
            </div>
            <Footer lang={props.params.lang}/>
        </Providers>
    );
}
export default PublicLayout