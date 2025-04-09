import styles from './header.module.scss'
import HeaderMenuContent from "@/components/layout/header/header-menu-content";
import LanguageSwitcher from "@/app/i18n/language-switcher";
import UserMenu from "@/components/layout/header/user-menu/user-menu";
import ShoppingCartButton from "@/components/layout/header/shopping-cart/shopping-cart-button";
import logo_icon from '/public/logo_icon.svg';
import logo_title from '/public/logo_title.svg';
import Image from "next/image";
import ShoppingCartFab from "@/components/layout/header/shopping-cart/shopping-cart-fab";
import Link from "next/link";
import VersionSwitcher from "@/app/i18n/version-switcher";


interface HeaderProps {
}

const Header = async (props: HeaderProps) => {

    return (
        <nav className={`${styles.navbar}`}>
            <Link href={'/'} className={styles.logo}>
				{/*<Image src={} alt={}*/}
                {/*<Image src={logo_horizontal}*/}
                {/*       alt="Aktiv kommune logo"*/}
                {/*       width={192}*/}
				{/*	   quality={100}*/}
                {/*       className={styles.logoImg}/>*/}
                <Image src={logo_icon}
                       alt="Aktiv kommune logo"
                       // width={192}
					   quality={100}
                       className={styles.logoIcon}/>
                <Image src={logo_title}
                       alt="Aktiv kommune logo"
                       // width={192}
					   quality={100}
                       className={styles.logoTitle}/>
            </Link>
            {/*${baseUrl}*/}
            <HeaderMenuContent>
				<VersionSwitcher/>
                <LanguageSwitcher/>
                <ShoppingCartButton/>
                <UserMenu/>
                <ShoppingCartFab/>
            </HeaderMenuContent>
        </nav>
    );
}

export default Header


