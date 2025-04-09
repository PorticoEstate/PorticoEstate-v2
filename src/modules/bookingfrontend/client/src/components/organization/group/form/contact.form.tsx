'use client'
import { Button, Textfield } from "@digdir/designsystemet-react";
import { Controller, useFieldArray } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { PlusIcon, MinusIcon } from '@navikt/aksel-icons';
import leaders from '../styles/group.update.module.scss';

interface ContactForm {
    control: any;
    errors: any;
}
interface GroupleaderForm {
    number: number;
    control: any;
    errors: any;
    key: any
    remove: () => void;
}

export const GroupleaderForm = ({ number, control, errors, remove }: GroupleaderForm) => {
    const t = useTrans();

    let errObj = errors.groupLeaders;
    if (errObj) errObj = errObj[number] ? errObj[number] : {}
    else errObj = {}

    return (
        <div key={`groupleader-${number}`}>
        <div className={leaders.groupleader_header}>
            <h3>{t('bookingfrontend.group_leader')} {number + 1}</h3>
            <Button variant='tertiary' onClick={remove}>
                <MinusIcon />
                {t('bookingfrontend.remove_groupleader')}
            </Button>
        </div>
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

const ContactsForm = ({ control, errors }: ContactForm) => {
    const { fields, remove, insert } = useFieldArray({
        name: `groupLeaders`,
        control,
    });
    const t = useTrans();
    
    const onPlus = () => {
        if (fields.length >= 2) return;
        insert(fields.length, { name: '', phone: '', email: '' });
    }

    return (
        <div className={leaders.group_leader_container}>
            { errors.groupLeaders?.message }
            { fields.map((field, i) => {
                return <GroupleaderForm
                    remove={() => remove(i)}
                    key={field.id}
                    errors={errors}
                    control={control} 
                    number={i}
                    />
            }
            )}
            {
                fields.length < 2 && (
                    <Button variant='tertiary' onClick={onPlus}>
                        <PlusIcon />
                        {t('bookingfrontend.add_groupleader')}
                    </Button>
                )
            }
        </div>
    );
}

export default ContactsForm;