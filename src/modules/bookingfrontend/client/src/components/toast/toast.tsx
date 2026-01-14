'use client';
import React, {useRef, useEffect} from 'react';
import {useToast, ToastMessage, ToastType} from './toast-context';
import styles from './toast.module.scss';
import {XMarkIcon} from '@navikt/aksel-icons';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {Alert, Button, Badge, Heading, Paragraph} from "@digdir/designsystemet-react";
import {SeverityColors} from "@digdir/designsystemet-react/colors";
import {usePartialApplications} from "@/service/hooks/api-hooks";
import {ShoppingBasketIcon} from "@navikt/aksel-icons";
import ShoppingCartPopper from "@/components/layout/header/shopping-cart/shopping-cart-popper";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {useShoppingCartDrawer} from "@/components/layout/header/shopping-cart/shopping-cart-drawer-context";
import {usePathname} from "next/navigation";

const ToastContainer: React.FC = () => {
	const {toasts, removeToast,dismissAllToasts, pauseToast, resumeToast, setFabButtonRef, setFabOpen, isFabOpen} = useToast();
	const {data: cartItems} = usePartialApplications();
	const fabButtonRef = useRef<HTMLButtonElement>(null);
	const t = useTrans();
	const pathname = usePathname();
	const isMobile = useIsMobile();
	const {setIsOpen: setDrawerOpen, setAnchorRef: setDrawerRef, isOpen: isDrawerOpen} = useShoppingCartDrawer();

	// Register the FAB button ref with the context
	useEffect(() => {
		setFabButtonRef(fabButtonRef);
	}, [setFabButtonRef]);

	// Check if current page is checkout
	const isCheckoutPage = pathname?.includes('/checkout');

	// Determine if the shopping cart FAB should be shown
	const showShoppingCart = !isCheckoutPage && cartItems?.list.length && cartItems.list.length > 0;

	return (
		<div className={styles.floatingContainer}>
			{/* Toast notifications */}
			<div className={styles.toastContainer}>
				{toasts.map((toast) => (
					<Toast
						key={toast.id}
						toast={toast}
						onClose={() => removeToast(toast.id)}
						onPause={() => pauseToast(toast.id)}
						onResume={() => resumeToast(toast.id)}
					/>
				))}
			</div>

			{/* Shopping Cart FAB */}
			<div className={`${styles.fabContainer} ${!showShoppingCart ? styles.hidden : ''}`}>
				{!isFabOpen && !isDrawerOpen && (
					<Button
						variant={'primary'}
						className={styles.fab}
						ref={fabButtonRef}
						onClick={() => {
							dismissAllToasts();
							if (isMobile) {
								setFabOpen(true);
							} else {
								// Use the shared drawer context instead
								setDrawerRef(fabButtonRef);
								setDrawerOpen(true);
							}
						}}
					>
						<ShoppingBasketIcon fontSize="1.25rem"/>
						Handlekurv
						<Badge
							data-color="brand3"
							data-size={'sm'}
							className={styles.badge}
							style={{
								display: 'flex',
								gap: 'var(--ds-spacing-2)',
							}}
							count={cartItems?.list.length ?? 0}
						></Badge>
					</Button>
				)}
				{isMobile && (
					<ShoppingCartPopper anchor={fabButtonRef.current} open={isFabOpen} setOpen={setFabOpen}/>
				)}
			</div>
		</div>
	);
};

interface ToastProps {
	toast: ToastMessage;
	onClose: () => void;
	onPause: () => void;
	onResume: () => void;
}

const Toast: React.FC<ToastProps> = ({toast, onClose, onPause, onResume}) => {
	const t = useTrans();
	const {type, title, text} = toast;

	const mapToastTypeToAlertColor = (toastType: ToastType): SeverityColors => {
		switch (toastType) {
			case 'error':
				return 'danger';
			case 'success':
				return 'success';
			case 'warning':
				return 'warning';
			case 'info':
				return 'info';
			default:
				return 'info';
		}
	};

	const color = mapToastTypeToAlertColor(type);

	return (
		<Alert
			data-color={color}
			className={`${styles.alert} ${styles.alertCompact} ${styles.toastAnimation}`}
			data-size="sm"
			onMouseEnter={onPause}
			onMouseLeave={onResume}
		>
			<div>
				{title && <Heading level={5} data-size='2xs' style={{
					marginBottom: 'var(--ds-size-2)'
				}}>
					{title}
				</Heading>}
				<Paragraph data-size={'xs'}>
					{typeof text === 'string' ? (
						<span dangerouslySetInnerHTML={{ __html: text }} />
					) : (
						text
					)}
				</Paragraph>
			</div>
			<Button
				icon
				data-color='neutral'
				variant='tertiary'
				data-size="sm"
				aria-label={t('bookingfrontend.close_message')}
				onClick={onClose}
				style={{padding: '4px', marginLeft: '4px'}}
			>
				<XMarkIcon aria-hidden fontSize="1rem"/>
			</Button>
		</Alert>
	);
};

export {ToastContainer};
export default ToastContainer;