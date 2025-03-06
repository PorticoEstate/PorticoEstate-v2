'use client';
import { Controller, useForm } from "react-hook-form";
import { Button, Textfield } from "@digdir/designsystemet-react";
import { zodResolver } from "@hookform/resolvers/zod";
import { UpdatingDelegate, updateDelegateFormSchema } from "./schemas";
import { patchDelegate } from "@/service/api/organization";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { ViewDelegate } from "@/service/types/api/organization.types";

interface DelegateUpdateProps {
    data: ViewDelegate;
}

const DelegateUpdate = ({ data }: DelegateUpdateProps) => {
    const t = useTrans();
    const {
        control,
        handleSubmit,
        formState: { errors },
    } = useForm({
        mode: 'onChange',
        resolver: zodResolver(updateDelegateFormSchema),
        defaultValues: {
            name: data.name,
            email: data.email,
            phone: data.phone
        }
    });
    const update = patchDelegate(data.id);

    const updateCb = (data: UpdatingDelegate) => {
        update.mutate(data);
    }
    
    return (
        <>
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
             <Button
                onClick={handleSubmit(updateCb)}
            >
                {t('bookingfrontend.update_delegate')}
            </Button>
        </>
    )
}

export default DelegateUpdate;