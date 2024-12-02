import React, { PropsWithChildren, useEffect, useRef, useState } from 'react';
import styles from './mobile-dialog.module.scss';
import { useTrans } from '@/app/i18n/ClientTranslationProvider';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faXmark, faExpand, faCompress } from '@fortawesome/free-solid-svg-icons';
import { Button, Tooltip } from '@digdir/designsystemet-react';
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
    footer?: React.ReactNode;
    /** Optional title content */
    title?: React.ReactNode;
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
                                       }) => {
    const dialogRef = useRef<HTMLDialogElement | null>(null);
    const contentRef = useRef<HTMLDivElement | null>(null);
    const [show, setShow] = useState<boolean>(false);
    const [isFullscreen, setIsFullscreen] = useState<boolean>(false);
    const t = useTrans();
    const [scrolled, setScrolled] = useState<boolean>(false);
    const isMobile = useIsMobile();

    // Attempt to close the dialog, with confirmation if necessary
    const attemptClose = () => {
        if (confirmOnClose) {
            if (window.confirm(t('Are you sure you want to close?'))) {
                onClose();
            }
        } else {
            onClose();
        }
    };

    // Toggle fullscreen mode
    const toggleFullscreen = () => {
        setIsFullscreen(!isFullscreen);
    };

    // Handle backdrop clicks
    const handleBackdropClick = (e: React.MouseEvent<HTMLDialogElement>) => {
        if (e.target === dialogRef.current) {
            attemptClose();
        }
    };

    useEffect(() => {
        const dialog = dialogRef.current;
        const content = contentRef.current;
        if (!dialog) return;
        let onScroll: (() => void) | undefined = undefined;
        if(content) {
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
            if(onScroll && content) {
                content.removeEventListener('scroll', onScroll);
            }
            dialog.removeEventListener('cancel', handleCancel);
        };
    }, [confirmOnClose]);

    useEffect(() => {
        const dialog = dialogRef.current;

        if (open) {
            if (dialog) {
                dialog.showModal();
                setTimeout(() => setShow(true), 10);
            }
            document.body.style.overflow = 'hidden';
        } else {
            if (dialog) {
                setShow(false);
                setTimeout(() => dialog.close(), 300);
            }
            document.body.style.overflow = 'auto';
        }

        return () => {
            document.body.style.overflow = 'auto';
        };
    }, [open]);

    return (
        <dialog
            ref={dialogRef}
            className={`${show ? styles.show : ''} ${styles.modal} ${size ? styles[size] : ''} ${
                isFullscreen ? styles.fullscreen : ''
            }`}
            onClick={handleBackdropClick}
        >
            <div className={styles.dialogContainer}>
                {showDefaultHeader && (
                    <div className={`${styles.dialogHeader} ${scrolled ? styles.scrolled : ''}`}>
                        <div className={styles.headerTitle}>{title  || ''}</div>
                        <div className={styles.headerButtons}>
                            {!isMobile && <Tooltip content={isFullscreen ? t('Exit fullscreen') : t('Enter fullscreen')} className={'text-body text-primary'}>
                                <Button
                                    icon={true}
                                    variant="tertiary"
                                    aria-label={isFullscreen ? "Exit fullscreen" : "Enter fullscreen"}
                                    onClick={toggleFullscreen}
                                    className={'default'}
                                    size={'sm'}
                                >
                                    <FontAwesomeIcon icon={isFullscreen ? faCompress : faExpand} size={'lg'} />
                                </Button>
                            </Tooltip>}
                            <Tooltip content={t('booking.close')} className={'text-body text-primary'}>
                                <Button
                                    icon={true}
                                    variant="tertiary"
                                    aria-label="Close dialog"
                                    onClick={attemptClose}
                                    className={'default'}
                                    size={'sm'}
                                >
                                    <FontAwesomeIcon icon={faXmark} size={'lg'} />
                                </Button>
                            </Tooltip>
                        </div>
                    </div>
                )}

                <div className={styles.dialogContent}  ref={contentRef}>{children}</div>
                {footer && <div className={styles.dialogFooter}>{footer}</div>}
            </div>
        </dialog>
    );
};

export default Dialog;