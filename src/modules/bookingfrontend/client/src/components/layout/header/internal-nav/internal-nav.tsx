'use client'
import {FC} from 'react';
import styles from './internal-nav.module.scss'
import Link from "next/link";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {phpGWLink} from "@/service/util";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faArrowLeft} from "@fortawesome/free-solid-svg-icons";
import {usePathname} from "next/navigation";
import {useIsMobile} from "@/service/hooks/is-mobile";
interface InternalNavProps {
}

const InternalNav: FC<InternalNavProps> = (props) => {
    const t=useTrans();
    const pathname = usePathname()
    const isMobile = useIsMobile();
    if(pathname.split('/').length === 2) {
        return null;
    }

    if(pathname.split('/')[2] === 'user') {
        return null;
    }
    return (
        <div className={`${styles.internalNavContainer} mx-standard`}>
            <Link className={'link-text link-text-primary'} href={phpGWLink('bookingfrontend/', {}, false)}>
                <FontAwesomeIcon icon={faArrowLeft} />
                {t('bookingfrontend.home_page')}
            </Link>
        </div>
    );
}

export default InternalNav


