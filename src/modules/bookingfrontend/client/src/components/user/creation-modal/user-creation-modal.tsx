import React, {useEffect} from 'react';
import {useForm, Controller} from 'react-hook-form';
import {z} from 'zod';
import {zodResolver} from "@hookform/resolvers/zod";
import {Button, Textfield} from "@digdir/designsystemet-react";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import MobileDialog from "@/components/dialog/mobile-dialog";
import {useCreateBookingUser, useExternalUserData} from "@/service/hooks/api-hooks";
import styles from "./user-creation-modal.module.scss";

// Phone number validation (reuse from user-details-form)
const validatePhone = (phone: string) => {
    if (!phone) return true;

    const generalFormat = /^[+]?[- _0-9]+$/;
    if (!generalFormat.test(phone) || phone.length < 8 || phone.length > 20) {
        return false;
    }

    const norwegianPattern = /^(0047|\+47|\d{8})/;
    if (norwegianPattern.test(phone)) {
        // Remove prefix and any whitespace/separators to get clean number
        const trimmedNumber = phone.replace(/^(0047|\+47)/, '').replace(/[- _]/g, '');
        return trimmedNumber.length === 8 && (trimmedNumber[0] === '9' || trimmedNumber[0] === '4');
    }

    return true;
};

// Form schema for user creation
const userCreationSchema = z.object({
    name: z.string().min(1, 'Name is required'),
    phone: z.string()
        .min(1, 'Phone number is required')
        .refine((val) => validatePhone(val), {
            message: 'Invalid phone number format. For Norwegian numbers, use format: +47 XXXXXXXX or 9XXXXXXX or 4XXXXXXX'
        }),
    email: z.string().email('Invalid email address').min(1, 'Email is required'),
    street: z.string().optional(),
    zip_code: z.string()
        .optional()
        .refine((val) => !val || val.trim() === '' || val.trim().length >= 4, {
            message: 'Zip code must be at least 4 characters'
        }),
    city: z.string().optional(),
    homepage: z.string()
        .optional()
        .refine((val) => !val || val.trim() === '' || z.string().url().safeParse(val).success, {
            message: 'Invalid URL format'
        }),
});

type UserCreationFormData = z.infer<typeof userCreationSchema>;

interface UserCreationModalProps {
    open: boolean;
    onClose: () => void;
    onUserCreated?: () => void;
}

const UserCreationModal: React.FC<UserCreationModalProps> = ({
    open,
    onClose,
    onUserCreated
}) => {
    const t = useTrans();
    const { data: externalData, isLoading: isLoadingExternal } = useExternalUserData();
    const { mutateAsync: createUser } = useCreateBookingUser();
    const [isSubmitting, setIsSubmitting] = React.useState(false);

    const {
        control,
        handleSubmit,
        reset,
        formState: { errors, isDirty, isValid }
    } = useForm<UserCreationFormData>({
        resolver: zodResolver(userCreationSchema),
        defaultValues: {
            name: '',
            phone: '',
            email: '',
            street: '',
            zip_code: '',
            city: '',
            homepage: ''
        },
    });

    // Pre-fill form with external data when available
    useEffect(() => {
        if (externalData) {
            reset({
                name: externalData.name || '',
                phone: externalData.phone || '',
                email: externalData.email || '',
                street: externalData.street || '',
                zip_code: externalData.zip_code || '',
                city: externalData.city || '',
                homepage: externalData.homepage || ''
            });
        }
    }, [externalData, reset]);

    const onSubmit = async (formData: UserCreationFormData) => {
        try {
            setIsSubmitting(true);

            // Convert empty strings to null for nullable fields
            const userData = {
                name: formData.name,
                phone: formData.phone || null,
                email: formData.email || null,
                street: formData.street || null,
                zip_code: formData.zip_code || null,
                city: formData.city || null,
                homepage: formData.homepage || null,
            };

            await createUser(userData);

            // Call success callback
            onUserCreated?.();
            // onClose();

        } catch (error) {
            console.error('Failed to create user:', error);
        } finally {
            setIsSubmitting(false);
        }
    };

    const handleCancel = () => {
        reset();
        onClose();
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

    const footer = (
        <div className={styles.modalFooter}>
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
                form="user-creation-form"
                disabled={!isDirty || !isValid || isSubmitting}
            >
                {isSubmitting ? t('common.creating') : t('common.create_account')}
            </Button>
        </div>
    );

    return (
        <MobileDialog
            dialogId={'user-creation-modal'}
            open={open}
            onClose={handleCancel}
            title={t('bookingfrontend.create_user_account')}
            footer={footer}
            stickyFooter={true}
        >
            <div className={styles.modalContent}>
                {isLoadingExternal && (
                    <p className={styles.loadingText}>{t('common.loading_external_data')}</p>
                )}

                <form
                    id="user-creation-form"
                    onSubmit={handleSubmit(onSubmit)}
                    className={styles.creationForm}
                >
                    <div className={styles.fieldGroup}>
                        <h3>{t('common.personal information')}</h3>

                        <Controller
                            name="name"
                            control={control}
                            render={({ field: { onChange, value } }) => (
                                <Textfield
                                    label={t('common.name')}
                                    value={value}
                                    onChange={(e) => onChange(e.target.value)}
                                    disabled={isSubmitting}
                                    error={errors.name?.message}
                                    required
                                />
                            )}
                        />

                        <Controller
                            name="phone"
                            control={control}
                            render={({ field: { onChange, value } }) => (
                                <Textfield
                                    type="tel"
                                    label={t('common.phone')}
                                    value={value || ''}
                                    onChange={(e) => {
                                        const formatted = formatPhoneNumber(e.target.value);
                                        onChange(formatted);
                                    }}
                                    placeholder="+47 XXXXXXXX"
                                    disabled={isSubmitting}
                                    error={errors.phone?.message}
                                    required
                                />
                            )}
                        />

                        <Controller
                            name="email"
                            control={control}
                            render={({ field: { onChange, value } }) => (
                                <Textfield
                                    type="email"
                                    label={t('common.email')}
                                    value={value || ''}
                                    onChange={(e) => onChange(e.target.value)}
                                    placeholder="email@example.com"
                                    disabled={isSubmitting}
                                    error={errors.email?.message}
                                    required
                                />
                            )}
                        />

                        <Controller
                            name="homepage"
                            control={control}
                            render={({ field: { onChange, value } }) => (
                                <Textfield
                                    type="url"
                                    label={t('common.homepage')}
                                    value={value || ''}
                                    onChange={(e) => onChange(e.target.value)}
                                    placeholder="https://example.com"
                                    disabled={isSubmitting}
                                    error={errors.homepage?.message}
                                />
                            )}
                        />
                    </div>

                    <div className={styles.fieldGroup}>
                        <h3>{t('common.address information')}</h3>

                        <Controller
                            name="street"
                            control={control}
                            render={({ field: { onChange, value } }) => (
                                <Textfield
                                    label={t('common.street')}
                                    value={value || ''}
                                    onChange={(e) => onChange(e.target.value)}
                                    disabled={isSubmitting}
                                    error={errors.street?.message}
                                />
                            )}
                        />

                        <Controller
                            name="zip_code"
                            control={control}
                            render={({ field: { onChange, value } }) => (
                                <Textfield
                                    label={t('common.zip_code')}
                                    value={value || ''}
                                    onChange={(e) => onChange(e.target.value)}
                                    placeholder="0000"
                                    disabled={isSubmitting}
                                    error={errors.zip_code?.message}
                                />
                            )}
                        />

                        <Controller
                            name="city"
                            control={control}
                            render={({ field: { onChange, value } }) => (
                                <Textfield
                                    label={t('common.city')}
                                    value={value || ''}
                                    onChange={(e) => onChange(e.target.value)}
                                    disabled={isSubmitting}
                                    error={errors.city?.message}
                                />
                            )}
                        />
                    </div>
                </form>
            </div>
        </MobileDialog>
    );
};

export default UserCreationModal;