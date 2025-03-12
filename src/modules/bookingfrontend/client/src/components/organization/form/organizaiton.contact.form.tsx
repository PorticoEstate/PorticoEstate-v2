'use client'
import { Textfield } from "@digdir/designsystemet-react";
import { Controller } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import styles from '../styles/organization.update.module.scss';

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
            <h3>{t(`bookingfrontend.admin ${number + 1}`)}</h3>
            <div className={styles.contact_form_container}>
                <Controller 
                    name={`contac ts[${number}].name`}
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
                            label={t('bookingfrontend.contact_email')}
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
