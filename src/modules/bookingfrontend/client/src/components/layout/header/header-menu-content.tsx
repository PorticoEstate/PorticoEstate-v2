'use client'
import React, {FC, useState, PropsWithChildren} from 'react';
import { Button } from "@digdir/designsystemet-react";
import styles from './header-menu-content.module.scss';
import {useScrollLockEffect} from '@/contexts/ScrollLockContext';
import {XMarkIcon} from "@navikt/aksel-icons";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";

interface HeaderMenuContentProps extends PropsWithChildren{}

const HeaderMenuContent: FC<HeaderMenuContentProps> = (props) => {
    const [drawerOpen, setDrawerOpen] = useState<boolean>(false);
	const t = useTrans();
    // Use scroll lock context to manage body overflow
    useScrollLockEffect('header-menu', drawerOpen);

    const toggleDrawer = () => {
        setDrawerOpen(!drawerOpen);
    };

    return (
        <div>
            <button onClick={toggleDrawer} className={`${styles.toggleMenuBtn}`} >
                <span></span>
                <span></span>
                <span></span>
            </button>
            <div className={`${styles.overlay} ${drawerOpen ? styles.active : ''}`} onClick={toggleDrawer}></div>
            <div id="mySidenav" className={`${styles.sidenav} ${drawerOpen ? styles.open : ''}`}>
				<div className={styles.sideNavHeader}>
					<Button
						icon={true}
						variant="tertiary"
						aria-label={t('common.close_dialog')}
						onClick={toggleDrawer}
						className={styles.closebtn}
						tabIndex={-1}
						data-size={'md'}
					>
						<XMarkIcon fontSize="1.25rem"/>
					</Button>
					{/*<Button variant={"tertiary"} className={styles.closebtn} onClick={toggleDrawer}>&times;</Button>*/}
				</div>
                {props.children}
                {/*<a href="#">About</a>*/}
                {/*<a href="#">Services</a>*/}
                {/*<a href="#">Clients</a>*/}
                {/*<a href="#">Contact</a>*/}
            </div>
        </div>
    );
}

export default HeaderMenuContent