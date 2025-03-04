'use client'
import { useState } from 'react';
import { Button, Textfield } from "@digdir/designsystemet-react";
import { Controller } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";

interface ContactForm {
    control: any;
    errors: any;
}
interface GroupleaderForm {
    number: 0 | 1;
    control: any;
    errors: any;
}

export const GroupleaderForm = ({ number, control, errors }: GroupleaderForm) => {
    const t = useTrans();

    let errObj = errors.groupLeaders;
    if (errObj) errObj = errors.groupLeaders[number];
    else errObj = {}

    return (
        <div>
        <h3>{t('bookingfrontend.groupleader')}</h3>
        <div>
            <Controller 
                name={`groupLeaders[${number}].name`}
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
                name={`groupLeaders[${number}].phone`}
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
                name={`groupLeaders[${number}].email`}
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

const ContactsForm = ({ control, errors }: ContactForm) => {
    const [leadersCount, setCount] = useState(0);
    const t = useTrans();
    return (
        <>
            <GroupleaderForm 
                errors={errors}
                control={control}
                number={0}
            />
            { 
                 leadersCount !== 1
                 ? <Button onClick={() => setCount(1)}>
                       {t('bookingfronted.add_second_groupleader')}
                   </Button>
                 : null  
            }
            { 
                leadersCount === 1
                ? <GroupleaderForm errors={errors} control={control} number={1}/> 
                : null
            }
            {
              
              leadersCount === 1
              ? <Button onClick={() => setCount(0)}>
                    {t('bookingfronted.remove_second_groupleader')}
                </Button>
              : null
            }
        </>
    );
}

export default ContactsForm;