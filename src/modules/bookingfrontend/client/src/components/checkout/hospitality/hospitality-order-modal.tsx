'use client';
import {FC, useEffect, useMemo, useState} from 'react';
import {Alert, Button, Details, Field, Label, Paragraph, Select} from '@digdir/designsystemet-react';
import {MinusCircleIcon, PlusCircleIcon, ChatElipsisIcon} from '@navikt/aksel-icons';
import {useClientTranslation} from '@/app/i18n/ClientTranslationProvider';
import {fallbackLng} from '@/app/i18n/settings';
import Dialog from '@/components/dialog/mobile-dialog';
import {
    IHospitality,
    IHospitalityOrder,
    IHospitalityArticle,
} from '@/service/types/api/hospitality.types';
import {IApplication, IApplicationDate} from '@/service/types/api/application.types';
import {
    useHospitalityMenu,
    useCreateHospitalityOrder,
    useUpdateHospitalityOrder,
} from '../hooks/hospitality-hooks';
import {formatCurrency} from '@/utils/cost-utils';
import {
    computeHospitalityDeadline,
    isWorkingDaysMode,
    formatOpenDays,
    isServingDayOpen,
    isoWeekdayInVenueTz,
    formatWeekdayName,
} from '@/utils/hospitality-deadline';
import styles from './hospitality.module.scss';

interface HospitalityOrderModalProps {
    open: boolean;
    onClose: () => void;
    hospitalities: IHospitality[];
    selectedHospitality: IHospitality | null;
    onHospitalitySelect: (hospitality: IHospitality) => void;
    applications: IApplication[];
    existingOrder?: IHospitalityOrder;
}

/** Key of the synthetic option carrying a legacy order's stored serving time. */
const GRANDFATHERED_DATE_KEY = 'grandfathered';

function formatHm(date: Date): string {
    return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

function generateTimeSlots(fromHour: number, fromMinute: number, toHour: number, toMinute: number): string[] {
    const slots: string[] = [];
    let h = fromHour;
    let m = Math.ceil(fromMinute / 15) * 15;
    if (m >= 60) {
        h++;
        m = 0;
    }
    while (h < toHour || (h === toHour && m <= toMinute)) {
        slots.push(`${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`);
        m += 15;
        if (m >= 60) {
            h++;
            m = 0;
        }
    }
    return slots;
}

function localizeField(field: Record<string, string> | null | undefined, lang: string): string | null {
    if (!field) return null;
    return field[lang] || field[fallbackLng.key] || Object.values(field).find(v => !!v) || null;
}

/** Build date options from application dates. Each option is a unique date+timerange from one application date entry. */
function buildDateOptions(applications: IApplication[]) {
    const options: { key: string; from: Date; to: Date; label: string; applicationId: number }[] = [];
    applications.forEach(app => {
        app.dates?.forEach(d => {
            const from = new Date(d.from_);
            const to = new Date(d.to_);
            const key = `${app.id}_${d.id}`;
            const dateStr = from.toLocaleDateString('nb-NO', {weekday: 'short', day: 'numeric', month: 'short'});
            const timeStr = `${formatHm(from)} - ${formatHm(to)}`;
            options.push({
                key,
                from,
                to,
                label: `${dateStr} | ${timeStr}`,
                applicationId: app.id,
            });
        });
    });
    return options.sort((a, b) => a.from.getTime() - b.from.getTime());
}

const HospitalityOrderModal: FC<HospitalityOrderModalProps> = ({
    open,
    onClose,
    hospitalities,
    selectedHospitality,
    onHospitalitySelect,
    applications,
    existingOrder,
}) => {
    const {t, i18n} = useClientTranslation();
    const hospitality = selectedHospitality;
    const {data: menu, isLoading: menuLoading} = useHospitalityMenu(open && hospitality ? hospitality.id : undefined);
    const showHospitalitySelector = hospitalities.length > 1 && !existingOrder;

    const [selectedDateKey, setSelectedDateKey] = useState('');
    const [selectedTime, setSelectedTime] = useState('');
    const [locationId, setLocationId] = useState<number>(0);
    const [quantities, setQuantities] = useState<Record<number, number>>({});
    const [comments, setComments] = useState<Record<number, string>>({});
    const [visibleComments, setVisibleComments] = useState<Set<number>>(new Set());
    const [editingQty, setEditingQty] = useState<number | null>(null);

    const dateOptions = useMemo(() => buildDateOptions(applications), [applications]);

    // Collect all booked resource IDs from the applications
    const appResourceIds = useMemo(() => {
        const ids = new Set<number>();
        applications.forEach(app => {
            app.resources?.forEach(r => ids.add(r.id));
        });
        return ids;
    }, [applications]);

    // Filter delivery locations: main (on-site) always included, remote only if resource is booked
    const availableLocations = useMemo(() => {
        if (!hospitality) return [];
        return hospitality.delivery_locations.filter(loc =>
            loc.location_type === 'main' || appResourceIds.has(loc.id)
        );
    }, [hospitality, appResourceIds]);

    /**
     * A legacy order can sit on an application that does not own its serving date — the
     * mis-attribution this fixes. Its stored instant then matches no date option of its own
     * application, and it must stay editable: the API re-validates the serving day only when
     * the instant moves (#373). So the stored time is offered as its own option rather than
     * leaving the order unsavable.
     */
    const grandfatheredOption = useMemo(() => {
        if (!existingOrder?.serving_time_iso) return null;
        const stored = new Date(existingOrder.serving_time_iso);
        const storedYmd = stored.toISOString().split('T')[0];
        const ownedByApplication = dateOptions.some(d =>
            d.applicationId === existingOrder.application_id
            && d.from.toISOString().split('T')[0] === storedYmd
        );
        if (ownedByApplication) return null;
        const dateStr = stored.toLocaleDateString('nb-NO', {weekday: 'short', day: 'numeric', month: 'short'});
        return {
            key: GRANDFATHERED_DATE_KEY,
            from: stored,
            to: stored,
            label: `${dateStr} | ${formatHm(stored)}`,
            applicationId: existingOrder.application_id,
        };
    }, [existingOrder, dateOptions]);

    const visibleDateOptions = useMemo(
        () => grandfatheredOption ? [grandfatheredOption, ...dateOptions] : dateOptions,
        [grandfatheredOption, dateOptions]
    );

    const selectedDateOption = useMemo(
        () => visibleDateOptions.find(d => d.key === selectedDateKey),
        [visibleDateOptions, selectedDateKey]
    );

    /**
     * The application this order belongs to. On create it is the application owning the chosen
     * date option — the option carries it exactly, so no date-range matching is needed and
     * overlapping ranges stay unambiguous. On edit it is the order's own application: an order
     * cannot be re-parented, and the API 404s when the URL application id does not match the
     * stored one.
     */
    const targetApplicationId = existingOrder
        ? existingOrder.application_id
        : selectedDateOption?.applicationId;

    const createMutation = useCreateHospitalityOrder(targetApplicationId);
    const updateMutation = useUpdateHospitalityOrder(targetApplicationId);

    const timeSlots = useMemo(() => {
        if (!selectedDateOption) return [];
        // The grandfathered option carries one exact instant — its own stored serving time.
        if (selectedDateOption.key === GRANDFATHERED_DATE_KEY) return [formatHm(selectedDateOption.from)];
        return generateTimeSlots(
            selectedDateOption.from.getHours(),
            selectedDateOption.from.getMinutes(),
            selectedDateOption.to.getHours(),
            selectedDateOption.to.getMinutes()
        );
    }, [selectedDateOption]);

    /**
     * The exact instant that will be sent as serving_time_iso. Derived once so the cutoff check,
     * the serving-day check and the save all judge the same instant the backend will.
     */
    const servingInstant = useMemo(() => {
        if (!selectedDateOption || !selectedTime) return null;
        // A grandfathered order resends its stored instant exactly: the API skips serving-day
        // re-validation only while the instant is unchanged.
        if (selectedDateOption.key === GRANDFATHERED_DATE_KEY) return selectedDateOption.from;
        const dateStr = selectedDateOption.from.toISOString().split('T')[0];
        return new Date(`${dateStr}T${selectedTime}:00`);
    }, [selectedDateOption, selectedTime]);

    /**
     * Serving time of the order as currently stored. The backend re-validates the serving day
     * only when the instant actually moves, so an order placed before a weekday was closed stays
     * editable (lines/comment/location) as long as its serving time is unchanged (#373).
     */
    const storedServingMs = useMemo(() => {
        if (!existingOrder?.serving_time_iso) return null;
        return new Date(existingOrder.serving_time_iso).getTime();
    }, [existingOrder]);

    /**
     * The option holding the existing order's serving day — kept selectable even if closed.
     * Scoped to the order's own application: matching across applications would keep another
     * booking's row selectable on a mis-attributed order.
     */
    const grandfatheredDateKey = useMemo(() => {
        if (!existingOrder?.serving_time_iso) return null;
        const storedYmd = new Date(existingOrder.serving_time_iso).toISOString().split('T')[0];
        return visibleDateOptions.find(d =>
            d.applicationId === existingOrder.application_id
            && d.from.toISOString().split('T')[0] === storedYmd
        )?.key ?? null;
    }, [existingOrder, visibleDateOptions]);

    /**
     * Serving-day check — blocks ordering on a weekday the catering is closed (#373).
     * Mirrors the backend rule exactly: venue-local weekday, and skipped when the serving
     * instant is unchanged (so legacy orders on a now-closed day stay editable).
     */
    const servingDayCheck = useMemo(() => {
        if (!hospitality || !servingInstant) return {valid: true, message: ''};
        if (storedServingMs !== null && servingInstant.getTime() === storedServingMs) {
            return {valid: true, message: ''};
        }
        if (isServingDayOpen(servingInstant, hospitality.open_days_list)) {
            return {valid: true, message: ''};
        }
        return {valid: false, message: t('bookingfrontend.serving_day_closed')};
    }, [hospitality, servingInstant, storedServingMs, t]);

    // Cutoff check — cutoff instant honours working days (mirrors the backend calc)
    const cutoffCheck = useMemo(() => {
        if (!servingInstant || !hospitality) return {valid: true, message: ''};

        const cutoffDate = computeHospitalityDeadline(
            servingInstant,
            hospitality.order_by_time_value,
            hospitality.order_by_time_unit,
            hospitality.open_days_list
        );
        if (!cutoffDate) return {valid: true, message: ''};

        if (Date.now() > cutoffDate.getTime()) {
            const workingDaysMode = isWorkingDaysMode(hospitality.open_days_list)
                && hospitality.order_by_time_unit === 'days';
            const unitLabel = hospitality.order_by_time_unit === 'hours'
                ? t('bookingfrontend.hours').toLowerCase()
                : workingDaysMode
                    ? t('bookingfrontend.working_days').toLowerCase()
                    : t('bookingfrontend.days').toLowerCase();
            const msg = t('bookingfrontend.order_cutoff_warning')
                .replace('%1', String(hospitality.order_by_time_value))
                .replace('%2', unitLabel);
            return {
                valid: false,
                message: msg,
            };
        }
        return {valid: true, message: ''};
    }, [servingInstant, hospitality, t]);

    // Cancellation deadline warning (informational, does not block ordering)
    const cancellationWarning = useMemo(() => {
        if (!selectedDateOption || !hospitality) return null;
        const val = hospitality.resource_cancellation_deadline_value;
        const unit = hospitality.resource_cancellation_deadline_unit;
        if (!val || !unit) return null;

        // Cancellation lead-time comes from the resource; open-days come from the hospitality.
        const cancelBy = computeHospitalityDeadline(selectedDateOption.from, val, unit, hospitality.open_days_list);
        if (!cancelBy) return null;

        if (Date.now() > cancelBy.getTime()) {
            return t('bookingfrontend.cancellation_deadline_passed_warning');
        }
        return t('bookingfrontend.cancellation_deadline_info')
            .replace('%1', cancelBy.toLocaleDateString('nb-NO', {
                day: '2-digit', month: 'short', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            }));
    }, [selectedDateOption, hospitality, t]);

    // Initialize state on open / edit
    useEffect(() => {
        if (!open) return;
        if (existingOrder) {
            setLocationId(existingOrder.location_resource_id);
            const qtyMap: Record<number, number> = {};
            existingOrder.lines.forEach(line => {
                qtyMap[line.hospitality_article_id] = parseFloat(line.quantity);
            });
            setQuantities(qtyMap);
            const commentMap: Record<number, string> = {};
            const visSet = new Set<number>();
            existingOrder.lines.forEach(line => {
                if (line.comment) {
                    commentMap[line.hospitality_article_id] = line.comment;
                    visSet.add(line.hospitality_article_id);
                }
            });
            setComments(commentMap);
            setVisibleComments(visSet);
            // Try to match existing serving time to a date option
            if (existingOrder.serving_time_iso) {
                const existingDate = new Date(existingOrder.serving_time_iso);
                // Scoped to the order's OWN application. Matching across applications would
                // preselect another booking's row on a mis-attributed order — and with two
                // applications holding dates on the same day it can pick the wrong row even
                // for a correctly attributed one.
                const eDate = existingDate.toISOString().split('T')[0];
                const match = visibleDateOptions.find(d =>
                    d.applicationId === existingOrder.application_id
                    && d.from.toISOString().split('T')[0] === eDate
                );
                if (match) {
                    setSelectedDateKey(match.key);
                    setSelectedTime(
                        `${String(existingDate.getHours()).padStart(2, '0')}:${String(existingDate.getMinutes()).padStart(2, '0')}`
                    );
                }
            }
        } else {
            if (availableLocations.length > 0) {
                setLocationId(availableLocations[0].id);
            }
            setQuantities({});
            setComments({});
            setVisibleComments(new Set());
            setSelectedDateKey('');
            setSelectedTime('');
        }
    }, [existingOrder, availableLocations, open, visibleDateOptions]);

    const allArticles = useMemo(() => {
        if (!menu) return [];
        const articles: IHospitalityArticle[] = [];
        menu.groups.forEach(g => articles.push(...g.articles));
        articles.push(...menu.ungrouped_articles);
        return articles;
    }, [menu]);

    const orderTotal = useMemo(() => {
        return allArticles.reduce((sum, a) => {
            const qty = quantities[a.id] || 0;
            return sum + qty * parseFloat(a.effective_price);
        }, 0);
    }, [allArticles, quantities]);

    const hasItems = Object.values(quantities).some(q => q > 0);
    const dateSelected = !!selectedDateKey;
    const menuEnabled = !!hospitality && dateSelected && !!locationId && !!selectedTime
        && cutoffCheck.valid && servingDayCheck.valid;
    const canSave = hasItems && menuEnabled;

    const increment = (id: number) =>
        setQuantities(prev => ({...prev, [id]: (prev[id] || 0) + 1}));

    const decrement = (id: number) =>
        setQuantities(prev => {
            const cur = prev[id] || 0;
            return cur <= 0 ? prev : {...prev, [id]: cur - 1};
        });

    const handleSave = async () => {
        if (!canSave || !servingInstant || !hospitality || !targetApplicationId) return;

        const servingTimeIso = servingInstant.toISOString();

        const lines = Object.entries(quantities)
            .filter(([, qty]) => qty > 0)
            .map(([articleId, qty]) => ({
                hospitality_article_id: parseInt(articleId),
                quantity: qty,
                ...(comments[parseInt(articleId)] ? {comment: comments[parseInt(articleId)]} : {}),
            }));

        if (existingOrder) {
            await updateMutation.mutateAsync({
                orderId: existingOrder.id,
                data: {
                    location_resource_id: locationId,
                    serving_time_iso: servingTimeIso,
                    lines,
                },
            });
        } else {
            await createMutation.mutateAsync({
                hospitality_id: hospitality.id,
                location_resource_id: locationId,
                serving_time_iso: servingTimeIso,
                lines,
            });
        }
        onClose();
    };

    const isSaving = createMutation.isPending || updateMutation.isPending;

    const setQuantity = (articleId: number, value: number) => {
        setQuantities(prev => ({...prev, [articleId]: Math.max(0, value)}));
    };

    const toggleComment = (articleId: number) => {
        setVisibleComments(prev => {
            const next = new Set(prev);
            if (next.has(articleId)) {
                next.delete(articleId);
            } else {
                next.add(articleId);
            }
            return next;
        });
    };

    const setComment = (articleId: number, value: string) => {
        setComments(prev => ({...prev, [articleId]: value}));
    };

    const renderArticle = (article: IHospitalityArticle) => {
        const qty = quantities[article.id] || 0;
        const price = parseFloat(article.effective_price);
        const amount = qty * price;
        const commentText = comments[article.id] || '';
        const commentVisible = visibleComments.has(article.id);
        const description = localizeField(article.description, i18n.language)
            ?? localizeField(article.service_description_json, i18n.language);
        return (
            <div key={article.id} className={styles.menuItem}>
                <div className={styles.menuRow}>
                    <span className={styles.menuName}>
                        {article.article_name}
                        {description && (
                            <span className={styles.menuDesc}>
                                {description}
                            </span>
                        )}
                    </span>
                    <span className={styles.menuUnit}>{article.unit}</span>
                    <span className={styles.menuPrice}>{formatCurrency(price)}</span>
                    <span className={styles.menuQty}>
                        {editingQty === article.id ? (
                            <input
                                type="number"
                                min={0}
                                autoFocus
                                className={styles.qtyInput}
                                value={qty || ''}
                                placeholder="0"
                                onChange={(e) => setQuantity(article.id, parseInt(e.target.value) || 0)}
                                onBlur={() => setEditingQty(null)}
                                onKeyDown={(e) => { if (e.key === 'Enter') setEditingQty(null); }}
                            />
                        ) : (
                            <>
                                <Button
                                    variant="tertiary"
                                    data-size="sm"
                                    data-color="accent"
                                    icon={true}
                                    onClick={() => { setEditingQty(null); setQuantity(article.id, qty - 1); }}
                                    disabled={qty <= 0 || !menuEnabled}
                                >
                                    <MinusCircleIcon aria-hidden="true"/>
                                </Button>
                                <span
                                    className={`${styles.qtyValue} ${menuEnabled ? styles.qtyClickable : ''}`}
                                    onClick={() => menuEnabled && setEditingQty(article.id)}
                                >
                                    {qty}
                                </span>
                                <Button
                                    variant="tertiary"
                                    data-size="sm"
                                    data-color="accent"
                                    icon={true}
                                    onClick={() => { setEditingQty(null); setQuantity(article.id, qty + 1); }}
                                    disabled={!menuEnabled}
                                >
                                    <PlusCircleIcon aria-hidden="true"/>
                                </Button>
                            </>
                        )}
                    </span>
                    <span className={styles.menuAmount}>{qty > 0 ? formatCurrency(amount) : ''}</span>
                    <button
                        type="button"
                        className={`${styles.commentToggle} ${commentText ? styles.commentToggleActive : ''}`}
                        onClick={() => toggleComment(article.id)}
                        disabled={qty <= 0 || !menuEnabled}
                        title={t('bookingfrontend.comment')}
                    >
                        <ChatElipsisIcon fontSize="0.875rem" aria-hidden="true"/>
                    </button>
                </div>
                {commentVisible && qty > 0 && (
                    <div className={styles.lineComment}>
                        <input
                            type="text"
                            className={styles.lineCommentInput}
                            placeholder={`${t('bookingfrontend.comment')}...`}
                            value={commentText}
                            onChange={(e) => setComment(article.id, e.target.value)}
                        />
                    </div>
                )}
            </div>
        );
    };

    const dialogTitle = existingOrder && hospitality
        ? `${t('bookingfrontend.edit')} - ${hospitality.name}`
        : hospitality
            ? `${t('bookingfrontend.add_hospitality_order')} - ${hospitality.name}`
            : t('bookingfrontend.add_hospitality_order');

    return (
        <Dialog
            open={open}
            onClose={onClose}
            dialogId={`hospitality-order-modal`}
            title={dialogTitle}
            stickyFooter
            footer={(attemptClose) => (
                <div className={styles.modalFooter}>
                    <Button variant="tertiary" onClick={attemptClose} disabled={isSaving}>
                        {t('booking.cancel')}
                    </Button>
                    <Button variant="primary" onClick={handleSave} disabled={!canSave || isSaving}>
                        {isSaving
                            ? t('common.saving')
                            : existingOrder ? t('common.save') : t('bookingfrontend.add_hospitality_order')
                        }
                    </Button>
                </div>
            )}
        >
            {/* Main order form */}
            {hospitality && menuLoading ? (
                <div style={{padding: '2rem', textAlign: 'center'}}>
                    {t('common.loading')}
                </div>
            ) : hospitality ? (
                <div className={styles.modalContent}>
                    {/* Top row: Date | Location | Time */}
                    <div className={styles.topRow}>
                        {showHospitalitySelector && (
                            <Field className={styles.topRowField}>
                                <Label>{t('bookingfrontend.hospitality')}</Label>
                                <Select
                                    value={String(hospitality.id)}
                                    onChange={(e) => {
                                        const h = hospitalities.find(h => h.id === parseInt(e.target.value));
                                        if (h) onHospitalitySelect(h);
                                    }}
                                >
                                    {hospitalities.map(h => (
                                        <option key={h.id} value={String(h.id)}>{h.name}</option>
                                    ))}
                                </Select>
                            </Field>
                        )}
                        <Field className={styles.topRowField}>
                            <Label>{t('bookingfrontend.select_serving_date')}</Label>
                            <Select
                                value={selectedDateKey}
                                onChange={(e) => {
                                    setSelectedDateKey(e.target.value);
                                    setSelectedTime('');
                                }}
                            >
                                <option value="">{t('bookingfrontend.select_serving_date')}</option>
                                {visibleDateOptions.map(d => {
                                    // Closed days stay visible (a vanishing booking date is confusing)
                                    // but are not selectable. The existing order's own day is left
                                    // selectable — the backend only re-validates when the serving
                                    // instant moves, so that order stays editable.
                                    const closed = !isServingDayOpen(d.from, hospitality.open_days_list);
                                    // Dates owned by another application stay visible for the same
                                    // reason, but an order cannot be moved between applications.
                                    const otherApplication = !!existingOrder
                                        && d.applicationId !== existingOrder.application_id;
                                    const note = otherApplication
                                        ? ` — ${t('bookingfrontend.date_belongs_to_other_application')}`
                                        : closed
                                            ? ` — ${t('bookingfrontend.closed_on_weekday')
                                                .replace('%1', formatWeekdayName(isoWeekdayInVenueTz(d.from), i18n.language))}`
                                            : '';
                                    return (
                                        <option
                                            key={d.key}
                                            value={d.key}
                                            disabled={otherApplication || (closed && d.key !== grandfatheredDateKey)}
                                        >
                                            {d.label}{note}
                                        </option>
                                    );
                                })}
                            </Select>
                            {existingOrder && dateOptions.some(d => d.applicationId !== existingOrder.application_id) && (
                                <Paragraph data-size="sm">
                                    {t('bookingfrontend.hospitality_cannot_change_application')}
                                </Paragraph>
                            )}
                        </Field>

                        <Field className={styles.topRowField}>
                            <Label>{t('bookingfrontend.delivery_location')}</Label>
                            <Select
                                value={String(locationId)}
                                onChange={(e) => setLocationId(parseInt(e.target.value))}
                                disabled={!dateSelected}
                            >
                                {availableLocations.map(loc => (
                                    <option key={loc.id} value={String(loc.id)}>
                                        {loc.location_type === 'main'
                                            ? `${t('bookingfrontend.serving_at')} ${loc.name}`
                                            : loc.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>

                        <Field className={styles.topRowField}>
                            <Label>{t('bookingfrontend.select_serving_time')}</Label>
                            <Select
                                value={selectedTime}
                                onChange={(e) => setSelectedTime(e.target.value)}
                                disabled={!dateSelected}
                            >
                                <option value="">{t('bookingfrontend.select_serving_time')}</option>
                                {timeSlots.map(slot => (
                                    <option key={slot} value={slot}>{slot}</option>
                                ))}
                            </Select>
                        </Field>
                    </div>

                    {/* Working-days info: which days the catering is open (deadlines counted in working days) */}
                    {isWorkingDaysMode(hospitality.open_days_list) && (
                        <Paragraph data-size="sm" style={{margin: '0 0 0.5rem'}}>
                            {t('bookingfrontend.open_days_label')}:{' '}
                            <strong>{formatOpenDays(hospitality.open_days_list, i18n.language)}</strong>
                            {' — '}{t('bookingfrontend.working_days_deadline_note')}
                        </Paragraph>
                    )}

                    {/* Serving-day block — catering closed on the selected weekday (#373) */}
                    {selectedTime && !servingDayCheck.valid && (
                        <Alert data-color="danger" data-size="sm">
                            {servingDayCheck.message}
                        </Alert>
                    )}

                    {/* Cutoff warning (blocks ordering) */}
                    {selectedTime && !cutoffCheck.valid && (
                        <Alert data-color="danger" data-size="sm">
                            {cutoffCheck.message}
                        </Alert>
                    )}

                    {/* Cancellation deadline warning (informational) */}
                    {cancellationWarning && (
                        <Alert data-color="warning" data-size="sm">
                            {cancellationWarning}
                        </Alert>
                    )}

                    {/* Hospitality-level info / routine text from admin (#374) */}
                    {menu?.hospitality_description && (
                        <Alert data-color="info" data-size="sm" className={styles.hospitalityInfo}>
                            {menu.hospitality_description}
                        </Alert>
                    )}

                    {/* Article menu */}
                    <div className={styles.menuList}>
                        {menu?.groups.map(group => {
                            const groupTotal = group.articles.reduce((sum, a) => {
                                const qty = quantities[a.id] || 0;
                                return sum + qty * parseFloat(a.effective_price);
                            }, 0);
                            return (
                                <Details key={group.id} defaultOpen data-color="neutral">
                                    <Details.Summary>
                                        <div className={styles.groupSummary}>
                                            <span>{group.name}</span>
                                            {groupTotal > 0 && (
                                                <span className={styles.groupTotal}>{formatCurrency(groupTotal)}</span>
                                            )}
                                        </div>
                                    </Details.Summary>
                                    <Details.Content>
                                        {group.articles.map(renderArticle)}
                                    </Details.Content>
                                </Details>
                            );
                        })}

                        {menu?.ungrouped_articles && menu.ungrouped_articles.length > 0 && (
                            <div>
                                {menu.ungrouped_articles.map(renderArticle)}
                            </div>
                        )}

                        {/* Total row */}
                        <div className={styles.totalRow}>
                            <span className={styles.totalLabel}>{t('bookingfrontend.order_total')}</span>
                            <span className={styles.totalAmount}>{formatCurrency(orderTotal)}</span>
                        </div>
                    </div>
                </div>
            ) : null}
        </Dialog>
    );
};

export default HospitalityOrderModal;
