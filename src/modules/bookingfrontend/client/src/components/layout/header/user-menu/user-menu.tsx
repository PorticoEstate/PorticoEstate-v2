'use client'
import React, {FC, useEffect, useState} from 'react';
import {useBookingUser} from "@/service/hooks/api-hooks";
import {Button, Divider, Dropdown} from "@digdir/designsystemet-react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faChevronDown, faFutbol, faSignInAlt, faUser} from "@fortawesome/free-solid-svg-icons";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {phpGWLink} from "@/service/util";
import Link from "next/link";
import {useSearchParams} from "next/navigation";
import {useQueryClient} from "@tanstack/react-query";

interface UserMenuProps {
}

const UserMenu: FC<UserMenuProps> = (props) => {
    const [lastClickHistory, setLastClickHistory] = useState<string>();
    const t = useTrans();
    const bookingUserQ = useBookingUser();
    const {data: bookingUser, isLoading} = bookingUserQ;
    const searchparams = useSearchParams();
    const queryClient = useQueryClient();

    useEffect(() => {
        const clickHistory = searchparams.get('click_history');
        if (clickHistory !== lastClickHistory) {
            setLastClickHistory(clickHistory!);
            queryClient.invalidateQueries({queryKey: ['bookingUser']})
        }
    }, [searchparams, queryClient]);

    if (bookingUser?.is_logged_in) {
        return (<Dropdown.TriggerContext>
            <Dropdown.Trigger variant={'tertiary'} color={'accent'} data-size={'sm'}>
                <FontAwesomeIcon icon={faUser}/> {bookingUser.name} <FontAwesomeIcon icon={faChevronDown}/>
            </Dropdown.Trigger>
            <Dropdown>
                <Dropdown.List>
                    <Dropdown.Item>
                        <Dropdown.Button asChild>
                            <Link href={'/user'}
                                  className={'link-text link-text-unset normal'}>
                                <FontAwesomeIcon icon={faUser}/> {t('bookingfrontend.my page')}
                            </Link>
                        </Dropdown.Button>
                    </Dropdown.Item>
                </Dropdown.List>
                <Divider/>
                {!!bookingUser.delegates && bookingUser.delegates.length > 0 && (
                    <>
                        <Dropdown.List>
                            {bookingUser.delegates?.map((delegate) => <Dropdown.Item key={delegate.org_id}>
                                <Dropdown.Button asChild>

                                    <Link href={phpGWLink('bookingfrontend/', {
                                        menuaction: 'bookingfrontend.uiorganization.show',
                                        id: delegate.org_id
                                    }, false)}
                                          className={'link-text link-text-unset normal'}>
                                        <FontAwesomeIcon icon={faFutbol}/> {delegate.name}
                                    </Link>
                                </Dropdown.Button>

                            </Dropdown.Item>)}


                        </Dropdown.List>
                        <Divider/>
                    </>
                )}

                <Dropdown.List>

                    <Dropdown.Item>
                        <Dropdown.Button asChild>

                            <Link href={phpGWLink(['bookingfrontend', 'logout'])}
                                  className="link-text link-text-unset normal">
                                {t('common.logout')}
                            </Link>
                        </Dropdown.Button>

                    </Dropdown.Item>
                </Dropdown.List>
            </Dropdown>
        </Dropdown.TriggerContext>);
    }

    // if(1==1) {
    return (<Dropdown.TriggerContext>
        <Dropdown.Trigger variant={'tertiary'} color={'accent'} data-size={'sm'}>
            <FontAwesomeIcon icon={faSignInAlt}/> {t('common.login')}
        </Dropdown.Trigger>
        <Dropdown>
            <Dropdown.List>
                <Dropdown.Item>
                    <Dropdown.Button asChild>

                        <Link
                            href={phpGWLink(['bookingfrontend', 'login/'], {after: encodeURI(window.location.href.split('bookingfrontend')[1])})}

                            className={'link-text link-text-unset normal'}>
                            <FontAwesomeIcon icon={faSignInAlt}/> Privatperson
                        </Link>
                    </Dropdown.Button>
                </Dropdown.Item>
                <Divider/>

                <Dropdown.Item>
                    <Dropdown.Button asChild>

                        <Link href={phpGWLink('/', {
                            menuaction: 'booking.uiapplication.index',
                        }, false)}
                              className={'link-text link-text-unset normal'}>
                            <FontAwesomeIcon icon={faSignInAlt}/> Saksbehandler
                        </Link>
                    </Dropdown.Button>
                </Dropdown.Item>
            </Dropdown.List>
        </Dropdown>
    </Dropdown.TriggerContext>)
    // }

    // return (
    //     <Link
    //         href={phpGWLink(['bookingfrontend', 'login/'], {after: encodeURI(window.location.href.split('bookingfrontend')[1])})}
    //         className={'link-text link-text-unset'}>
    //         <Button variant={'tertiary'} color={'accent'} data-size={'sm'}>
    //             <FontAwesomeIcon icon={faSignInAlt}/> {t('common.login')}
    //         </Button>
    //     </Link>
    // );
}

export default UserMenu


