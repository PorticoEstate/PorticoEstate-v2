'use client'
import React, {FC, useState} from 'react';
import {DateTime} from 'luxon';
import {
    Button,
    Dialog,
    Field,
    Heading,
    Label,
    Paragraph,
    Spinner,
    Tag,
    Textarea,
    ValidationMessage,
} from '@digdir/designsystemet-react';
import {ChatIcon} from '@navikt/aksel-icons';
import {useTrans, useClientTranslation} from '@/app/i18n/ClientTranslationProvider';
import {useAddApplicationComment} from '@/service/hooks/api-hooks';
import {CommentElement, CommentElementType} from '@/service/types/api/application.types';
import styles from './element-comment.module.scss';

// Comments on one element (allocation, event or booking) of an application (GH #1392).

/**
 * "Fre 04.12 17:00–20:00": how a comment names the time of its element. Wall-clock times;
 * the end carries its own date when it falls on another day.
 */
export function formatElementTime(from_: string, to_: string, locale: string): string {
    const from = DateTime.fromISO(from_).setLocale(locale);
    const to = DateTime.fromISO(to_).setLocale(locale);
    const day = (dt: DateTime) => {
        const weekday = dt.toFormat('ccc').replace('.', '');
        return `${weekday.charAt(0).toUpperCase()}${weekday.slice(1)} ${dt.toFormat('dd.MM')}`;
    };
    const end = from.hasSame(to, 'day') ? to.toFormat('HH:mm') : `${day(to)} ${to.toFormat('HH:mm')}`;
    return `${day(from)} ${from.toFormat('HH:mm')}–${end}`;
}

/** The element a comment is about, or a note that it no longer exists. */
export const CommentElementBadge: FC<{ element: CommentElement }> = ({element}) => {
    const t = useTrans();
    const {i18n} = useClientTranslation();

    if (!element.exists || !element.from_ || !element.to_) {
        return <Tag data-color="neutral" data-size="sm">{t('bookingfrontend.comment_time_removed')}</Tag>;
    }

    let text = formatElementTime(element.from_, element.to_, i18n.language);
    if (element.resource_names) text += ` · ${element.resource_names}`;
    if (!element.active) text += ` · ${t('bookingfrontend.inactive')}`;
    return <Tag data-color="info" data-size="sm">{text}</Tag>;
};

interface ElementCommentButtonProps {
    applicationId: number;
    secret?: string;
    elementType: CommentElementType;
    elementId: number;
    /** How the dialog names the element, e.g. "Fre 04.12 17:00–20:00 · Musikkrom 3" */
    elementLabel: string;
}

/** "Kommenter" on one reserved time: a comment to the case officer that names that element. */
export const ElementCommentButton: FC<ElementCommentButtonProps> = ({applicationId, secret, elementType, elementId, elementLabel}) => {
    const t = useTrans();
    const addComment = useAddApplicationComment();
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState('');
    const fieldId = `element-comment-${elementType}-${elementId}`;

    const close = () => {
        setOpen(false);
        setDraft('');
        addComment.reset();
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!draft.trim()) return;
        try {
            await addComment.mutateAsync({
                applicationId,
                commentData: {
                    comment: draft.trim(),
                    type: 'comment',
                    element_type: elementType,
                    element_id: elementId,
                },
                secret,
            });
            close();
        } catch (err) {
            console.error('Failed to add comment:', err);
        }
    };

    return (
        <>
            <Button variant="tertiary" data-color="accent" data-size="sm" onClick={() => setOpen(true)}>
                <ChatIcon fontSize="1.1rem" aria-hidden="true"/>
                {t('bookingfrontend.comment_on_time')}
            </Button>

            <Dialog open={open} onClose={close} closedby="any">
                <form onSubmit={handleSubmit}>
                    <Dialog.Block>
                        <Heading level={2} data-size="sm">
                            {t('bookingfrontend.comment_on_time_heading')}
                        </Heading>
                        <Paragraph>{elementLabel}</Paragraph>
                    </Dialog.Block>
                    <Dialog.Block>
                        <Field>
                            <Label htmlFor={fieldId}>{t('bookingfrontend.comment')}</Label>
                            <Textarea
                                id={fieldId}
                                placeholder={t('bookingfrontend.reply_placeholder')}
                                value={draft}
                                onChange={(e) => setDraft(e.target.value)}
                                rows={3}
                                maxLength={10000}
                                disabled={addComment.isPending}
                            />
                            {addComment.isError && (
                                <ValidationMessage>{t('bookingfrontend.failed_to_add_comment')}</ValidationMessage>
                            )}
                        </Field>
                    </Dialog.Block>
                    <Dialog.Block>
                        <div className={styles.actions}>
                            <Button type="button" variant="tertiary" onClick={close}>
                                {t('common.cancel')}
                            </Button>
                            <Button type="submit" disabled={!draft.trim() || addComment.isPending}>
                                {addComment.isPending ? <Spinner data-size="xs" aria-hidden="true"/> : null}
                                {t('bookingfrontend.send_message')}
                            </Button>
                        </div>
                    </Dialog.Block>
                </form>
            </Dialog>
        </>
    );
};
