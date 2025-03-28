import styles from './header.module.scss'
import {fetchServerSettings} from "@/service/api/api-utils";
import ClientPHPGWLink from "@/components/layout/header/ClientPHPGWLink";
import HeaderMenuContent from "@/components/layout/header/header-menu-content";
import LanguageSwitcher from "@/app/i18n/language-switcher";
import UserMenu from "@/components/layout/header/user-menu/user-menu";
import ShoppingCartButton from "@/components/layout/header/shopping-cart/shopping-cart-button";
import logo from '/public/logo_aktiv_kommune.png';
import logo_horizontal from '/public/logo_aktiv_kommune_horizontal.png';
import Image from "next/image";
import ShoppingCartFab from "@/components/layout/header/shopping-cart/shopping-cart-fab";
import Link from "next/link";


interface HeaderProps {
}

const Header = async (props: HeaderProps) => {

    return (
        <nav className={`${styles.navbar}`}>
            <Link href={'/'} className={styles.logo}>
                <Image src={logo_horizontal}
                       alt="Aktiv kommune logo"
                       width={192}
                       className={styles.logoImg}/>
                <Image src={logo}
                       alt="Aktiv kommune logo"
                       width={80}
                       className={`${styles.logoImg} ${styles.logoImgDesktop}`}/>
            </Link>
            {/*${baseUrl}*/}
            <HeaderMenuContent>
                <LanguageSwitcher/>
                <ShoppingCartButton/>
                <UserMenu/>
                <ShoppingCartFab/>
            </HeaderMenuContent>
        </nav>
    );
}

export default Header


