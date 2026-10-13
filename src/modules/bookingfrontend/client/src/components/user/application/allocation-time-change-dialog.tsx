'use client'
import React, {FC, useEffect, useState} from 'react';
import {Alert, Button, Dialog, Heading, Paragraph, Spinner, Textfield} from "@digdir/designsystemet-react";
import {DateTime} from "luxon";
import {IAPIAllocation} from "@/service/pecalendar.types";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {useAllocationTimeChange} from "@/service/hooks/allocation-cancellation-hooks";
import {VENUE_TIMEZONE} from "@/utils/hospitality-deadline";
import styles from "./allocation-time-change-dialog.module.scss";

/**
 * "Endre tid" for one allocation occurrence on Min side (GH #1392).
 *
 * Only the time of day is chosen; the occurrence keeps its date. The server decides what the
 * new time is: a shorter time inside the approved one is stored at once and its price follows
 * the new hours, any other time is sent to the case officer as a request
 * (AllocationTimeChangeService.php). The outcome - and the price sentence the case officer's
 * comment got - is shown here before the dialog is closed.
 */
const AllocationTimeChangeDialog: FC<{
    allocation: IAPIAllocation | null;
    applicationId: number;
    applicationSecret?: string;
    onClose: () => void;
}> = ({allocation, applicationId, applicationSecret, onClose}) => {
    const t = useTrans();
    const mutation = useAllocationTimeChange();
    const [fromTime, setFromTime] = useState('');
    const [toTime, setToTime] = useState('');
    const [comment, setComment] = useState('');

    // The occurrence's own wall-clock time, in the venue's zone, whatever the browser's is
    const venueTime = (iso: string) => DateTime.fromISO(iso).setZone(VENUE_TIMEZONE);

    const allocationId = allocation?.id;
    useEffect(() => {
        if (!allocation) return;
        setFromTime(venueTime(allocation.from_).toFormat('HH:mm'));
        setToTime(venueTime(allocation.to_).toFormat('HH:mm'));
        setComment('');
        mutation.reset();
        // Reset only when another occurrence is opened, not on every refetch of the same one
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [allocationId]);

    const from = allocation ? venueTime(allocation.from_) : null;
    const to = allocation ? venueTime(allocation.to_) : null;
    const result = mutation.data;
    // The server answers in naive venue-local time
    const answeredTime = (sql: string) => DateTime.fromSQL(sql, {zone: VENUE_TIMEZONE}).toFormat('HH:mm');

    const handleSubmit = async () => {
        if (!allocation || !from || !to) return;
        try {
            await mutation.mutateAsync({
                allocationId: allocation.id,
                applicationId,
                secret: applicationSecret,
                from_: `${from.toFormat('yyyy-MM-dd')} ${fromTime}`,
                to_: `${to.toFormat('yyyy-MM-dd')} ${toTime}`,
                comment: comment.trim(),
            });
        } catch (err) {
            console.error('Failed to change the time of the timeslot:', err);
        }
    };

    return (
        <Dialog open={!!allocation} onClose={onClose} closedby="any">
            {allocation && from && to && <>
            <Dialog.Block>
                <Heading level={2} data-size="sm">
                    {t('bookingfrontend.change_time')}
                </Heading>
                <Paragraph data-size="sm">
                    {from.setLocale('no').toFormat('cccc dd.MM.yyyy')}, {from.toFormat('HH:mm')}–{to.toFormat('HH:mm')}
                </Paragraph>
                {result ? (
                    <Alert data-color="success">
                        <Paragraph>
                            {result.outcome === 'shortened'
                                ? `${t('bookingfrontend.allocation_time_shortened', {
                                    time: `${answeredTime(result.from_)}–${answeredTime(result.to_)}`,
                                })} ${result.price?.message ?? ''}`
                                : t('bookingfrontend.allocation_change_requested')}
                        </Paragraph>
                    </Alert>
                ) : (
                    <div className={styles.fields}>
                        <Paragraph data-size="sm">
                            {t('bookingfrontend.change_allocation_time_description')}
                        </Paragraph>
                        <Textfield
                            type="time"
                            label={t('bookingfrontend.From time')}
                            value={fromTime}
                            onChange={(e) => setFromTime(e.target.value)}
                            disabled={mutation.isPending}
                            required
                        />
                        <Textfield
                            type="time"
                            label={t('bookingfrontend.To time')}
                            value={toTime}
                            onChange={(e) => setToTime(e.target.value)}
                            disabled={mutation.isPending}
                            required
                        />
                        <Textfield
                            multiline
                            rows={3}
                            label={t('bookingfrontend.withdraw_timeslot_comment_label')}
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                            maxLength={10000}
                            disabled={mutation.isPending}
                        />
                        {mutation.isError && (
                            <Alert data-color="danger">
                                <Paragraph>{mutation.error?.message}</Paragraph>
                            </Alert>
                        )}
                    </div>
                )}
            </Dialog.Block>
            <Dialog.Block>
                <div className={styles.actions}>
                    {result ? (
                        <Button onClick={onClose}>
                            {t('bookingfrontend.close')}
                        </Button>
                    ) : (
                        <>
                            <Button variant="tertiary" onClick={onClose}>
                                {t('common.cancel')}
                            </Button>
                            <Button
                                onClick={handleSubmit}
                                disabled={mutation.isPending || !fromTime || !toTime}
                            >
                                {mutation.isPending ? <Spinner data-size="xs" aria-hidden="true"/> : null}
                                {t('bookingfrontend.change_time')}
                            </Button>
                        </>
                    )}
                </div>
            </Dialog.Block>
            </>}
        </Dialog>
    );
};

export default AllocationTimeChangeDialog;
