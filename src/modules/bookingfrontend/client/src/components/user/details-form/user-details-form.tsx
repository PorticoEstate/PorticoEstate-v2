'use client'
import React, {Fragment, useEffect} from 'react';
import {useForm, Controller} from 'react-hook-form';
import {z} from 'zod';
import {IBookingUser} from "@/service/types/api.types";
import {zodResolver} from "@hookform/resolvers/zod";
import {Button, Heading, Paragraph, Tag, Textfield, Checkbox} from "@digdir/designsystemet-react";
import {PencilIcon, PersonGroupIcon, PlusIcon} from "@navikt/aksel-icons";
import styles from "./user-details-form.module.scss";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";


const maskSSN = (ssn: string): string => {
    if (!ssn) return '';
    return ssn.slice(0, -5) + '*****';
};

const validatePhone = (phone: string) => {
    if (!phone) return true;
    const generalFormat = /^\+?[- _0-9]+$/;
    if (!generalFormat.test(phone) || phone.length < 8 || phone.length > 20) {
        return false;
    }
    const norwegianPattern = /^(0047|\+47|\d{8})/;
    if (norwegianPattern.test(phone)) {
        const trimmedNumber = phone.replace(/^(0047|\+47)/, '');
        return trimmedNumber.length === 8 && (trimmedNumber[0] === '9' || trimmedNumber[0] === '4');
    }
    return true;
};

type EditableBookingUser = Pick<IBookingUser, 'name' | 'ssn' | 'homepage' | 'phone' | 'email' | 'street' | 'zip_code' | 'city'>;

const userFormSchema: z.ZodType<EditableBookingUser> = z.object({
    name: z.string().min(1, 'Name is required').nullable(),
    ssn: z.string().nullable(),
    homepage: z.string().url('Invalid URL format').nullable(),
    phone: z.string()
        .nullable()
        .refine((val) => !val || validatePhone(val), {
            message: 'Invalid phone number format. For Norwegian numbers, use format: +47 XXXXXXXX or 9XXXXXXX or 4XXXXXXX'
        }),
    email: z.string().email('Invalid email address').nullable(),
    street: z.string().min(1, 'Street is required').nullable(),
    zip_code: z.string().min(4, 'Invalid zip code').nullable(),
    city: z.string().min(1, 'City is required').nullable(),
});

type UserFormData = z.infer<typeof userFormSchema>;

interface FieldConfig {
    label: string;
    key: keyof EditableBookingUser;
    editable?: boolean;
    placeholder?: string;
    helperText?: string;
    type?: 'text' | 'email' | 'tel' | 'url';
    masked?: boolean;
    readOnly?: boolean;
    fullWidth?: boolean;
}

interface FieldCategory {
    title: string;
    fields: FieldConfig[];
}

interface DetailsProps {
    user: IBookingUser;
    onUpdate: (data: Partial<IBookingUser>) => Promise<void>;
}

const isEmptyValue = (value: unknown): boolean => {
    if (value === null || value === undefined) return true;
    if (typeof value === 'string') return value.trim() === '';
    return false;
};

const normalizeEmptyValue = (key: keyof IBookingUser, value: unknown): string | null => {
    const nullableFields = ['homepage', 'phone', 'email', 'street', 'zip_code', 'city'];
    const emptyStringFields = ['name'];
    if (isEmptyValue(value)) {
        if (nullableFields.includes(key)) return null;
        if (emptyStringFields.includes(key)) return '';
        return null;
    }
    return value as string;
};

const personalFields: FieldConfig[] = [
    {label: 'common.name', key: 'name', editable: true, type: 'text'},
    {label: 'bookingfrontend.ssn', key: 'ssn', editable: false, type: 'text', masked: true, readOnly: true},
    {label: 'common.phone', key: 'phone', editable: true, type: 'tel', placeholder: '+47 XXXXXXXX', helperText: 'common.phone_helper'},
    {label: 'common.email', key: 'email', editable: true, type: 'email', placeholder: 'email@example.com'},
    {label: 'common.homepage', key: 'homepage', editable: true, type: 'url', placeholder: 'https://example.com'},
];

const addressFields: FieldConfig[] = [
    {label: 'common.street', key: 'street', editable: true, type: 'text', fullWidth: true},
    {label: 'common.zip_code', key: 'zip_code', editable: true, type: 'text', placeholder: '0000'},
    {label: 'common.city', key: 'city', editable: true, type: 'text'},
];

function getInitials(name: string | null): string {
    if (!name) return '?';
    return name.split(/\s+/).map(w => w[0]).filter(Boolean).slice(0, 2).join('').toUpperCase();
}

const UserDetailsForm: React.FC<DetailsProps> = ({user, onUpdate}) => {
    const [isEditing, setIsEditing] = React.useState(false);
    const [isSubmitting, setIsSubmitting] = React.useState(false);
    const t = useTrans();
    const [lastResetUser, setLastResetUser] = React.useState(user);
    const {
        control,
        handleSubmit,
        reset,
        formState: {errors, isDirty},
        getValues,
    } = useForm<UserFormData>({
        resolver: zodResolver(userFormSchema),
        defaultValues: {
            name: user.name || null,
            ssn: user.ssn ? maskSSN(user.ssn) : null,
            homepage: user.homepage || null,
            phone: user.phone || null,
            email: user.email || null,
            street: user.street || null,
            zip_code: user.zip_code || null,
            city: user.city || null,
        },
    });

    useEffect(() => {
        if (JSON.stringify(user) !== JSON.stringify(lastResetUser)) {
            reset({
                name: user.name || null,
                ssn: user.ssn ? maskSSN(user.ssn) : null,
                homepage: user.homepage || null,
                phone: user.phone || null,
                email: user.email || null,
                street: user.street || null,
                zip_code: user.zip_code || null,
                city: user.city || null,
            });
            setLastResetUser(user);
        }
    }, [user, reset, lastResetUser, getValues]);

    const onSubmit = async (formData: UserFormData) => {
        try {
            setIsSubmitting(true);
            const changedFields = Object.entries(formData).reduce<Partial<IBookingUser>>(
                (acc, [key, newValue]) => {
                    const fieldKey = key as keyof IBookingUser;
                    const originalValue = user[fieldKey];
                    const normalizedOriginal = normalizeEmptyValue(fieldKey, originalValue);
                    const normalizedNew = normalizeEmptyValue(fieldKey, newValue);
                    if (normalizedOriginal !== normalizedNew) {
                        // @ts-ignore
                        acc[fieldKey] = normalizedNew;
                    }
                    return acc;
                },
                {}
            );

            if (Object.keys(changedFields).length > 0) {
                await onUpdate(changedFields);
            }
            setIsEditing(false);
        } catch (error) {
            console.error('Failed to update user details:', error);
        } finally {
            setIsSubmitting(false);
        }
    };

    const handleCancel = () => {
        reset();
        setIsEditing(false);
    };

    const formatPhoneNumber = (value: string) => {
        let formatted = value.replace(/[^\d+]/g, '');
        if (formatted.startsWith('47') && formatted.length > 2) {
            formatted = '+' + formatted;
        }
        if (formatted.startsWith('+47') && formatted.length > 3) {
            formatted = formatted.slice(0, 3) + ' ' + formatted.slice(3);
        }
        return formatted;
    };

    const renderViewField = (field: FieldConfig) => (
        <div key={field.key} className={styles.field}>
            <span className={styles.fieldLabel}>{t(field.label)}</span>
            <span className={styles.fieldValue}>
                {field.masked
                    ? (user[field.key] ? maskSSN(user[field.key] as string) : '—')
                    : (user[field.key] || '—')}
            </span>
        </div>
    );

    const renderEditField = (field: FieldConfig) => (
        <div key={field.key} className={`${styles.editFieldWrapper} ${field.fullWidth ? styles.fullWidth : ''}`}>
            {field.editable ? (
                <Controller
                    name={field.key}
                    control={control}
                    render={({field: {onChange, value}}) => (
                        <>
                            <Textfield
                                type={field.type || 'text'}
                                label={t(field.label)}
                                value={value || ''}
                                onChange={(e) => {
                                    const newValue = field.key === 'phone'
                                        ? formatPhoneNumber(e.target.value)
                                        : e.target.value;
                                    onChange(newValue);
                                }}
                                placeholder={field.placeholder}
                                disabled={field.readOnly || isSubmitting}
                                error={errors[field.key]?.message}
                            />
                            {field.helperText && (
                                <p>{t(field.helperText)}</p>
                            )}
                        </>
                    )}
                />
            ) : (
                <Textfield
                    label={t(field.label)}
                    value={field.masked ? maskSSN(user[field.key] as string || '') : (user[field.key] as string || '')}
                    readOnly
                    disabled
                />
            )}
            {field.key === 'ssn' && isEditing && (
                <p>
                    {t('bookingfrontend.ssn_from_idporten')}
                </p>
            )}
        </div>
    );

    return (
        <section>
            {/* Page header */}
            <div className={styles.pageHeader}>
                <div>
                    <Heading level={1} data-size="lg">
                        {t('bookingfrontend.user_data')}
                    </Heading>
                    <p className={styles.subtitle}>
                        {t('bookingfrontend.user_data_description')}
                    </p>
                </div>
                {!isEditing && (
                    <Button variant="primary" data-size="sm" onClick={() => setIsEditing(true)}>
                        <PencilIcon fontSize="1rem"/>
                        {t('bookingfrontend.edit')}
                    </Button>
                )}
            </div>

            {/* Profile card */}
            <form onSubmit={handleSubmit(onSubmit)}>
                <div className={styles.card}>
                    {/* Profile header */}
                    <div className={styles.profileHeader}>
                        <div className={styles.left}>
                            <span className={styles.avatarLg}>{getInitials(user.name)}</span>
                            <div>
                                <Heading level={2} data-size="sm" style={{marginBottom: 4}}>
                                    {user.name || '—'}
                                </Heading>
                                <div className={styles.profileMeta}>
                                    <span>{t('bookingfrontend.logged_in_with_idporten')}</span>
                                </div>
                            </div>
                        </div>
                        <Tag data-color="accent" data-size="sm">
                            {t('bookingfrontend.verified_via_idporten')}
                        </Tag>
                    </div>

                    {/* Personopplysninger */}
                    <h3 className={styles.sectionCaption}>
                        {t('common.personal information')}
                    </h3>
                    <div className={styles.dlGrid}>
                        {isEditing
                            ? personalFields.map(renderEditField)
                            : personalFields.map(renderViewField)}
                    </div>

                    {/* Adresse */}
                    <h3 className={styles.sectionCaption}>
                        {t('common.address information')}
                    </h3>
                    <div className={styles.dlGrid}>
                        {isEditing
                            ? addressFields.map(renderEditField)
                            : addressFields.map(renderViewField)}
                    </div>

                    {/* Action row */}
                    {isEditing && (
                        <div className={styles.actionRow}>
                            <Button
                                type="button"
                                variant="tertiary"
                                onClick={handleCancel}
                                disabled={isSubmitting}
                            >
                                {t('common.cancel')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={!isDirty || isSubmitting}
                            >
                                {isSubmitting
                                    ? t('common.saving')
                                    : (t('common.save changes'))}
                            </Button>
                        </div>
                    )}
                </div>
            </form>

            {/* Organizations */}
            {user.delegates && user.delegates.length > 0 && (
                <>
                    <div className={styles.sectionHeader}>
                        <Heading level={2} data-size="sm">
                            {t('bookingfrontend.organizations_you_represent')}
                        </Heading>
                        <Button variant="tertiary" data-size="sm">
                            <PlusIcon fontSize="1rem"/>
                            {t('bookingfrontend.add_organization')}
                        </Button>
                    </div>
                    <div className={styles.card} style={{padding: 0}}>
                        {user.delegates.map((d) => (
                            <div key={d.org_id} className={styles.orgRow}>
                                <div className={styles.orgInfo}>
                                    <span className={styles.orgIcon}>
                                        <PersonGroupIcon fontSize="1rem"/>
                                    </span>
                                    <div style={{minWidth: 0}}>
                                        <div style={{fontWeight: 500}}>{d.name}</div>
                                        <div style={{fontSize: 13, color: 'var(--ds-color-neutral-text-subtle)'}}>
                                            {t('bookingfrontend.organization number')} {d.organization_number}
                                        </div>
                                    </div>
                                </div>
                                <Tag data-color="neutral" data-size="sm">
                                    {d.active
                                        ? t('bookingfrontend.active_delegate')
                                        : t('bookingfrontend.inactive')}
                                </Tag>
                            </div>
                        ))}
                    </div>
                </>
            )}

            {/* Notifications section — UI-only, not wired to backend yet */}
            <div className={styles.sectionHeader}>
                <Heading level={2} data-size="sm">
                    {t('bookingfrontend.notifications_and_communication')}
                </Heading>
            </div>
            <div className={styles.card}>
                <div className={styles.toggleRow}>
                    <div className={styles.toggleLabel}>
                        <div className={styles.title}>{t('bookingfrontend.email_notifications')}</div>
                        <div className={styles.helper}>{t('bookingfrontend.email_notifications_description')}</div>
                    </div>
                    <div className={styles.toggleControl}>
                        <Checkbox defaultChecked aria-label={t('bookingfrontend.email_notifications')}/>
                    </div>
                </div>

                <div className={styles.toggleRow}>
                    <div className={styles.toggleLabel}>
                        <div className={styles.title}>{t('bookingfrontend.sms_notifications')}</div>
                        <div className={styles.helper}>{t('bookingfrontend.sms_notifications_description')}</div>
                    </div>
                    <div className={styles.toggleControl}>
                        <Checkbox aria-label={t('bookingfrontend.sms_notifications')}/>
                    </div>
                </div>

                <div className={styles.toggleRow}>
                    <div className={styles.toggleLabel}>
                        <div className={styles.title}>{t('bookingfrontend.newsletter')}</div>
                        <div className={styles.helper}>{t('bookingfrontend.newsletter_description')}</div>
                    </div>
                    <div className={styles.toggleControl}>
                        <Checkbox defaultChecked aria-label="Nyhetsbrev"/>
                    </div>
                </div>
            </div>
        </section>
    );
};

export default UserDetailsForm;
