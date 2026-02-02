import React, {PropsWithChildren, useCallback, useEffect, useRef, useState} from 'react';
import styles from './mobile-dialog.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {XMarkIcon, ExpandIcon, ShrinkIcon} from '@navikt/aksel-icons';
import {Button, Tooltip} from '@digdir/designsystemet-react';
import {useIsMobile} from "@/service/hooks/is-mobile";
import {useScrollLockEffect} from '@/contexts/ScrollLockContext';

const DEBUG_DIALOGS = false;

interface DialogProps extends PropsWithChildren {
	/** Boolean to control the visibility of the modal */
	open: boolean;
	/** Function to close the modal */
	onClose: () => void;
	/** Unique identifier for this dialog instance (required for scroll lock management) */
	dialogId: string;
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
 * @param dialogId - Unique identifier for this dialog instance (required for scroll lock management)
 * @param showDefaultHeader - Controls whether the default header is shown (default: true)
 * @param confirmOnClose - Prompts the user for confirmation before closing (default: false)
 * @param footer - Optional footer content to be rendered at the bottom of the dialog
 * @param title - Optional title content to be rendered in the header
 * @param closeOnBackdropClick - Allows closing the dialog by clicking the backdrop (default: false)
 */
const Dialog: React.FC<DialogProps> = ({
										   open,
										   onClose,
										   dialogId,
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
	const closeTimeoutRef = useRef<NodeJS.Timeout | null>(null);
	const openRef = useRef<boolean>(open);
	const [show, setShow] = useState<boolean>(false);
	const [isFullscreen, setIsFullscreen] = useState<boolean>(false);
	const t = useTrans();
	const [scrolled, setScrolled] = useState<boolean>(false);
	const isMobile = useIsMobile();

	// Use scroll lock context to manage body overflow
	useScrollLockEffect(dialogId, open);

	// Debug show state changes
	useEffect(() => {
		if (DEBUG_DIALOGS) console.log('Show state changed for dialog:', dialogId, 'show:', show);
		if (show && dialogRef.current) {
			setTimeout(() => {
				const dialog = dialogRef.current;
				const opacity = dialog ? window.getComputedStyle(dialog).opacity : 'no element';
				if (DEBUG_DIALOGS) console.log('Modal visual state check for:', dialogId, {
					show,
					dialogElement: dialog,
					dialogOpen: dialog?.open,
					computedStyle: opacity
				});

				// Log detailed dialog state
				if (dialog) {
					const computedStyles = window.getComputedStyle(dialog);
					if (DEBUG_DIALOGS) console.log('Detailed dialog state for:', dialogId, {
						className: dialog.className,
						opacity,
						display: computedStyles.display,
						visibility: computedStyles.visibility,
						pointerEvents: computedStyles.pointerEvents,
						zIndex: computedStyles.zIndex,
						position: computedStyles.position,
						transform: computedStyles.transform
					});
				}

				// Force opacity to 1 if it's not reaching there
				if (dialog && parseFloat(opacity) < 1) {
					if (DEBUG_DIALOGS) console.log('Forcing opacity to 1 for dialog:', dialogId);
					dialog.style.opacity = '1';
				}

				// Check again after 2 seconds
				setTimeout(() => {
					if (dialog) {
						const computedStyles2 = window.getComputedStyle(dialog);
						if (DEBUG_DIALOGS) console.log('Dialog state after 2 seconds for:', dialogId, {
							className: dialog.className,
							opacity: computedStyles2.opacity,
							display: computedStyles2.display,
							visibility: computedStyles2.visibility,
							pointerEvents: computedStyles2.pointerEvents,
							inlineStyle: dialog.style.cssText
						});

						// Force display back to block if it's been set to none
						if (computedStyles2.display === 'none') {
							if (DEBUG_DIALOGS) console.log('FOUND THE BUG: Display is none! Forcing back to block for:', dialogId);
							dialog.style.display = 'block';
						}
					}
				}, 2000);
			}, 100);
		}
	}, [show, dialogId]);

	// Attempt to close the dialog, with confirmation if necessary
	const attemptClose = useCallback(() => {
		if (DEBUG_DIALOGS) console.log('attemptClose called for dialog:', dialogId);
		if (confirmOnClose) {
			if (window.confirm(t('common.confirm_close'))) {
				if (DEBUG_DIALOGS) console.log('User confirmed close for dialog:', dialogId);
				onClose();
			} else {
				if (DEBUG_DIALOGS) console.log('User cancelled close for dialog:', dialogId);
			}
		} else {
			if (DEBUG_DIALOGS) console.log('Closing dialog without confirmation:', dialogId);
			onClose();
		}
	}, [onClose, confirmOnClose, t, dialogId]);

	// Toggle fullscreen mode
	const toggleFullscreen = () => {
		setIsFullscreen(!isFullscreen);
	};

	// Handle backdrop clicks
	const handleBackdropClick = (e: React.MouseEvent<HTMLDialogElement>) => {
		if (DEBUG_DIALOGS) console.log('Backdrop click detected for dialog:', dialogId, 'closeOnBackdropClick:', closeOnBackdropClick);
		if (closeOnBackdropClick && e.target === dialogRef.current) {
			if (DEBUG_DIALOGS) console.log('Closing modal due to backdrop click');
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
		openRef.current = open; // Update the ref with current open state
		if (DEBUG_DIALOGS) console.log('MobileDialog useEffect - dialogId:', dialogId, 'open:', open);

		if (open) {
			if (dialog) {
				if (DEBUG_DIALOGS) console.log('Opening dialog:', dialogId);

				// Clear any pending close timeout
				if (closeTimeoutRef.current) {
					if (DEBUG_DIALOGS) console.log('Clearing pending close timeout for:', dialogId);
					clearTimeout(closeTimeoutRef.current);
					closeTimeoutRef.current = null;
				}
				try {
					dialog.showModal()
				} catch (e) {
					console.log('Failed to spawn modal, offloading to timeout',e)
				}
				// dialog.showModal();
				setTimeout(() => {
					if (DEBUG_DIALOGS) console.log('Setting show to TRUE for dialog:', dialogId);
					setShow(true);
				}, 10);
			}
		} else {
			if (dialog) {
				if (DEBUG_DIALOGS) console.log('Closing dialog:', dialogId);
				setShow(false);
				closeTimeoutRef.current = setTimeout(() => {
					// Double-check that we should still close this dialog using the ref
					if (!openRef.current) {
						if (DEBUG_DIALOGS) console.log('Actually calling dialog.close() for:', dialogId);
						dialog.close();
					} else {
						if (DEBUG_DIALOGS) console.log('Skipping dialog.close() because dialog should be open:', dialogId);
					}
					closeTimeoutRef.current = null;
				}, 300);
			}
		}
	}, [open, dialogId]);

	if(!open) {
		return null;
	}
	return (
		<dialog
			ref={dialogRef}
			className={`${show ? styles.show : ''} ${styles.modal} ${size ? styles[size] : ''} ${
				isFullscreen ? styles.fullscreen : ''
			}`}
			onClick={handleBackdropClick}
		>
			{/* Custom backdrop inside dialog */}
			{show && <div className={styles.customBackdrop} onClick={closeOnBackdropClick ? attemptClose : undefined} />}

			{/* Portal container for calendar popups */}
			{show && <div className={styles.portalContainer} data-portal="datepicker" id={'portalContainer'} />}

			{/* Dialog content container */}
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