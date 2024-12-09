import React, {Fragment, useState} from 'react';
import {Badge, Button, Checkbox, Chip, Tag, Textfield} from '@digdir/designsystemet-react';
import {DateTime} from 'luxon';
import MobileDialog from '@/components/dialog/mobile-dialog';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {useCurrentBuilding, useTempEvents} from '@/components/building-calendar/calendar-context';
import {useBuildingResources} from '@/service/api/building';
import {FCallTempEvent} from '@/components/building-calendar/building-calendar.types';
import ColourCircle from '@/components/building-calendar/modules/colour-circle/colour-circle';
import {FontAwesomeIcon} from '@fortawesome/react-fontawesome';
import {faPen} from '@fortawesome/free-solid-svg-icons';
import styles from './event-crud.module.scss';
import {useForm, Controller} from 'react-hook-form';
import {z} from 'zod';
import {zodResolver} from '@hookform/resolvers/zod';

interface EventCrudProps {
    selectedTempEvent?: Partial<FCallTempEvent>;
    onClose: () => void;
}

const eventFormSchema = z.object({
    title: z.string().min(1, 'Title is required'),
    start: z.date(),
    end: z.date(),
    resources: z.array(z.string()).min(1, 'At least one resource must be selected')
});

type EventFormData = z.infer<typeof eventFormSchema>;

const EventCrud: React.FC<EventCrudProps> = ({selectedTempEvent, onClose}) => {
    const t = useTrans();
    const currentBuilding = useCurrentBuilding();
    const {data: buildingResources} = useBuildingResources(currentBuilding);
    const {tempEvents, setTempEvents} = useTempEvents();
    const [isEditingResources, setIsEditingResources] = useState(false);

    const existingEvent = selectedTempEvent?.id ? tempEvents[selectedTempEvent.id] : undefined;
    const isExistingEvent = !!existingEvent;

    const {
        control,
        handleSubmit,
        watch,
        setValue,
        formState: {errors, isDirty}
    } = useForm<EventFormData>({
        resolver: zodResolver(eventFormSchema),
        defaultValues: {
            title: undefined,
            start: existingEvent?.start || selectedTempEvent?.start || new Date(),
            end: existingEvent?.end || selectedTempEvent?.end || new Date(),
            resources: existingEvent?.extendedProps.resources.map(String) ||
                selectedTempEvent?.extendedProps?.resources?.map(String) ||
                []
        }
    });

    const selectedResources = watch('resources');

    const formatDateForInput = (date: Date) => {
        return DateTime.fromJSDate(date).toFormat('yyyy-MM-dd\'T\'HH:mm');
    };

    const onSubmit = (data: EventFormData) => {
        const eventId = selectedTempEvent?.id || `temp-${Date.now()}`;
        const newEvent: FCallTempEvent = {
            id: eventId,
            title: data.title,
            start: data.start,
            end: data.end,
            allDay: false,
            editable: true,
            extendedProps: {
                type: 'temporary',
                resources: data.resources.map(Number),
            },
        };

        setTempEvents(prev => ({
            ...prev,
            [eventId]: newEvent
        }));
        onClose();
    };

    const handleDelete = () => {
        if (isExistingEvent && selectedTempEvent?.id) {
            setTempEvents(prev => {
                const newEvents = {...prev};
                delete newEvents[selectedTempEvent.id!];
                return newEvents;
            });
        }
        onClose();
    };

    const toggleResource = (resourceId: string) => {
        const currentResources = watch('resources');
        const resourceIndex = currentResources.indexOf(resourceId);

        if (resourceIndex === -1) {
            setValue('resources', [...currentResources, resourceId], {shouldDirty: true});
        } else {
            setValue(
                'resources',
                currentResources.filter(id => id !== resourceId),
                {shouldDirty: true}
            );
        }
    };

    const toggleAllResources = () => {
        if (!buildingResources) return;

        const allResourceIds = buildingResources.map(r => String(r.id));
        if (selectedResources.length === buildingResources.length) {
            setValue('resources', [], {shouldDirty: true});
        } else {
            setValue('resources', allResourceIds, {shouldDirty: true});
        }
    };

    const renderResourceList = () => {
        if (!buildingResources) return null;

        if (!isEditingResources) {
            // Show only selected resources with edit button
            return (
                <div className={styles.selectedResourcesList}>
                    <div className={styles.resourcesHeader}>
                        <h4>{t('bookingfrontend.selected_resources')}</h4>

                    </div>
                    <div style={{
                        display: 'flex',
                        flexWrap: 'wrap',
                        gap: '0.5rem'
                    }}>
                        {buildingResources
                            .filter(resource => selectedResources.includes(String(resource.id)))
                            .map(resource => (
                                <Tag  data-size={"md"}  key={resource.id} className={styles.selectedResourceItem}>
                                    <ColourCircle resourceId={resource.id} size="medium"/>
                                    <span className={styles.resourceName}>{resource.name}</span>
                                </Tag>
                            ))}
                        <Button
                            variant="tertiary"
                            data-size="sm"
                            onClick={() => setIsEditingResources(true)}
                            icon={true}
                        >
                            <FontAwesomeIcon icon={faPen}/>
                        </Button>
                    </div>

                </div>
            );
        }

        // Show all resources with checkboxes when editing
        return (
            <div className={styles.resourceList}>
                <div className={styles.resourcesHeader}>
                    <h4>{t('bookingfrontend.select_resources')}</h4>
                    <Button
                        variant="tertiary"
                        data-size="sm"
                        onClick={() => setIsEditingResources(false)}
                    >
                        {t('common.done')}
                    </Button>
                </div>
                {/*<Checkbox*/}
                {/*    value="select-all"*/}
                {/*    id="resource-all"*/}
                {/*    label={`${t('common.select all')} ${t('bookingfrontend.resources').toLowerCase()}`}*/}
                {/*    checked={buildingResources && selectedResources.length === buildingResources.length}*/}
                {/*    onChange={toggleAllResources}*/}
                {/*    className={styles.resourceCheckbox}*/}
                {/*/>*/}

                {buildingResources.map(resource => (
                    // <div key={resource.id} className={styles.resourceItem}>
                        <Checkbox
                            value={String(resource.id)}
                            id={`resource-${resource.id}`}
                            key={resource.id}
                            label={<div  className={styles.resourceItem}>
                                <ColourCircle resourceId={resource.id} size="medium"/>
                                <span>{resource.name}</span>
                            </div>}
                            checked={selectedResources.includes(String(resource.id))}
                            onChange={() => toggleResource(String(resource.id))}
                            className={styles.resourceCheckbox}
                        />
                    // </div>
                ))}
            </div>
        );
    };

    return (
        <form onSubmit={handleSubmit(onSubmit)} className={styles.eventForm}>
            <MobileDialog
                open={true}
                onClose={onClose}
                size={'hd'}
                title={
                    <div className={styles.dialogHeader}>
                        <h3>{isExistingEvent ? t('bookingfrontend.edit application') : t('bookingfrontend.new application')}</h3>
                    </div>
                }
                footer={<Fragment>
                    {isExistingEvent && (
                        <Button
                            variant="tertiary"
                            color="danger"
                            onClick={handleDelete}
                            type="button"
                        >
                            {t('common.delete')}
                        </Button>
                    )}
                    <Button
                        variant="primary"
                        type="submit"
                        disabled={!isDirty}
                    >
                        {t('common.save')}
                    </Button>
                </Fragment>}
            >
                <div className={styles.formGroup}>
                    <Controller
                        name="title"
                        control={control}
                        render={({field}) => (
                            <Textfield
                                label={t('common.title')}
                                {...field}
                                error={errors.title?.message}
                                placeholder={t('bookingfrontend.enter title')}
                            />
                        )}
                    />
                </div>

                <div className={styles.dateTimeGroup}>
                    <div className={styles.dateTimeInput}>
                        <Controller
                            name="start"
                            control={control}
                            render={({field: {value, onChange, ...field}}) => (
                                <>
                                    <label>{t('common.start')}</label>
                                    <input
                                        type="datetime-local"
                                        {...field}
                                        value={formatDateForInput(value)}
                                        onChange={e => onChange(new Date(e.target.value))}
                                    />
                                    {errors.start && <span className={styles.error}>{errors.start.message}</span>}
                                </>
                            )}
                        />
                    </div>
                    <div className={styles.dateTimeInput}>
                        <Controller
                            name="end"
                            control={control}
                            render={({field: {value, onChange, ...field}}) => (
                                <>
                                    <label>{t('common.end')}</label>
                                    <input
                                        type="datetime-local"
                                        {...field}
                                        value={formatDateForInput(value)}
                                        onChange={e => onChange(new Date(e.target.value))}
                                    />
                                    {errors.end && <span className={styles.error}>{errors.end.message}</span>}
                                </>
                            )}
                        />
                    </div>
                </div>

                <div className={styles.formGroup}>
                    {renderResourceList()}
                    {errors.resources && (
                        <span className={styles.error}>{errors.resources.message}</span>
                    )}
                </div>
            </MobileDialog>
        </form>
    );
};

export default EventCrud;