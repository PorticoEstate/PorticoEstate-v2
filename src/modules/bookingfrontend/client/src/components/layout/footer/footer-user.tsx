'use client'
import {FC, useEffect, useState} from 'react';
import {useBookingUser, useLogout} from "@/service/hooks/api-hooks";
import Link from "next/link";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {phpGWLink} from "@/service/util";
import { EnterIcon, PersonFillIcon, LeaveIcon } from "@navikt/aksel-icons";
import {useSearchParams} from "next/navigation";
import {useQueryClient} from "@tanstack/react-query";

interface FooterUserProps {
}

const FooterUser: FC<FooterUserProps> = (props) => {
    const bookingUserQ = useBookingUser();
    const logout = useLogout();
    const queryClient = useQueryClient();
    const params = useSearchParams();
    const [lastParam, setLastParam] = useState<string>();

    useEffect(() => {
        const currParam = params.get('click_history');
        if(currParam !== lastParam) {
            if (currParam) {
                setLastParam(currParam);
                queryClient.refetchQueries({queryKey: ['bookingUser']})
            }
        }

    }, [params, queryClient, lastParam]);

    const handleLogout = async () => {
        try {
            await logout.mutateAsync();
        } catch (error) {
            console.error('Logout failed:', error);
        }
    };
    const t = useTrans();
    const {data: bookingUser, isLoading} = bookingUserQ;
    return (
        <ul className={'list-unstyled text-small'}>
            <li>
                <Link href={phpGWLink('bookingfrontend/', {menuaction: 'bookingfrontend.uiuser.show'}, false)}
                      rel="noopener noreferrer" className="link-text link-text-secondary normal">
                    <PersonFillIcon fontSize="1.25rem" /> {t('bookingfrontend.my page')}
                </Link>
            </li>

            <li>
                {bookingUser?.is_logged_in ? (

                    <Link href={phpGWLink(['bookingfrontend', 'logout'])}
                          rel="noopener noreferrer"
                          className="link-text link-text-secondary normal">
                        <LeaveIcon fontSize="1.25rem" /> {bookingUser.orgnr} :: {t('common.logout')}
                    </Link>
                ) : (
                    <Link href={phpGWLink(['bookingfrontend', 'login/'], {after: encodeURI(window.location.href.split('bookingfrontend')[1])})}
                          rel="noopener noreferrer" className="link-text link-text-secondary normal">
                        <EnterIcon fontSize="1.25rem" /> {t('bookingfrontend.organization')}
                    </Link>
                )}

            </li>
            <li>
                {bookingUser?.is_logged_in && bookingUser?.orgname !== '000000000' && (
                    <Link href={phpGWLink('bookingfrontend/', {
                        menuaction: 'bookingfrontend.uiorganization.show',
                        id: bookingUser.org_id!
                    }, false)}
                          rel="noopener noreferrer" className="link-text link-text-secondary normal">
                        <EnterIcon fontSize="1.25rem" /> {bookingUser.orgname}
                    </Link>)}
            </li>
            {/*{org_info_view}*/}

            <li>
                <Link href={phpGWLink('/', {
                    menuaction: 'booking.uiapplication.index',
                }, false)} target="_blank"
                      rel="noopener noreferrer" className="link-text link-text-secondary normal">
                    <EnterIcon fontSize="1.25rem" /> {t('bookingfrontend.executiveofficer')}
                </Link>
            </li>
        </ul>
    );
}

export default FooterUser
