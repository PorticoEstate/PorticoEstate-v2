import React, {PropsWithChildren, useCallback, useEffect, useRef, useState} from 'react';
import styles from './mobile-dialog.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {XMarkIcon, ExpandIcon, ShrinkIcon} from '@navikt/aksel-icons';
import {Button, Tooltip} from '@digdir/designsystemet-react';
import {useIsMobile} from "@/service/hooks/is-mobile";

interface DialogProps extends PropsWithChildren {
	/** Boolean to control the visibility of the modal */
	open: boolean;
	/** Function to close the modal */
	onClose: () => void;
	/** Boolean to control whether the default header is shown */
	showDefaultHeader?: boolean;
	/** Size of the dialog */
	size?: 'hd';
	/** Whether to confirm on close */
	confirmOnClose?: boolean;
	/** Optional footer content */
	footer?: ((attemptOnClose: () => void) => React.ReactNode) | React.ReactNode;
	/** Optional title content */
	title?: React.ReactNode;
	/** Enable clicking background to close */
	closeOnBackdropClick?: boolean;

	stickyFooter?: boolean;
}

/**
 * Dialog Component
 *
 * This component renders a modal that is fullscreen on mobile and windowed on desktop.
 * It uses the `<dialog>` HTML element and SCSS modules for styling.
 *
 * @param open - Controls whether the modal is open or closed
 * @param onClose - Callback function to close the modal
 * @param showDefaultHeader - Controls whether the default header is shown (default: true)
 * @param confirmOnClose - Prompts the user for confirmation before closing (default: false)
 * @param footer - Optional footer content to be rendered at the bottom of the dialog
 * @param title - Optional title content to be rendered in the header
 * @param closeOnBackdropClick - Allows closing the dialog by clicking the backdrop (default: false)
 */
const Dialog: React.FC<DialogProps> = ({
										   open,
										   onClose,
										   showDefaultHeader = true,
										   children,
										   size,
										   confirmOnClose = false,
										   footer,
										   title,
										   closeOnBackdropClick = false,
										   stickyFooter = false,
									   }) => {
	const dialogRef = useRef<HTMLDialogElement | null>(null);
	const contentRef = useRef<HTMLDivElement | null>(null);
	const [show, setShow] = useState<boolean>(false);
	const [isFullscreen, setIsFullscreen] = useState<boolean>(false);
	const t = useTrans();
	const [scrolled, setScrolled] = useState<boolean>(false);
	const isMobile = useIsMobile();

	// Attempt to close the dialog, with confirmation if necessary
	const attemptClose = useCallback(() => {
		if (confirmOnClose) {
			if (window.confirm(t('common.confirm_close'))) {
				onClose();
			}
		} else {
			onClose();
		}
	}, [onClose, confirmOnClose, t]);

	// Toggle fullscreen mode
	const toggleFullscreen = () => {
		setIsFullscreen(!isFullscreen);
	};

	// Handle backdrop clicks
	const handleBackdropClick = (e: React.MouseEvent<HTMLDialogElement>) => {
		if (closeOnBackdropClick && e.target === dialogRef.current) {
			attemptClose();
		}
	};

	useEffect(() => {
		const dialog = dialogRef.current;
		const content = contentRef.current;
		if (!dialog) return;
		let onScroll: (() => void) | undefined = undefined;
		if (content) {
			onScroll = () => {
				setScrolled(content.scrollTop > 5);
			};
			content.addEventListener('scroll', onScroll);
		}


		// Handle Escape key
		const handleCancel = (e: Event) => {
			e.preventDefault(); // Prevent default close
			attemptClose();
		};
		dialog.addEventListener('cancel', handleCancel);

		return () => {
			if (onScroll && content) {
				content.removeEventListener('scroll', onScroll);
			}
			dialog.removeEventListener('cancel', handleCancel);
		};
	}, [attemptClose]);

	// Much simpler implementation without all the viewport adjustments
	// The grid layout in CSS will handle the footer positioning naturally

	// Simplified dialog management
	useEffect(() => {
		const dialog = dialogRef.current;
		const scrollY = window.scrollY;

		if (open) {
			if (dialog) {
				dialog.showModal();
				setTimeout(() => setShow(true), 10);
			}
			
			// Simple approach to disable scrolling
			document.body.style.overflow = 'hidden';
		} else {
			if (dialog) {
				setShow(false);
				setTimeout(() => dialog.close(), 300);
			}
			
			// Restore scrolling
			document.body.style.overflow = '';
		}

		return () => {
			document.body.style.overflow = '';
		};
	}, [open]);

	return (
		<dialog
			ref={dialogRef}
			className={`${show ? styles.show : ''} ${styles.modal} ${size ? styles[size] : ''} ${
				isFullscreen ? styles.fullscreen : ''
			}`}
			onClick={handleBackdropClick}
			style={{ 
				maxWidth: '100%', 
				maxHeight: '100%',
				width: isMobile ? '100%' : 'auto',
				height: isMobile ? '100%' : '90vh'
			}}
		>
			<div className={styles.dialogContainer}>
				{showDefaultHeader && (
					<div className={`${styles.dialogHeader} ${scrolled ? styles.scrolled : ''}`}>
						<div className={styles.headerTitle}>{title || ''}</div>
						<div className={styles.headerButtons}>
							{!isMobile && <Tooltip
								content={isFullscreen ? t('common.exit_fullscreen') : t('common.enter_fullscreen')}
								className={'text-body '}>
								<Button
									icon={true}
									variant="tertiary"
									aria-label={isFullscreen ? t('common.exit_fullscreen') : t('common.enter_fullscreen')}
									onClick={toggleFullscreen}
									tabIndex={-1}
									className={'default'}
									data-size={'sm'}
								>
									{isFullscreen ? <ShrinkIcon fontSize="1.25rem"/> : <ExpandIcon fontSize="1.25rem"/>}
								</Button>
							</Tooltip>}
							<Tooltip content={t('booking.close')} className={'text-body'}>
								<Button
									icon={true}
									variant="tertiary"
									aria-label={t('common.close_dialog')}
									onClick={attemptClose}
									className={'default'}
									tabIndex={-1}
									data-size={'sm'}
								>
									<XMarkIcon fontSize="1.25rem"/>
								</Button>
							</Tooltip>
						</div>
					</div>
				)}

				<div className={styles.dialogContent} ref={contentRef}>{children}</div>
				{footer && <div
					className={`${stickyFooter ? styles.sticky : ''} ${styles.dialogFooter}`}>{typeof footer === "function" ? footer(attemptClose) : footer}</div>}
			</div>
		</dialog>
	);
};

export default Dialog;