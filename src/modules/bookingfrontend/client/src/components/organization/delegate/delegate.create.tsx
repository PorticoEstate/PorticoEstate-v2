'use client';
import { Controller, useForm } from "react-hook-form";
import { Button, Textfield } from "@digdir/designsystemet-react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { createDelegateFormSchema, CreatingDelegate } from "./schemas";
import { createDelegate } from "@/service/api/organization";
import { Organization } from "@/service/types/api/organization.types";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faFloppyDisk } from "@fortawesome/free-solid-svg-icons";
import { useRouter } from 'next/navigation'
import styles from './styles/delegater.form.module.scss';

interface DelegateFormProps {
    data: Organization;
}

const DelegateCreate = ({ data }: DelegateFormProps) => {
    const router = useRouter();
    const t = useTrans();
    const {
        control,
        handleSubmit,
        formState: {errors},
    } = useForm({
        mode: 'onChange',
        resolver: zodResolver(createDelegateFormSchema),
    });
    const create = createDelegate(data.id);

    const save = (data: CreatingDelegate) => {
        create.mutate(data);
    }
    return (
        <main className={styles.delegate_create} >
            <div className={styles.buttons_group}>
                <Button variant='secondary' onClick={() => router.back()}>
                    {t('bookingfrontend.cancel')}
                </Button>
                <Button onClick={handleSubmit(save)}>
                    <FontAwesomeIcon icon={faFloppyDisk} />
                    {t('bookingfrontend.save')}
                </Button>
            </div>
            <h2>{t('bookingfrontend.new_delegate')}</h2>
            <Controller 
                name='name'
                control={control}
                render={({ field }) => (
                    <Textfield 
                        {...field}
                        label={t('bookingfrontend.name')}
                        error={errors.name?.message ? t(errors.name.message) : undefined}
                    />
                )}
            />
            <Controller 
                name='ssn'
                control={control}
                render={({ field }) => (
                    <Textfield 
                        {...field}
                        label={t('bookingfrontend.birth_number')}
                        error={
                            errors.ssn?.message 
                            ? t(errors.ssn.message)
                            : create.error ? create.error.message : null
                        }
                    />
                )}
            />
            <Textfield 
                readOnly
                value={data.name}
                label={t('bookingfrontend.organization_name')}
            />
            <Controller 
                name='email'
                control={control}
                render={({ field }) => (
                    <Textfield 
                        {...field}
                        label={t('bookingfrontend.email')}
                        error={errors.email?.message ? t(errors.email.message) : undefined}
                    />
                )}
            />
            <Controller 
                name='phone'
                control={control}
                render={({ field }) => (
                    <Textfield 
                        {...field}
                        label={t('bookingfrontend.phone')}
                        error={errors.phone?.message ? t(errors.phone.message) : undefined}
                    />
                )}
            />
            { create.isSuccess ? <h3>Delegate added</h3> : null }
        </main>
    )
} 

export default DelegateCreate;