'use client'
import React, {FC, useState, PropsWithChildren} from 'react';
import {Button, Divider, Dropdown} from "@digdir/designsystemet-react";
import styles from './header-menu-content.module.scss';
import {useScrollLockEffect} from '@/contexts/ScrollLockContext';
import {ChevronDownIcon, PersonFillIcon, TenancyIcon} from "@navikt/aksel-icons";
import Link from "next/link";
import {phpGWLink} from "@/service/util";
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
            {/*<button onClick={toggleDrawer} className={`${styles.toggleMenuBtn}`} >*/}
            {/*    <span></span>*/}
            {/*    <span></span>*/}
            {/*    <span></span>*/}
            {/*</button>*/}
			<Dropdown.TriggerContext>
				<Dropdown.Trigger variant={'tertiary'} color={'accent'} data-size={'sm'} className={`${styles.toggleMenuBtn}`}>
					<span></span>
					<span></span>
					<span></span>
				</Dropdown.Trigger>
				<Dropdown>
				<Dropdown.List>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')}
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 1
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 2
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 3
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 4
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 5
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 6
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 7
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
					</Dropdown.List>
					<Divider/>
					<Dropdown.List>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')}
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 1
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 2
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 3
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 4
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 5
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 6
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 7
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
					</Dropdown.List>
					<Divider/>
					<Dropdown.List>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')}
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 1
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 2
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 3
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 4
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 5
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 6
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
						<Dropdown.Item>
							<Dropdown.Button asChild>
								<Link href={'/user'}
									  className={'link-text link-text-unset normal'}>
									<PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')} 7
								</Link>
							</Dropdown.Button>
						</Dropdown.Item>
					</Dropdown.List>
					<Divider/>

					<Dropdown.List>

						<Dropdown.Item>
							<Dropdown.Button asChild>
								<a
									href={phpGWLink(['bookingfrontend', 'logout/'])}

									className={'link-text link-text-unset normal'}>
									{t('common.logout')}
								</a>
							</Dropdown.Button>

						</Dropdown.Item>
					</Dropdown.List>
				</Dropdown>
			</Dropdown.TriggerContext>
            <div className={`${styles.overlay} ${drawerOpen ? styles.active : ''}`} onClick={toggleDrawer}></div>

            <div id="mySidenav" className={`${styles.sidenav} ${drawerOpen ? styles.open : ''}`}>
                <Button variant={"tertiary"} className={styles.closebtn} onClick={toggleDrawer}>&times;</Button>

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