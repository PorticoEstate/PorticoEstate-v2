'use client';
import {FC, useEffect, useMemo, useState} from 'react';
import {Button, Details, Field, Label, Select} from '@digdir/designsystemet-react';
import {MinusCircleIcon, PlusCircleIcon, ChatElipsisIcon} from '@navikt/aksel-icons';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
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
import styles from './hospitality.module.scss';

interface HospitalityOrderModalProps {
    open: boolean;
    onClose: () => void;
    hospitality: IHospitality;
    applicationId: number;
    applications: IApplication[];
    existingOrder?: IHospitalityOrder;
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

function getCutoffMs(hospitality: IHospitality): number | null {
    if (!hospitality.order_by_time_value || !hospitality.order_by_time_unit) return null;
    const value = hospitality.order_by_time_value;
    if (hospitality.order_by_time_unit === 'hours') return value * 3600000;
    if (hospitality.order_by_time_unit === 'days') return value * 86400000;
    return null;
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
            const timeStr = `${String(from.getHours()).padStart(2, '0')}:${String(from.getMinutes()).padStart(2, '0')} - ${String(to.getHours()).padStart(2, '0')}:${String(to.getMinutes()).padStart(2, '0')}`;
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
    hospitality,
    applicationId,
    applications,
    existingOrder,
}) => {
    const t = useTrans();
    const {data: menu, isLoading: menuLoading} = useHospitalityMenu(open ? hospitality.id : undefined);
    const createMutation = useCreateHospitalityOrder(applicationId);
    const updateMutation = useUpdateHospitalityOrder(applicationId);

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
        return hospitality.delivery_locations.filter(loc =>
            loc.location_type === 'main' || appResourceIds.has(loc.id)
        );
    }, [hospitality.delivery_locations, appResourceIds]);

    const selectedDateOption = useMemo(
        () => dateOptions.find(d => d.key === selectedDateKey),
        [dateOptions, selectedDateKey]
    );

    const timeSlots = useMemo(() => {
        if (!selectedDateOption) return [];
        return generateTimeSlots(
            selectedDateOption.from.getHours(),
            selectedDateOption.from.getMinutes(),
            selectedDateOption.to.getHours(),
            selectedDateOption.to.getMinutes()
        );
    }, [selectedDateOption]);

    // Cutoff check
    const cutoffCheck = useMemo(() => {
        if (!selectedDateOption || !selectedTime) return {valid: true, message: ''};
        const cutoffMs = getCutoffMs(hospitality);
        if (!cutoffMs) return {valid: true, message: ''};

        const dateStr = selectedDateOption.from.toISOString().split('T')[0];
        const servingDate = new Date(`${dateStr}T${selectedTime}:00`);
        const timeDiff = servingDate.getTime() - Date.now();

        if (timeDiff < cutoffMs) {
            const unitLabel = hospitality.order_by_time_unit === 'hours'
                ? t('bookingfrontend.hours').toLowerCase() : t('bookingfrontend.days').toLowerCase();
            const msg = t('bookingfrontend.order_cutoff_warning')
                .replace('%1', String(hospitality.order_by_time_value))
                .replace('%2', unitLabel);
            return {
                valid: false,
                message: msg,
            };
        }
        return {valid: true, message: ''};
    }, [selectedDateOption, selectedTime, hospitality, t]);

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
                const match = dateOptions.find(d => {
                    const dDate = d.from.toISOString().split('T')[0];
                    const eDate = existingDate.toISOString().split('T')[0];
                    return dDate === eDate;
                });
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
    }, [existingOrder, availableLocations, open, dateOptions]);

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
    const menuEnabled = dateSelected && !!locationId && !!selectedTime && cutoffCheck.valid;
    const canSave = hasItems && menuEnabled;

    const increment = (id: number) =>
        setQuantities(prev => ({...prev, [id]: (prev[id] || 0) + 1}));

    const decrement = (id: number) =>
        setQuantities(prev => {
            const cur = prev[id] || 0;
            return cur <= 0 ? prev : {...prev, [id]: cur - 1};
        });

    const handleSave = async () => {
        if (!canSave || !selectedDateOption) return;

        const dateStr = selectedDateOption.from.toISOString().split('T')[0];
        const servingTimeIso = new Date(`${dateStr}T${selectedTime}:00`).toISOString();

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
        return (
            <div key={article.id} className={`${styles.menuItem} ${qty === 0 ? styles.dimmed : ''}`}>
                <div className={styles.menuRow}>
                    <span className={styles.menuName}>
                        {article.article_name}
                        {article.description && (
                            <span className={styles.menuDesc}>
                                {Object.values(article.description)[0]}
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

    return (
        <Dialog
            open={open}
            onClose={onClose}
            dialogId={`hospitality-order-${hospitality.id}`}
            title={existingOrder
                ? `${t('bookingfrontend.edit')} - ${hospitality.name}`
                : `${t('bookingfrontend.add_hospitality_order')} - ${hospitality.name}`
            }
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
            {menuLoading ? (
                <div style={{padding: '2rem', textAlign: 'center'}}>
                    {t('common.loading')}
                </div>
            ) : (
                <div className={styles.modalContent}>
                    {/* Top row: Date | Location | Time */}
                    <div className={styles.topRow}>
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
                                {dateOptions.map(d => (
                                    <option key={d.key} value={d.key}>{d.label}</option>
                                ))}
                            </Select>
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

                    {/* Cutoff warning */}
                    {selectedTime && !cutoffCheck.valid && (
                        <div className={styles.cutoffWarning}>
                            {cutoffCheck.message}
                        </div>
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
            )}
        </Dialog>
    );
};

export default HospitalityOrderModal;
