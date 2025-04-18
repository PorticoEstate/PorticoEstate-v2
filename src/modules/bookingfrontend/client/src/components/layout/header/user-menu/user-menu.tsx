'use client'
import React, {FC, useEffect, useState} from 'react';
import {useBookingUser, useLogin, useLogout} from "@/service/hooks/api-hooks";
import {Divider, Dropdown} from "@digdir/designsystemet-react";
import { EnterIcon, PersonFillIcon, ChevronDownIcon, TenancyIcon } from "@navikt/aksel-icons";
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

    const login = useLogin();
    const logout = useLogout();

    useEffect(() => {
        const clickHistory = searchparams.get('click_history');
        if (clickHistory !== lastClickHistory) {
            setLastClickHistory(clickHistory!);
            queryClient.invalidateQueries({queryKey: ['bookingUser']})
        }
    }, [searchparams, queryClient]);


    const handleLogin = async () => {
        try {
            await login.mutateAsync();
        } catch (error) {
            console.error('Login failed:', error);
        }
    };

    const handleLogout = async () => {
        try {
            await logout.mutateAsync();
        } catch (error) {
            console.error('Logout failed:', error);
        }
    };


    if (bookingUser?.is_logged_in) {
        return (<Dropdown.TriggerContext>
            <Dropdown.Trigger variant={'tertiary'} color={'accent'} data-size={'sm'}>
                <PersonFillIcon width="1.875rem" height="1.875rem" /> {bookingUser.name} <ChevronDownIcon fontSize="1.25rem" />
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
                                        <TenancyIcon fontSize="1.25rem" /> {delegate.name}
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
							<a
								href={phpGWLink(['bookingfrontend', 'logout/'])}

								className={'link-text link-text-unset normal'}>
								{t('common.logout')}
							</a>
						</Dropdown.Button>

                    </Dropdown.Item>
                </Dropdown.List>
            </Dropdown>
        </Dropdown.TriggerContext>);
    }

    // if(1==1) {
    return (<Dropdown.TriggerContext>
        <Dropdown.Trigger variant={'tertiary'} color={'accent'} data-size={'sm'}>
            <EnterIcon width="1.875rem" height="1.875rem" /> {t('common.login')}
        </Dropdown.Trigger>
        <Dropdown>
            <Dropdown.List>
                <Dropdown.Item>
                    <Dropdown.Button asChild>

                        <a
                            href={phpGWLink(['bookingfrontend', 'login/'], {after: encodeURI(window.location.href.split('bookingfrontend')[1])})}

                            className={'link-text link-text-unset normal'}>
                            <EnterIcon width="1.25rem" height="1.25rem" /> {t('bookingfrontend.private_person')}
                        </a>
                    </Dropdown.Button>
                </Dropdown.Item>
                <Divider/>

                <Dropdown.Item>
                    <Dropdown.Button asChild>

                        <Link href={phpGWLink('/', {
                            menuaction: 'booking.uiapplication.index',
                        }, false)}
                              className={'link-text link-text-unset normal'}>
                            <EnterIcon width="1.25rem" height="1.25rem" /> {t('bookingfrontend.case_officer')}
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


