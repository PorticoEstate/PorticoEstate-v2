'use client'
import React, {FC, useMemo, useEffect, useState, useRef} from 'react';
import {IApplication, IApplicationDate, ApplicationComment as IComment, RecurringInfoUtils} from "@/service/types/api/application.types";
import {
    useApplication,
    useResourceRegulationDocuments,
    useBuildingAudience,
    useApplicationDocuments,
    useApplicationComments,
    useAddApplicationCommentWs,
    useUpdateApplicationStatus,
    useUploadApplicationDocument,
    useDeleteApplicationDocument,
    markNotificationsAsRead,
} from "@/service/hooks/api-hooks";
import {SubscriptionManager} from "@/service/websocket/subscription-manager";
import {
    Heading,
    Paragraph,
    Spinner,
    Tag,
    Button,
    Alert,
    Textarea,
    Details,
    Dialog,
    Table,
} from "@digdir/designsystemet-react";
import Link from "next/link";
import {DateTime} from "luxon";
import ResourceCircles from "@/components/resource-circles/resource-circles";
import {useTrans, useClientTranslation} from "@/app/i18n/ClientTranslationProvider";
import {getDocumentLink} from "@/service/api/building";
import ApplicationCrud from "@/components/building-calendar/modules/event/edit/application-crud";
import {FCallTempEvent} from "@/components/building-calendar/building-calendar.types";
import ArticleTable from "@/components/article-table/article-table";
import {ENABLE_COMBINED_APPLICATIONS} from '@/service/feature-flags';
import {getStatusColor} from './status-utils';
import styles from "./application-details.module.scss";
import {
    ArrowLeftIcon,
    PencilIcon,
    FilesIcon,
    XMarkOctagonIcon,
    CalendarIcon,
    PinIcon,
    InformationSquareIcon,
    FileIcon,
    PaperplaneIcon,
    TrashIcon,
    PlusIcon,
} from '@navikt/aksel-icons';
import {useQueryClient} from '@tanstack/react-query';
import TimeAgo from 'timeago-react';
import * as timeago from 'timeago.js';
import nb from 'timeago.js/lib/lang/nb_NO';

timeago.register('no', nb);

interface ApplicationDetailsProps {
    initialApplication?: IApplication;
    applicationId: number;
    secret?: string;
}

const ACCEPTED_FILE_TYPES = '.jpg,.jpeg,.png,.gif,.xls,.xlsx,.doc,.docx,.txt,.pdf,.odt,.ods';
const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2MB

// --- Helpers ---

function fmtDate(iso: string) {
    const dt = DateTime.fromISO(iso).setLocale('no');
    return {
        day: dt.toFormat('dd'),
        mon: dt.toFormat('MMM').replace('.', '').toLowerCase(),
        weekday: dt.toFormat('ccc'),
        full: dt.toFormat('dd.MM.yyyy'),
        time: dt.toFormat('HH:mm'),
    };
}

function fmtSqlDate(sql: string): string {
    if (!sql) return '';
    return DateTime.fromSQL(sql).toFormat('dd.MM.yyyy');
}

function fmtSqlDateTime(sql: string): string {
    if (!sql) return '';
    return DateTime.fromSQL(sql).toFormat('dd.MM.yyyy') + ' kl. ' + DateTime.fromSQL(sql).toFormat('HH:mm');
}

function getInitials(name: string): string {
    return name.split(/\s+/).map(w => w[0]).filter(Boolean).slice(0, 2).join('').toUpperCase();
}

function durationHours(from: string, to: string): number {
    return Math.round((DateTime.fromISO(to).toMillis() - DateTime.fromISO(from).toMillis()) / 3600000 * 10) / 10;
}

// --- Sub-components ---

const StatusTimeline: FC<{ application: IApplication; t: (k: string) => string }> = ({application, t}) => {
    const status = application.status.toLowerCase();
    const rejected = status === 'rejected' || status === 'cancelled';
    const order = ['new', 'pending', 'accepted'];

    const isDoneUpTo = (key: string) => {
        const idx = order.indexOf(key);
        const cur = order.indexOf(status);
        if (rejected) return key === 'new' || key === 'pending';
        return cur > idx;
    };
    const isCurrent = (key: string) => {
        if (rejected) return false;
        return key === status;
    };

    const steps = [
        {key: 'new', label: t('bookingfrontend.new'), date: fmtSqlDate(application.created)},
        {key: 'pending', label: t('bookingfrontend.pending'), date: application.status !== 'NEW' ? fmtSqlDate(application.modified) : '—'},
        {key: 'accepted', label: t('bookingfrontend.accepted'), date: status === 'accepted' ? fmtSqlDate(application.modified) : '—'},
    ];

    if (rejected) {
        steps.push({
            key: 'rejected',
            label: status === 'cancelled' ? t('bookingfrontend.cancelled') : t('bookingfrontend.rejected'),
            date: fmtSqlDate(application.modified),
        });
    } else {
        steps.push({key: 'done', label: t('bookingfrontend.finished'), date: '—'});
    }

    return (
        <div className={styles.statusTimeline}>
            {steps.map((step, i) => {
                const done = isDoneUpTo(step.key);
                const current = isCurrent(step.key);
                const danger = step.key === 'rejected';
                let cls = '';
                if (danger) cls = styles.danger;
                else if (done) cls = styles.done;
                else if (current) cls = styles.current;

                return (
                    <div key={step.key} className={`${styles.statusStep} ${cls}`}>
                        <span className={styles.statusDot}>{i + 1}</span>
                        <span className={styles.statusLabel}>{step.label}</span>
                        <span className={styles.statusDate}>{step.date}</span>
                    </div>
                );
            })}
        </div>
    );
};

const DatesList: FC<{ dates: IApplicationDate[] }> = ({dates}) => {
    const sorted = useMemo(
        () => [...dates].sort((a, b) => DateTime.fromISO(a.from_).toMillis() - DateTime.fromISO(b.from_).toMillis()),
        [dates]
    );

    return (
        <div className={styles.datesList}>
            {sorted.map((dt, i) => {
                const f = fmtDate(dt.from_);
                const to = fmtDate(dt.to_);
                const sameDay = f.full === to.full;
                const hours = durationHours(dt.from_, dt.to_);

                return (
                    <div key={dt.id || i} className={styles.dateRow}>
                        <div className={styles.dateBadge}>
                            <span className={styles.day}>{f.day}</span>
                            <span className={styles.month}>{f.mon}</span>
                        </div>
                        <div className={styles.dateInfo}>
                            <div className={styles.primary}>{f.weekday} {f.full}</div>
                            <div className={styles.secondary}>
                                kl. {f.time}{sameDay ? `–${to.time}` : ` → ${to.full} kl. ${to.time}`}
                            </div>
                        </div>
                        <div className={styles.dateDuration}>{hours} t</div>
                    </div>
                );
            })}
        </div>
    );
};

const Field: FC<{ label: string; children: React.ReactNode }> = ({label, children}) => (
    <div className={styles.field}>
        <span className={styles.fieldLabel}>{label}</span>
        <span className={styles.fieldValue}>{children || <span className={styles.muted}>—</span>}</span>
    </div>
);

// --- Comments section (inline, not accordion) ---

const CommentsSection: FC<{
    applicationId: number;
    secret?: string;
    isCancelled: boolean;
    t: (k: string) => string;
}> = ({applicationId, secret, isCancelled, t}) => {
    const {data: commentsData, isLoading} = useApplicationComments(applicationId, "comment,ownership,status", secret);
    const addComment = useAddApplicationCommentWs();
    const [replyDraft, setReplyDraft] = useState('');
    const {i18n} = useClientTranslation();

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!replyDraft.trim()) return;
        try {
            await addComment.mutateAsync({
                applicationId,
                comment: replyDraft.trim(),
                secret,
            });
            setReplyDraft('');
        } catch (err) {
            console.error('Failed to add comment:', err);
        }
    };

    const comments = commentsData?.comments || [];
    const commentCount = comments.filter(c => c.type === 'comment').length;

    if (isLoading) {
        return (
            <div style={{display: 'flex', justifyContent: 'center', padding: '1rem'}}>
                <Spinner data-size="sm" aria-label={t('common.loading')}/>
            </div>
        );
    }

    return (
        <>
            <div className={styles.cardHeader}>
                <Heading level={2} data-size="sm">
                    {t('bookingfrontend.comments')}
                </Heading>
                <Tag data-color="info" data-size="sm">{commentCount} {t('bookingfrontend.messages')}</Tag>
            </div>

            {comments.length > 0 && (
                <div className={styles.commentList}>
                    {comments.map(c => {
                        const isSystem = c.type !== 'comment';
                        // Heuristic: if the comment author matches the contact, it's the user's own
                        const isSelf = !isSystem && c.author && !c.author.includes('saksbehandler') && !c.author.includes('System');
                        let commentClass = '';
                        if (isSystem) commentClass = styles.system;
                        else if (isSelf) commentClass = styles.user;

                        return (
                            <div key={c.id} className={`${styles.comment} ${commentClass}`}>
                                <span className={styles.commentAvatar}>
                                    {isSystem
                                        ? <InformationSquareIcon fontSize="1rem"/>
                                        : getInitials(c.author)}
                                </span>
                                <div className={styles.commentBody}>
                                    <div className={styles.commentMeta}>
                                        <strong>{c.author}</strong>
                                        {isSystem && <Tag data-color="neutral" data-size="sm">System</Tag>}
                                        <span>&middot;</span>
                                        <span>
                                            <TimeAgo datetime={c.time} locale={i18n.language}/>{' · '}
                                            {DateTime.fromISO(c.time).toFormat('dd.MM.yyyy HH:mm')}
                                        </span>
                                    </div>
                                    <div className={styles.commentText}>{c.comment}</div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {!isCancelled && (
                <form className={styles.commentForm} onSubmit={handleSubmit}>
                    <label htmlFor="newComment" style={{fontWeight: 500, fontSize: 14, display: 'block', marginBottom: 6}}>
                        {t('bookingfrontend.reply')}
                    </label>
                    <Textarea
                        id="newComment"
                        placeholder={t('bookingfrontend.reply_placeholder')}
                        value={replyDraft}
                        onChange={(e) => setReplyDraft(e.target.value)}
                        rows={3}
                        maxLength={10000}
                        disabled={addComment.isPending}
                    />
                    <div className={styles.formActions}>
                        <span className={styles.helperText}>
                            {t('bookingfrontend.email_notification_on_reply')}
                        </span>
                        <Button
                            type="submit"
                            data-size="sm"
                            disabled={!replyDraft.trim() || addComment.isPending}
                        >
                            {addComment.isPending ? (
                                <Spinner data-size="xs" aria-hidden="true"/>
                            ) : (
                                <PaperplaneIcon fontSize="1rem"/>
                            )}
                            {t('bookingfrontend.send_message')}
                        </Button>
                    </div>
                    {addComment.isError && (
                        <Paragraph data-size="sm" style={{color: 'var(--ds-color-danger-text-default)', marginTop: 8}}>
                            {t('bookingfrontend.failed_to_add_comment')}
                        </Paragraph>
                    )}
                </form>
            )}
        </>
    );
};


// --- Main Component ---

const ApplicationDetails: FC<ApplicationDetailsProps> = (props) => {
    const {data: application, isLoading, error} = useApplication(props.applicationId, {
        initialData: props.initialApplication,
        secret: props.secret,
    });
    const {data: regulationDocuments} = useResourceRegulationDocuments(application?.resources || []);
    const {data: audience} = useBuildingAudience(application?.building_id);
    const {data: applicationDocuments} = useApplicationDocuments(props.applicationId);
    const cancelStatus = useUpdateApplicationStatus();
    const uploadDocumentMutation = useUploadApplicationDocument();
    const deleteDocumentMutation = useDeleteApplicationDocument();
    const queryClient = useQueryClient();
    const fileInputRef = useRef<HTMLInputElement>(null);
    const t = useTrans();
    const [uploadError, setUploadError] = useState<string | null>(null);

    // TODO: When ENABLE_COMBINED_APPLICATIONS is true and this is a parent application,
    // fetch child applications via a new API endpoint (e.g., GET /applications/{id}/children)
    // and merge their dates, resources, orders, and agegroups into the parent display.
    // For now, combined mode only affects the list page filtering.
    const isParentApplication = ENABLE_COMBINED_APPLICATIONS && application && (!application.parent_id || application.parent_id === application.id);

    useEffect(() => {
        if (application && props.secret) {
            document.title = application.name || `Application ${props.applicationId}`;
        }
    }, [application, props.secret, props.applicationId]);

    // Live comment updates via WebSocket
    useEffect(() => {
        if (!application?.id) return;

        const subscriptionManager = SubscriptionManager.getInstance();
        const cleanup = subscriptionManager.subscribeToEntity(
            'application',
            application.id,
            (event: any) => {
                if (event.eventType === 'new_comment' && event.data?.comment) {
                    // Invalidate comments cache to refetch with the new comment
                    queryClient.invalidateQueries({ queryKey: ['applicationComments', application.id] });
                    // Also invalidate unread count since we're viewing the page
                    queryClient.invalidateQueries({ queryKey: ['unreadNotificationCount'] });
                }
            }
        );

        return cleanup;
    }, [application?.id, queryClient]);

    // Mark notifications as read when viewing the application
    useEffect(() => {
        if (!application?.id) return;
        markNotificationsAsRead('application', application.id).catch(() => {});
    }, [application?.id]);

    // Copy-as-new dialog
    const [showCopyDialog, setShowCopyDialog] = useState(false);
    // Cancel confirmation
    const [confirmCancelOpen, setConfirmCancelOpen] = useState(false);

    const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
        setUploadError(null);
        const files = e.target.files;
        if (!files || files.length === 0) return;

        const allowedExtensions = ACCEPTED_FILE_TYPES.split(',').map(ext => ext.trim().toLowerCase());

        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const ext = '.' + file.name.split('.').pop()?.toLowerCase();
            if (!allowedExtensions.includes(ext)) {
                setUploadError(t('bookingfrontend.invalid_file_type'));
                if (fileInputRef.current) fileInputRef.current.value = '';
                return;
            }
            if (file.size > MAX_FILE_SIZE) {
                setUploadError(t('bookingfrontend.file_too_large'));
                if (fileInputRef.current) fileInputRef.current.value = '';
                return;
            }
        }

        const formData = new FormData();
        for (let i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
        }

        try {
            await uploadDocumentMutation.mutateAsync({
                id: props.applicationId,
                files: formData,
            });
            queryClient.invalidateQueries({queryKey: ['applicationDocuments', props.applicationId]});
        } catch {
            setUploadError(t('bookingfrontend.upload_failed'));
        }

        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    const handleDeleteDocument = async (documentId: number, documentName: string) => {
        if (!window.confirm(`${t('bookingfrontend.delete_document')}: ${documentName}?`)) return;

        try {
            await deleteDocumentMutation.mutateAsync(documentId);
            queryClient.invalidateQueries({queryKey: ['applicationDocuments', props.applicationId]});
        } catch {
            console.error('Failed to delete document:', documentId);
        }
    };

    const createTempEventFromApplication = (): Partial<FCallTempEvent> | undefined => {
        if (!application) return undefined;
        return {
            id: `copy-${application.id}-${Date.now()}`,
            title: application.name || 'Copied Application',
            start: undefined,
            end: undefined,
            allDay: false,
            editable: true,
            extendedProps: {
                type: 'temporary',
                resources: application.resources.map(r => String(r.id)),
                building_id: application.building_id,
                baseApplication: {...application, dates: []},
            },
        };
    };

    const handleCancelApplication = async () => {
        if (!application) return;
        try {
            await cancelStatus.mutateAsync({
                applicationId: application.id,
                statusData: {status: 'CANCELLED'},
                secret: props.secret,
            });
            setConfirmCancelOpen(false);
        } catch (err) {
            console.error('Failed to cancel application:', err);
        }
    };

    if (isLoading) {
        return (
            <div style={{display: 'flex', justifyContent: 'center', padding: '3rem'}}>
                <Spinner data-size="lg" aria-label={t('common.loading')}/>
            </div>
        );
    }

    if (error || !application) {
        return (
            <main>
                <Heading level={2} data-size="sm">{t('common.error')}</Heading>
                <Paragraph>{t('common.application not found')}</Paragraph>
            </main>
        );
    }

    const isPending = application.status === 'PENDING' || application.status === 'NEW';
    const isAccepted = application.status === 'ACCEPTED';
    const isRejected = application.status === 'REJECTED';
    const isCancelled = application.status === 'CANCELLED';
    const isOrg = application.application_type === 'organization';
    const orderTotal = application.orders.reduce((s, o) => s + o.sum, 0);

    return (
        <main>
            {/* Back link */}
            <Link href="/user/applications" className={styles.backLink}>
                <ArrowLeftIcon fontSize="1rem"/>
                {t('bookingfrontend.back_to_applications')}
            </Link>

            {/* App header */}
            <div className={styles.appHeader}>
                <div className={styles.titleBlock}>
                    <div className={styles.metaLine}>
                        <span>{application.id_string}</span>
                        <span>&middot;</span>
                        <span>{isOrg ? application.customer_organization_name : t('bookingfrontend.personal')}</span>
                    </div>
                    <Heading level={1} data-size="lg">{application.name}</Heading>
                    {isParentApplication && (
                        <Tag data-size="sm" data-color="info">{t('bookingfrontend.combined_application')}</Tag>
                    )}
                    <div className={styles.metaRow}>
                        <Tag data-color={getStatusColor(application.status)} data-size="md">
                            {t(`bookingfrontend.${application.status.toLowerCase()}`)}
                        </Tag>
                        <span className={styles.metaItem}>
                            <PinIcon fontSize="0.9rem"/>{application.building_name}
                        </span>
                        <span className={styles.metaItem}>
                            <CalendarIcon fontSize="0.9rem"/>
                            {application.dates.length} {application.dates.length === 1
                                ? (t('bookingfrontend.date'))
                                : (t('bookingfrontend.dates'))}
                        </span>
                        {RecurringInfoUtils.isRecurring(application) && (
                            <Tag data-size="sm" data-color="neutral">{t('bookingfrontend.recurring_application')}</Tag>
                        )}
                    </div>
                </div>
                <div className={styles.appActions}>
                    {isPending && (
                        <>
                            <Button variant="secondary" data-size="sm">
                                <PencilIcon fontSize="0.9rem"/>
                                {t('bookingfrontend.edit')}
                            </Button>
                            <Button
                                data-color="danger"
                                variant="secondary"
                                data-size="sm"
                                onClick={() => setConfirmCancelOpen(true)}
                            >
                                <XMarkOctagonIcon fontSize="0.9rem"/>
                                {t('bookingfrontend.withdraw_application')}
                            </Button>
                        </>
                    )}
                    {(isAccepted || isRejected || isCancelled) && (
                        <Button variant="secondary" data-size="sm" onClick={() => setShowCopyDialog(true)}>
                            <FilesIcon fontSize="0.9rem"/>
                            {t('bookingfrontend.copy_application')}
                        </Button>
                    )}
                </div>
            </div>

            {/* Status banner */}
            {isPending && (
                <Alert data-color="warning" style={{marginBottom: 20}}>
                    <Heading level={3} data-size="xs">
                        {t('bookingfrontend.application_pending_title')}
                    </Heading>
                    <Paragraph>
                        {t('bookingfrontend.application_pending_description')}
                    </Paragraph>
                </Alert>
            )}
            {isAccepted && (
                <Alert data-color="success" style={{marginBottom: 20}}>
                    <Heading level={3} data-size="xs">
                        {t('bookingfrontend.application_accepted_title')}
                    </Heading>
                    <Paragraph>
                        {t('bookingfrontend.application_accepted_description')}
                    </Paragraph>
                </Alert>
            )}
            {isRejected && (
                <Alert data-color="info" style={{marginBottom: 20}}>
                    <Heading level={3} data-size="xs">
                        {t('bookingfrontend.application_rejected_title')}
                    </Heading>
                    <Paragraph>
                        {t('bookingfrontend.application_rejected_description')}
                    </Paragraph>
                </Alert>
            )}

            {/* Main grid */}
            <div className={styles.detailGrid}>
                <div className={styles.detailMain}>

                    {/* Saksgang (status timeline) */}
                    <div className={styles.card}>
                        <div className={styles.cardHeader}>
                            <Heading level={2} data-size="sm">
                                {t('bookingfrontend.case_history')}
                            </Heading>
                            <span style={{fontSize: 13, color: 'var(--ds-color-neutral-text-subtle)'}}>
                                {t('bookingfrontend.updated')} {fmtSqlDateTime(application.modified)}
                            </span>
                        </div>
                        <StatusTimeline application={application} t={t}/>
                    </div>

                    {/* Tider og lokale */}
                    <div className={styles.card}>
                        <div className={styles.cardHeader}>
                            <Heading level={2} data-size="sm">
                                {t('bookingfrontend.times_and_venue')}
                            </Heading>
                            <span style={{fontSize: 13, color: 'var(--ds-color-neutral-text-subtle)'}}>
                                {application.dates.length} {t('bookingfrontend.time_slots')}
                            </span>
                        </div>
                        <div className={styles.dlGrid} style={{marginBottom: 16}}>
                            <Field label={t('bookingfrontend.place')}>{application.building_name}</Field>
                            <Field label={t('bookingfrontend.resources')}>
                                <div style={{display: 'flex', alignItems: 'center', gap: 8}}>
                                    <ResourceCircles resources={application.resources} maxCircles={4} size="small"/>
                                    {application.resources.length > 1 && (
                                        <span>{application.resources.map(r => r.name).join(', ')}</span>
                                    )}
                                </div>
                            </Field>
                        </div>
                        <h3 className={styles.sectionCaption}>
                            {t('bookingfrontend.requested_times')}
                        </h3>
                        <DatesList dates={application.dates}/>
                    </div>

                    {/* Om arrangementet */}
                    {(application.description || application.equipment || (application.agegroups && application.agegroups.length > 0)) && (
                        <div className={styles.card}>
                            <Heading level={2} data-size="sm">
                                {t('bookingfrontend.about_the_event')}
                            </Heading>
                            {application.description && (
                                <Paragraph style={{margin: '12px 0 0', lineHeight: 1.6}}>
                                    {application.description}
                                </Paragraph>
                            )}
                            {application.equipment && (
                                <>
                                    <div className={styles.divider} style={{margin: '16px 0'}}/>
                                    <Field label={t('bookingfrontend.equipment')}>{application.equipment}</Field>
                                </>
                            )}
                            {application.agegroups && application.agegroups.length > 0 && (() => {
                                const total = application.agegroups.reduce((s, g) => s + (g.male || 0) + (g.female || 0), 0);
                                return (
                                    <>
                                        <div className={styles.divider} style={{margin: '16px 0'}}/>
                                        <Table data-size="sm">
                                            <Table.Head>
                                                <Table.Row>
                                                    <Table.HeaderCell>{t('bookingfrontend.age_group')}</Table.HeaderCell>
                                                    <Table.HeaderCell>{t('bookingfrontend.participants')}</Table.HeaderCell>
                                                </Table.Row>
                                            </Table.Head>
                                            <Table.Body>
                                                {application.agegroups.map(group => (
                                                    <Table.Row key={group.id}>
                                                        <Table.Cell>{group.name}</Table.Cell>
                                                        <Table.Cell>{(group.male || 0) + (group.female || 0)}</Table.Cell>
                                                    </Table.Row>
                                                ))}
                                                <Table.Row>
                                                    <Table.Cell><strong>{t('common.total')}</strong></Table.Cell>
                                                    <Table.Cell><strong>{total}</strong></Table.Cell>
                                                </Table.Row>
                                            </Table.Body>
                                        </Table>
                                    </>
                                );
                            })()}
                        </div>
                    )}

                    {/* Kommunikasjon med saksbehandler */}
                    <div className={styles.card}>
                        <CommentsSection
                            applicationId={props.applicationId}
                            secret={props.secret || application.secret || undefined}
                            isCancelled={isCancelled}
                            t={t}
                        />
                    </div>

                    {/* Pris og betaling */}
                    {application.orders && application.orders.length > 0 && (
                        <Details defaultOpen>
                            <Details.Summary>
                                {t('bookingfrontend.orders_articles')} — {orderTotal.toLocaleString('no-NO')} kr
                            </Details.Summary>
                            <Details.Content>
                                <ArticleTable
                                    orders={application.orders}
                                    mode="orders"
                                    readOnly={true}
                                />
                                <div className={styles.infoNote}>
                                    <InformationSquareIcon fontSize="0.9rem"/>
                                    <span>
                                        {isAccepted
                                            ? (t('bookingfrontend.invoice_after_event'))
                                            : (t('bookingfrontend.price_confirmed_on_approval'))}
                                    </span>
                                </div>
                            </Details.Content>
                        </Details>
                    )}

                    {/* Dokumenter */}
                    <Details>
                        <Details.Summary>
                            {t('bookingfrontend.application_documents')} ({applicationDocuments?.length || 0})
                        </Details.Summary>
                        <Details.Content>
                            {applicationDocuments && applicationDocuments.length > 0 ? (
                                <div className={styles.docList}>
                                    {applicationDocuments.map(doc => (
                                        <div key={doc.id} className={styles.docRow}>
                                            <a
                                                href={getDocumentLink(doc, 'application')}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className={styles.docItem}
                                            >
                                                <span className={styles.docIcon}>
                                                    <FileIcon fontSize="1rem"/>
                                                </span>
                                                <div className={styles.docMeta}>
                                                    <div className={styles.docName}>{doc.name}</div>
                                                </div>
                                                <span style={{fontSize: 13, color: 'var(--ds-color-neutral-text-subtle)'}}>
                                                    {t('bookingfrontend.download')}
                                                </span>
                                            </a>
                                            {!isCancelled && (
                                                <Button
                                                    variant="tertiary"
                                                    data-color="danger"
                                                    data-size="sm"
                                                    onClick={() => handleDeleteDocument(doc.id, doc.name)}
                                                    disabled={deleteDocumentMutation.isPending}
                                                    aria-label={`${t('bookingfrontend.delete_document')}: ${doc.name}`}
                                                >
                                                    {deleteDocumentMutation.isPending ? (
                                                        <Spinner data-size="xs" aria-hidden="true"/>
                                                    ) : (
                                                        <TrashIcon fontSize="1rem"/>
                                                    )}
                                                </Button>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <Paragraph data-size="sm" style={{color: 'var(--ds-color-neutral-text-subtle)'}}>
                                    {t('bookingfrontend.no documents available')}
                                </Paragraph>
                            )}

                            {!isCancelled && (
                                <div className={styles.uploadArea}>
                                    <input
                                        ref={fileInputRef}
                                        type="file"
                                        accept={ACCEPTED_FILE_TYPES}
                                        onChange={handleFileUpload}
                                        style={{display: 'none'}}
                                        multiple
                                    />
                                    <Button
                                        variant="secondary"
                                        data-size="sm"
                                        onClick={() => fileInputRef.current?.click()}
                                        disabled={uploadDocumentMutation.isPending}
                                    >
                                        {uploadDocumentMutation.isPending ? (
                                            <Spinner data-size="xs" aria-hidden="true"/>
                                        ) : (
                                            <PlusIcon fontSize="1rem"/>
                                        )}
                                        {t('bookingfrontend.upload_document')}
                                    </Button>
                                    {uploadError && (
                                        <Paragraph data-size="sm" style={{color: 'var(--ds-color-danger-text-default)', marginTop: 8}}>
                                            {uploadError}
                                        </Paragraph>
                                    )}
                                </div>
                            )}
                        </Details.Content>
                    </Details>

                    {/* Vilkår og reglement */}
                    {regulationDocuments && regulationDocuments.length > 0 && (
                        <Details>
                            <Details.Summary>
                                {t('bookingfrontend.terms and conditions')}
                            </Details.Summary>
                            <Details.Content>
                                <div className={styles.docList}>
                                    {regulationDocuments.map(doc => (
                                        <a
                                            key={doc.id}
                                            href={getDocumentLink(doc, doc.owner_type || 'resource')}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className={styles.docItem}
                                        >
                                            <span className={styles.docIcon}>
                                                <FileIcon fontSize="1rem"/>
                                            </span>
                                            <div className={styles.docMeta}>
                                                <div className={styles.docName}>{doc.name}</div>
                                            </div>
                                            <span style={{fontSize: 13, color: 'var(--ds-color-neutral-text-subtle)'}}>
                                                {t('bookingfrontend.open_document')}
                                            </span>
                                        </a>
                                    ))}
                                </div>
                            </Details.Content>
                        </Details>
                    )}
                </div>

                {/* Sidebar */}
                <aside className={styles.detailSide}>
                    {/* Hurtigfakta */}
                    <div className={styles.card}>
                        <h3 className={styles.sectionCaption} style={{marginTop: 0}}>
                            {t('bookingfrontend.quick_facts')}
                        </h3>
                        <div style={{display: 'flex', flexDirection: 'column', gap: 14}}>
                            <Field label={t('bookingfrontend.type')}>
                                {isOrg
                                    ? (t('bookingfrontend.on_behalf_of_organization'))
                                    : t('bookingfrontend.personal')}
                            </Field>
                            {application.customer_organization_name && (
                                <Field label={t('bookingfrontend.organization')}>
                                    {application.customer_organization_name}
                                </Field>
                            )}
                            {application.customer_organization_name && application.customer_organization_number && (
                                <Field label={t('bookingfrontend.organization_number')}>
                                    {application.customer_organization_number}
                                </Field>
                            )}
                            <Field label={t('bookingfrontend.organizer')}>{application.organizer}</Field>
                            {application.homepage && (
                                <Field label={t('bookingfrontend.homepage')}>
                                    <a href={application.homepage} target="_blank" rel="noopener noreferrer" className={styles.accentLink}>
                                        {application.homepage}
                                    </a>
                                </Field>
                            )}
                            {application.responsible_street && (
                                <Field label={t('bookingfrontend.address')}>
                                    {application.responsible_street}
                                    {(application.responsible_zip_code || application.responsible_city) && (
                                        <>, {application.responsible_zip_code} {application.responsible_city}</>
                                    )}
                                </Field>
                            )}
                            <Field label={t('bookingfrontend.sent')}>
                                {fmtSqlDate(application.created)}
                            </Field>
                            {application.status !== 'NEW' && (
                                <Field label={t('bookingfrontend.modified')}>
                                    {fmtSqlDateTime(application.modified)}
                                </Field>
                            )}
                        </div>
                    </div>

                    {/* Kontaktperson */}
                    <div className={styles.card}>
                        <h3 className={styles.sectionCaption} style={{marginTop: 0}}>
                            {t('bookingfrontend.contact_person')}
                        </h3>
                        <div style={{display: 'flex', flexDirection: 'column', gap: 10}}>
                            <div style={{display: 'flex', alignItems: 'center', gap: 10}}>
                                <span className={styles.avatarSm}>
                                    {getInitials(application.contact_name)}
                                </span>
                                <div style={{minWidth: 0}}>
                                    <div style={{fontWeight: 500}}>{application.contact_name}</div>
                                    <div style={{fontSize: 13, color: 'var(--ds-color-neutral-text-subtle)'}}>
                                        {t('bookingfrontend.responsible_applicant')}
                                    </div>
                                </div>
                            </div>
                            <div className={styles.divider}/>
                            <Field label={t('common.email')}>
                                <a href={`mailto:${application.contact_email}`} className={styles.accentLink}>
                                    {application.contact_email}
                                </a>
                            </Field>
                            <Field label={t('common.phone')}>
                                <a href={`tel:${application.contact_phone}`} className={styles.accentLink}>
                                    {application.contact_phone}
                                </a>
                            </Field>
                        </div>
                    </div>

                    {/* Tips while waiting */}
                    {isPending && (
                        <div className={styles.tipsCard}>
                            <Heading level={3} data-size="xs" style={{color: 'var(--ds-color-warning-text-default)', marginBottom: 8}}>
                                {t('bookingfrontend.tips_while_waiting')}
                            </Heading>
                            <Paragraph data-size="sm" style={{color: 'var(--ds-color-warning-text-default)', lineHeight: 1.55}}>
                                {t('bookingfrontend.processing_time_info')}
                            </Paragraph>
                        </div>
                    )}
                </aside>
            </div>

            {/* Cancel confirmation dialog */}
            <Dialog
                open={confirmCancelOpen}
                onClose={() => setConfirmCancelOpen(false)}
                closedby="any"
            >
                <Dialog.Block>
                    <Heading level={2} data-size="sm">
                        {t('bookingfrontend.confirm_cancel_title')}
                    </Heading>
                    <Paragraph style={{margin: '12px 0'}}>
                        {t('bookingfrontend.confirm_cancel_description')}
                    </Paragraph>
                </Dialog.Block>
                <Dialog.Block>
                    <div style={{display: 'flex', justifyContent: 'flex-end', gap: 8}}>
                        <Button variant="tertiary" onClick={() => setConfirmCancelOpen(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            data-color="danger"
                            onClick={handleCancelApplication}
                            disabled={cancelStatus.isPending}
                        >
                            {cancelStatus.isPending
                                ? <Spinner data-size="xs" aria-hidden="true"/>
                                : null}
                            {t('bookingfrontend.withdraw_application')}
                        </Button>
                    </div>
                </Dialog.Block>
            </Dialog>

            {/* Copy Application Dialog */}
            {showCopyDialog && application && (
                <ApplicationCrud
                    selectedTempApplication={createTempEventFromApplication()}
                    building_id={application.building_id}
                    onClose={() => setShowCopyDialog(false)}
                />
            )}
        </main>
    );
};

export default ApplicationDetails;
