'use client'
import React, {FC} from 'react';
import {useBookingUser} from "@/service/hooks/api-hooks";
import {Button, Divider, Dropdown} from "@digdir/designsystemet-react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faChevronDown, faFutbol, faSignInAlt, faUser} from "@fortawesome/free-solid-svg-icons";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {phpGWLink} from "@/service/util";
import Link from "next/link";

interface UserMenuProps {
}

const UserMenu: FC<UserMenuProps> = (props) => {
    const t = useTrans();
    const bookingUserQ = useBookingUser();
    const {data: bookingUser, isLoading} = bookingUserQ;

    if (bookingUser?.is_logged_in) {
        return (<Dropdown.Context>
            <Dropdown.Trigger variant={'tertiary'} color={'accent'} size={'sm'}>
                <FontAwesomeIcon icon={faUser}/> {bookingUser.name} <FontAwesomeIcon icon={faChevronDown}/>
            </Dropdown.Trigger>
            <Dropdown>
                <Dropdown.List>
                    <Dropdown.Item asChild>
                        <Link href={'/user'}
                              className={'link-text link-text-unset normal'}>
                            <FontAwesomeIcon icon={faUser}/> {t('bookingfrontend.my page')}
                        </Link>
                    </Dropdown.Item>
                </Dropdown.List>
                <Divider/>
                <Dropdown.List>
                    {bookingUser.delegates?.map((delegate) => <Dropdown.Item asChild key={delegate.org_id}>
                        <Link href={phpGWLink('bookingfrontend/', {
                            menuaction: 'bookingfrontend.uiorganization.show',
                            id: delegate.org_id
                        }, false)}
                              className={'link-text link-text-unset normal'}>
                            <FontAwesomeIcon icon={faFutbol}/> {delegate.name}
                        </Link>
                    </Dropdown.Item>)}


                </Dropdown.List>
                <Divider/>
                <Dropdown.List>

                    <Dropdown.Item asChild>
                        <Link href={phpGWLink(['bookingfrontend', 'logout'])}
                              className="link-text link-text-unset normal">
                            {t('common.logout')}
                        </Link>

                    </Dropdown.Item>

                </Dropdown.List>
            </Dropdown>
        </Dropdown.Context>);
    }

    return (
        <Link
            href={phpGWLink(['bookingfrontend', 'login/'], {after: encodeURI(window.location.href.split('bookingfrontend')[1])})}
            className={'link-text link-text-unset'}>
            <Button variant={'tertiary'} color={'accent'} size={'sm'}>
                <FontAwesomeIcon icon={faSignInAlt}/> {t('common.login')}
            </Button>
        </Link>
    );
}

export default UserMenu


