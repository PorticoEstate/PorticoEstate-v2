import React, {Fragment, useEffect} from 'react';
import {useForm, Controller} from 'react-hook-form';
import {z} from 'zod';
import {IBookingUser} from "@/service/types/api.types";
import {zodResolver} from "@hookform/resolvers/zod";
import {Button, Textfield} from "@digdir/designsystemet-react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faPen} from "@fortawesome/free-solid-svg-icons";
import styles from "./user-details-form.module.scss";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";


// Phone number validation
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

// Form schema matching IBookingUser interface
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

// Reuse the existing interfaces from api.types.ts
interface FieldConfig {
    label: string;
    key: keyof EditableBookingUser;
    editable?: boolean;
    placeholder?: string;
    helperText?: string;
    type?: 'text' | 'email' | 'tel' | 'url';
    readOnly?: boolean;
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

// Helper to normalize empty values based on field type
const normalizeEmptyValue = (key: keyof IBookingUser, value: unknown): string | null => {
    // Fields that should be null when empty
    const nullableFields = ['homepage', 'phone', 'email', 'street', 'zip_code', 'city'];

    // Fields that should be empty string when empty
    const emptyStringFields = ['name'];

    if (isEmptyValue(value)) {
        if (nullableFields.includes(key)) return null;
        if (emptyStringFields.includes(key)) return '';
        // Default to null for other fields
        return null;
    }

    // Return the original value if not empty
    return value as string;
};

const fieldCategories: FieldCategory[] = [
    {
        title: 'Personal Information',
        fields: [
            {
                label: 'common.name',
                key: 'name',
                editable: true,
                type: 'text'
            },
            {
                label: 'bookingfrontend.ssn',
                key: 'ssn',
                editable: false,
                type: 'text'
            },
            {
                label: 'Phone',
                key: 'phone',
                editable: true,
                type: 'tel',
                placeholder: '+47 XXXXXXXX',
                helperText: 'Norwegian numbers: +47 XXXXXXXX or 9XXXXXXX or 4XXXXXXX'
            },
            {
                label: 'Email',
                key: 'email',
                editable: true,
                type: 'email',
                placeholder: 'email@example.com'
            },
            {
                label: 'Homepage',
                key: 'homepage',
                editable: true,
                type: 'url',
                placeholder: 'https://example.com'
            },
        ],
    },
    {
        title: 'Address Information',
        fields: [
            {
                label: 'Street',
                key: 'street',
                editable: true,
                type: 'text'
            },
            {
                label: 'ZIP Code',
                key: 'zip_code',
                editable: true,
                type: 'text',
                placeholder: '0000'
            },
            {
                label: 'City',
                key: 'city',
                editable: true,
                type: 'text'
            },
        ],
    },
];

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
        getValues
    } = useForm<UserFormData>({
        resolver: zodResolver(userFormSchema),
        defaultValues: {
            name: user.name || null,
            ssn: user.ssn,
            homepage: user.homepage || null,
            phone: user.phone || null,
            email: user.email || null,
            street: user.street || null,
            zip_code: user.zip_code || null,
            city: user.city || null,
        },
    });

    useEffect(() => {
        // Only reset if the user data has actually changed
        if (JSON.stringify(user) !== JSON.stringify(lastResetUser)) {

            // Reset the form with new values
            reset({
                name: user.name || null,
                ssn: user.ssn,
                homepage: user.homepage || null,
                phone: user.phone || null,
                email: user.email || null,
                street: user.street || null,
                zip_code: user.zip_code || null,
                city: user.city || null,
            });

            // Update our reference to the last reset user data
            setLastResetUser(user);
        }
    }, [user, reset, lastResetUser, getValues]);


    const onSubmit = async (formData: UserFormData) => {
        try {
            setIsSubmitting(true);

            const DEBUG = process.env.NODE_ENV === 'development';

            // Create an object with only the changed fields
            const changedFields = Object.entries(formData).reduce<Partial<IBookingUser>>(
                (acc, [key, newValue]) => {
                    const fieldKey = key as keyof IBookingUser;
                    const originalValue = user[fieldKey];

                    // Normalize both values to handle empty strings consistently
                    const normalizedOriginal = normalizeEmptyValue(fieldKey, originalValue);
                    const normalizedNew = normalizeEmptyValue(fieldKey, newValue);

                    if (DEBUG) {
                        console.group(`Checking field: ${key}`);
                        console.log('Original:', originalValue);
                        console.log('New:', newValue);
                        console.log('Normalized Original:', normalizedOriginal);
                        console.log('Normalized New:', normalizedNew);
                        console.groupEnd();
                    }

                    const hasChanged = normalizedOriginal !== normalizedNew;

                    if (hasChanged) {
                        // @ts-ignore
                        acc[fieldKey] = normalizedNew;
                    }

                    return acc;
                },
                {}
            );

            if (DEBUG) {
                console.group('Update Summary');
                console.log('Changed Fields:', changedFields);
                const changes = Object.entries(changedFields).map(([field, value]) => ({
                    field,
                    from: user[field as keyof IBookingUser],
                    to: value
                }));
                console.table(changes);
                console.groupEnd();
            }

            if (Object.keys(changedFields).length > 0) {
                await onUpdate(changedFields);
                setIsEditing(false);
            } else {
                setIsEditing(false);
            }
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

    return (
        <section>
            <div>
                <form onSubmit={handleSubmit(onSubmit)}>
                    {fieldCategories.map((category) => (
                        <div key={category.title} className={styles.detailCategory}>
                            <h3>{category.title}</h3>
                            <div>
                                {category.fields.map((field) => (
                                    <div key={field.key}>
                                        {isEditing && field.editable ? (
                                            <Controller
                                                name={field.key}
                                                control={control}
                                                render={({field: {onChange, value}}) => (
                                                    <div className={styles.editFieldWrapper}>
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
                                                        {/*{field.helperText && (*/}
                                                        {/*    <p>*/}
                                                        {/*        {field.helperText}*/}
                                                        {/*    </p>*/}
                                                        {/*)}*/}
                                                        {errors[field.key] && (
                                                            <p>
                                                                {errors[field.key]?.message}
                                                            </p>
                                                        )}
                                                    </div>
                                                )}
                                            />
                                        ) : !isEditing && (
                                            <div className={`${styles.viewField} ${isEditing && styles.editing}`}>
                                                <span>{t(field.label)}</span>
                                                <span>{user[field.key] || '-'}</span>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    ))}
                    <div className={styles.actionRow}>
                        {/*{isDirty && <span>isDirty</span>}*/}
                        {/*{isSubmitting && <span>isSubmitting</span>}*/}
                        {/*{!(!isDirty || isSubmitting) ? <span>Saveable</span> : <span>Not saveable</span>}*/}
                        {isEditing && (
                            <Fragment>
                                <Button
                                    type="button"
                                    variant="tertiary"
                                    onClick={handleCancel}
                                    disabled={isSubmitting}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={!isDirty || isSubmitting}
                                >
                                    {isSubmitting ? 'Saving...' : 'Save Changes'}
                                </Button>
                            </Fragment>
                        )}
                        {!isEditing && (
                            <Button
                                variant="primary"
                                data-size="sm"
                                onClick={() => setIsEditing(true)}
                            >
                                <FontAwesomeIcon icon={faPen}/>
                                {t('bookingfrontend.edit')}
                            </Button>
                        )}
                    </div>
                </form>
            </div>
        </section>
    );
};

export default UserDetailsForm;