'use client'
import { useState } from 'react';
import { Button, Textfield } from "@digdir/designsystemet-react";
import { Controller } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faPlus, faMinus } from "@fortawesome/free-solid-svg-icons";

interface ContactForm {
    control: any;
    errors: any;
    resetField: any;
}
interface GroupleaderForm {
    number: 0 | 1;
    control: any;
    errors: any;
}

export const GroupleaderForm = ({ number, control, errors }: GroupleaderForm) => {
    const t = useTrans();

    let errObj = errors.groupLeaders;
    if (errObj) errObj = errObj[number] ? errObj[number] : {}
    else errObj = {}

    return (
        <div>
        <h3>{t('bookingfrontend.group_leader')} {number + 1}</h3>
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

const ContactsForm = ({ control, errors, resetField }: ContactForm) => {
    const [leadersCount, setCount] = useState(0);
    const t = useTrans();

    const onMinus = () => {
        resetField('groupLeaders[1]');
        setCount(0);
    }

    return (
        <>
            <GroupleaderForm 
                errors={errors}
                control={control}
                number={0}
            />
            { 
                 leadersCount !== 1
                 ? <Button variant='tertiary' onClick={() => setCount(1)}>
                        <FontAwesomeIcon icon={faPlus} />
                       {t('bookingfrontend.add_groupleader')}
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
              ? <Button variant='tertiary' onClick={onMinus}>
                    <FontAwesomeIcon icon={faMinus} />
                    {t('bookingfrontend.remove_groupleader')}
                </Button>
              : null
            }
        </>
    );
}

export default ContactsForm;