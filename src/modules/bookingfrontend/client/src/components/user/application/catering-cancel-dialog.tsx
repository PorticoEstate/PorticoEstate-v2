'use client';
import React, {FC, useId} from 'react';
import {Button, Dialog, Heading, Paragraph} from '@digdir/designsystemet-react';
import {useClientTranslation} from '@/app/i18n/ClientTranslationProvider';
import styles from './catering-overview.module.scss';

export interface CancelDialogLine {
    id: number;
    /** «3 × Kaffekanne» */
    text: string;
    /** The line's amount incl. VAT, formatted. */
    amount: string;
}

interface CateringCancelDialogProps {
    open: boolean;
    /** «Onsdag 23. desember kl. 10:00 · Konferanserom 225»: names the serving, since two can share an offer. */
    heading: string;
    offerName: string;
    lines: CancelDialogLine[];
    /** The order total incl. VAT, formatted. */
    total: string;
    /** What cancelling does: the serving goes, the total goes down by so much, the others stay. */
    description: string;
    pending: boolean;
    onConfirm: () => void;
    onClose: () => void;
}

/**
 * Confirms the cancellation of ONE serving (GH #1423). Replaces window.confirm: it names the serving and what
 * it holds, says what the cancellation does to the total, and puts the danger colour on the button that
 * actually cancels, not on the way out. Whether the server still accepts the cancellation (409) is the caller's
 * to show after the answer; this dialog only asks.
 */
const CateringCancelDialog: FC<CateringCancelDialogProps> = ({
    open, heading, offerName, lines, total, description, pending, onConfirm, onClose,
}) => {
    const {t} = useClientTranslation();
    const titleId = useId();
    const descriptionId = useId();

    return (
        <Dialog
            open={open}
            onClose={onClose}
            closedby="any"
            closeButton={t('bookingfrontend.close')}
            role="alertdialog"
            aria-labelledby={titleId}
            aria-describedby={descriptionId}
            data-testid="catering-cancel-dialog"
        >
            {open && <>
                <Dialog.Block>
                    <Heading level={2} data-size="sm" id={titleId}>
                        {t('bookingfrontend.catering_overview_cancel_title')}
                    </Heading>
                    <Paragraph data-size="sm">
                        <strong>{heading}</strong>
                        <br/>
                        {offerName}
                    </Paragraph>
                    <ul className={styles.dialogLines}>
                        {lines.map(line => (
                            <li key={line.id} className={styles.dialogLine}>
                                <span>{line.text}</span>
                                <span>{line.amount}</span>
                            </li>
                        ))}
                        <li className={`${styles.dialogLine} ${styles.dialogTotal}`}>
                            <span>{t('bookingfrontend.catering_overview_order_total')}</span>
                            <span>{total}</span>
                        </li>
                    </ul>
                    <Paragraph id={descriptionId}>{description}</Paragraph>
                </Dialog.Block>
                <Dialog.Block>
                    <div className={styles.dialogActions}>
                        <Button variant="tertiary" onClick={onClose} disabled={pending}>
                            {t('bookingfrontend.cancel')}
                        </Button>
                        <Button data-color="danger" onClick={onConfirm} disabled={pending} data-testid="catering-cancel-confirm">
                            {t('bookingfrontend.catering_overview_cancel_confirm')}
                        </Button>
                    </div>
                </Dialog.Block>
            </>}
        </Dialog>
    );
};

export default CateringCancelDialog;
