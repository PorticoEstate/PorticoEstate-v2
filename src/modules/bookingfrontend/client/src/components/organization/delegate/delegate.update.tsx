'use client';
import { Controller, useForm } from "react-hook-form";
import { Button, Textfield } from "@digdir/designsystemet-react";
import { zodResolver } from "@hookform/resolvers/zod";
import { UpdatingDelegate, updateDelegateFormSchema } from "./schemas";
import { patchDelegate } from "@/service/api/organization";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faFloppyDisk } from "@fortawesome/free-solid-svg-icons";
import { useRouter } from 'next/navigation';
import { useQueryClient } from "@tanstack/react-query";
import styles from './styles/delegater.form.module.scss';
import { ViewDelegate } from "@/service/types/api/organization.types";

interface DelegateUpdateProps {
    delegate: ViewDelegate;
}

const DelegateUpdate = ({ delegate }: DelegateUpdateProps) => {
    const router = useRouter();
    const t = useTrans();
    const {
        control,
        handleSubmit,
        formState: { errors },
    } = useForm({
        mode: 'onChange',
        resolver: zodResolver(updateDelegateFormSchema),   
        defaultValues: {
            name: delegate.name,
            email: delegate.email,
            phone: delegate.phone
        }
    });
    const cl = useQueryClient();
    const update = patchDelegate(delegate.organization.id, delegate.id, cl);
    const updateCb = (data: UpdatingDelegate) => {
        update.mutate(data);
    }
    
    return (
        <main className={styles.delegate_create}>
            <div className={styles.buttons_group}>
                <Button variant='secondary' onClick={() => router.back()}>
                    {t('bookingfrontend.cancel')}
                </Button>
                <Button onClick={handleSubmit(updateCb)}>
                    <FontAwesomeIcon icon={faFloppyDisk} />
                    {t('bookingfrontend.save')}
                </Button>
            </div>
            <h2>{t('bookingfrontend.delegate_details')}</h2>
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
                name='email'
                control={control}
                render={({ field }) => (
                    <Textfield 
                        {...field}
                        label={t('bookingfrontend.contact_email')}
                        error={errors.email?.message ? t(errors.email.message) : undefined}
                    />
                )}
            />
           <Textfield             
                readOnly
                label={t('bookingfrontend.organization_company')}
                value={delegate.organization.name}
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
        </main>
    )
}

export default DelegateUpdate;