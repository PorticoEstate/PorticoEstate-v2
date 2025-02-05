'use client'
import { FC } from "react";
import { Field, Label, Input, Textfield } from "@digdir/designsystemet-react";
import { ActivityData } from "@/service/api/event-info";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import styles from '../event.module.scss';
import MaxParticipantInput from "./max-participant-input";
import ResourcesGroup from "./resources-group";
import CalendarDatePicker from "@/components/date-time-picker/calendar-date-picker";
import { Controller } from "react-hook-form";
interface FormProps {
    event: ActivityData;
    control: any;
    errors: any;
}

const EventEditingForm: FC<FormProps> = ({ event, control, errors }: FormProps) => {
    const t = useTrans(); 
    return (
        <div className={styles.editForm}>
            <Controller 
                name="name"
                control={control}
                render={({ field }) => (
                    <Textfield
                        label={t('bookingfrontend.title')}
                        error={errors.name?.message ? t(errors.name.message) : undefined}
                        { ...field }
                    />
                )}
            />
            <div>
                <Controller
                name="from_"
                control={control}
                render={({ field: { onChange, value } }) => (
                    <Field>
                        <span className={styles.inputLabel}>From</span>
                        <CalendarDatePicker 
                            showTimeSelect
                            timeIntervals={5}
                            onDateChange={(date) => onChange(date)}
                            currentDate={value}
                            view="timeGridDay"
                        />
                    </Field>
                )}
            />
            <Controller
                name="to_"
                control={control}
                render={({ field: { onChange, value } }) => (
                    <Field>
                        <span className={styles.inputLabel}>To</span>
                        <CalendarDatePicker 
                            showTimeSelect
                            timeIntervals={5}
                            onDateChange={(date) => onChange(date)}
                            currentDate={value}
                            view="timeGridDay"
                        />
                    </Field>
                )}
            />
            { errors.from_?.message || errors.to_message  
                ? ( 
                    <p className="ds-validation-message">
                        {errors.from_?.message || errors.to_message}
                    </p>
                )
                : null 
            }
            </div>
            
            <Field>
                <span className={styles.inputLabel}>{t('bookingfrontend.place')}</span>
                <Input
                    value={event.building_name}
                    readOnly={true}
                />
            </Field>
            <Controller 
                name="resources"
                control={control}
                render={({ field: { onChange, value }}) => (
                    <Field style={{gap: '0'}}>
                        <span className={styles.inputLabel}>{t('bookingfrontend.resource')}</span>
                        <ResourcesGroup 
                            updateField={onChange}
                            buildingResources={event.buildingResources} 
                            selectedResources={value}
                        />
                    </Field>
                )}
            />
           
            <Controller
                name="organizer"
                control={control}
                render={({ field }) => (
                    <Textfield 
                        { ...field }
                        error={
                            errors.organizer?.message 
                            ? t(errors.organizer.message) 
                            : undefined
                        }
                        label={t('bookingfrontend.organizer')}
                    />
                )}
            />
            <Controller
                name="participant_limit"
                control={control}
                render={({ field: { onChange, value } }) => (
                    <Field>
                        <Label>{t('bookingfrontend.max_participants_info')}</Label>
                        <MaxParticipantInput 
                            error={
                                errors.participant_limit?.message 
                                ? t(errors.participant_limit.message) 
                                : undefined
                            }
                            updateField={onChange} 
                            fieldValue={value}
                        />
                    </Field>
                )}
            />
        </div>
    )
}

export default EventEditingForm;
