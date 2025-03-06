'use client'
import { Textfield } from "@digdir/designsystemet-react";
import { Controller } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";

interface GroupleaderForm {
    number: 0 | 1;
    control: any;
    errors: any;
}

export const OrganizationContactForm = ({ number, control, errors }: GroupleaderForm) => {
    const t = useTrans();

    let errObj = errors.groupLeaders;
    if (errObj) errObj = errors.groupLeaders[number];
    else errObj = {}

    return (
        <div>
        <h3>{t('bookingfrontend.organization_leader')} {number + 1}</h3>
        <div>
            <Controller 
                name={`contacts[${number}].name`}
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.name')}
                        error={
                            errObj.name?.message 
                            ? t(errObj.name.message) 
                            : null 
                        }
                    />                        
                )}
            />
            <Controller 
                name={`contacts[${number}].phone`}
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.phone')}
                        error={
                            errObj.phone?.message 
                            ? t(errObj.phone.message) 
                            : null 
                        }
                    />                        
                )}
            />
            <Controller 
                name={`contacts[${number}].email`}
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.email')}
                        error={
                            errObj.email?.message 
                            ? t(errObj.email.message) 
                            : null 
                        }
                    />                        
                )}
            />
        </div>
    </div>
    )
}
