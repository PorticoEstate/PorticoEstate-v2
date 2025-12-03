'use client'
import React, {FC, useEffect, useState, useMemo} from 'react';
import {useBookingUser, useLogin, useLogout} from "@/service/hooks/api-hooks";
import {Dropdown} from "@digdir/designsystemet-react";
import {EnterIcon, PersonFillIcon, ChevronDownIcon, TenancyIcon} from "@navikt/aksel-icons";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {phpGWLink} from "@/service/util";
import Link from "next/link";
import {useSearchParams} from "next/navigation";
import {useQueryClient} from "@tanstack/react-query";
import ResponsiveDropdown from "@/components/common/responsive-dropdown/responsive-dropdown";
import {useIsMobile} from "@/service/hooks/is-mobile";

interface UserMenuProps {
}

const UserMenu: FC<UserMenuProps> = (props) => {
	const [lastClickHistory, setLastClickHistory] = useState<string>();
	const t = useTrans();
	const bookingUserQ = useBookingUser();
	const {data: bookingUser, isLoading, refetch} = bookingUserQ;
	const searchparams = useSearchParams();
	const queryClient = useQueryClient();
	const isMobile = useIsMobile();

	const login = useLogin();
	const logout = useLogout();

	useEffect(() => {
		const clickHistory = searchparams.get('click_history');
		if (clickHistory !== lastClickHistory) {
			setLastClickHistory(clickHistory!);
			queryClient.invalidateQueries({queryKey: ['bookingUser']})
		}
	}, [searchparams, queryClient]);


	// Filter active delegates
	const activeDelegates = useMemo(() => {
		return bookingUser?.delegates?.filter(delegate => delegate.active) || [];
	}, [bookingUser?.delegates]);


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
		return (
			<ResponsiveDropdown
				trigger={
					<Dropdown.Trigger variant={'tertiary'} color={'accent'} data-size={'sm'}>
						<PersonFillIcon width="1.875rem"
										height="1.875rem"/> {bookingUser.name || t('bookingfrontend.user')}
						<ChevronDownIcon fontSize="1.25rem"/>
					</Dropdown.Trigger>
				}
			>
				<ResponsiveDropdown.List>
					<ResponsiveDropdown.Item>
						<ResponsiveDropdown.Button asChild>
							<Link href={'/user'}
								  className={'link-text link-text-unset normal'}>
								<PersonFillIcon
									fontSize="1.25rem"/> {isMobile ? bookingUser.name || t('bookingfrontend.user') : t('bookingfrontend.my page')}
							</Link>
						</ResponsiveDropdown.Button>
					</ResponsiveDropdown.Item>
				</ResponsiveDropdown.List>

				<ResponsiveDropdown.Divider/>

				{activeDelegates.length > 0 && (
					<>
						<ResponsiveDropdown.List>
							{activeDelegates.map((delegate) => (
								<ResponsiveDropdown.Item key={delegate.org_id}>
									<ResponsiveDropdown.Button asChild>
										<Link href={`/organization/${delegate.org_id}`}
											  className={'link-text link-text-unset normal'}>
											<TenancyIcon fontSize="1.25rem"/> {delegate.name}
										</Link>
									</ResponsiveDropdown.Button>
								</ResponsiveDropdown.Item>
							))}
						</ResponsiveDropdown.List>
						<ResponsiveDropdown.Divider/>
					</>
				)}

				<ResponsiveDropdown.List>
					<ResponsiveDropdown.Item>
						<ResponsiveDropdown.Button asChild>
							<a href={phpGWLink(['bookingfrontend', 'logout/'])}
							   className={'link-text link-text-unset normal'}>
								{t('common.logout')}
							</a>
						</ResponsiveDropdown.Button>
					</ResponsiveDropdown.Item>
				</ResponsiveDropdown.List>
			</ResponsiveDropdown>
		);
	}

	return (
		<ResponsiveDropdown
			trigger={
				<Dropdown.Trigger variant={'tertiary'} color={'accent'} data-size={'sm'}>
					<EnterIcon width="1.875rem" height="1.875rem"/> {t('common.login')}
				</Dropdown.Trigger>
			}
		>
			<ResponsiveDropdown.List>
				<ResponsiveDropdown.Item>
					<ResponsiveDropdown.Button asChild>
						<a href={phpGWLink(['bookingfrontend', 'login/'], {after: encodeURI(window.location.href.split('bookingfrontend')[1])})}
						   className={'link-text link-text-unset normal'}>
							<EnterIcon width="1.25rem" height="1.25rem"/> {t('bookingfrontend.private_person')}
						</a>
					</ResponsiveDropdown.Button>
				</ResponsiveDropdown.Item>
			</ResponsiveDropdown.List>

			<ResponsiveDropdown.Divider/>

			<ResponsiveDropdown.List>
				<ResponsiveDropdown.Item>
					<ResponsiveDropdown.Button asChild>
						<Link href={phpGWLink('/', {
							menuaction: 'booking.uiapplication.index',
						}, false)}
							  className={'link-text link-text-unset normal'}>
							<EnterIcon width="1.25rem" height="1.25rem"/> {t('bookingfrontend.case_officer')}
						</Link>
					</ResponsiveDropdown.Button>
				</ResponsiveDropdown.Item>
			</ResponsiveDropdown.List>
		</ResponsiveDropdown>
	);
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


