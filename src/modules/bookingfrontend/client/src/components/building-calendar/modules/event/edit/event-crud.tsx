import React, {Fragment, useMemo, useState} from 'react';
import {
    Badge,
    Button,
    Checkbox,
    Chip,
    Field,
    Label,
    Select, Spinner,
    Tag,
    Textfield,
    ValidationMessage
} from '@digdir/designsystemet-react';
import {DateTime} from 'luxon';
import MobileDialog from '@/components/dialog/mobile-dialog';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {useCurrentBuilding, useTempEvents} from '@/components/building-calendar/calendar-context';
import {useBuilding, useBuildingResources} from '@/service/api/building';
import {FCallTempEvent} from '@/components/building-calendar/building-calendar.types';
import ColourCircle from '@/components/building-calendar/modules/colour-circle/colour-circle';
import styles from './event-crud.module.scss';
import {useForm, Controller} from 'react-hook-form';
import {z} from 'zod';
import {zodResolver} from '@hookform/resolvers/zod';
import {
    useBuildingAgeGroups,
    useBuildingAudience,
    useCreatePartialApplication, useDeletePartialApplication,
    usePartialApplications,
    useUpdatePartialApplication
} from "@/service/hooks/api-hooks";
import {NewPartialApplication, IUpdatePartialApplication, IApplication} from "@/service/types/api/application.types";
import {applicationTimeToLux} from "@/components/layout/header/shopping-cart/shopping-cart-content";

interface EventCrudProps {
    selectedTempEvent?: Partial<FCallTempEvent>;
    building_id: string | number;
    applicationId?: number;
    date_id?: number;
    onClose: () => void;
}

interface EventCrudInnerProps extends EventCrudProps {
    building?: IBuilding;
    buildingResources?: IResource[];
    partials?: { list: IApplication[], total_sum: number };
    audience?: IAudience[];
    agegroups?: IAgeGroup[];
    existingEvent?: IApplication;
}

const eventFormSchema = z.object({
    title: z.string().min(1, 'Title is required'),
    start: z.date(),
    end: z.date(),
    resources: z.array(z.string()).min(1, 'At least one resource must be selected'),
    // Add validation for audience and agegroups
    audience: z.array(z.number()),
    agegroups: z.array(z.object({
        id: z.number(),
        male: z.number().min(0),
        female: z.literal(0), // Since we're only using male counts
        name: z.string(),
        description: z.string().nullable(),
        sort: z.number()
    }))
});


type EventFormData = z.infer<typeof eventFormSchema>;

import {FC} from 'react';
import {IAgeGroup, IAudience, IBuilding} from "@/service/types/Building";

interface EventCrudProps {
}

const EventCrudWrapper: FC<EventCrudProps> = (props) => {

    const {data: building, isLoading: buildingLoading} = useBuilding(+props.building_id);
    const {data: buildingResources, isLoading: buildingResourcesLoading} = useBuildingResources(props.building_id);
    const {data: partials, isLoading: partialsLoading} = usePartialApplications();
    const {data: audience, isLoading: audienceLoading} = useBuildingAudience(+props.building_id);
    const {data: agegroups, isLoading: agegroupsLoading} = useBuildingAgeGroups(+props.building_id);
    const existingEvent = useMemo(() => {
        const applicationId = props.applicationId || props.selectedTempEvent?.extendedProps?.applicationId;
        if (applicationId === undefined) {
            return null;
        }
        if (!partials) {
            return undefined;
        }
        return partials.list.find(a => a.id === applicationId) || null;
    }, [props.selectedTempEvent, partials, props.applicationId]);

    if (buildingLoading || buildingResourcesLoading || partialsLoading || agegroupsLoading || audienceLoading || existingEvent === undefined) {
        return null
    }
    return (
        <EventCrud agegroups={agegroups} building={building} existingEvent={existingEvent || undefined}
                   audience={audience} buildingResources={buildingResources}
                   partials={partials} {...props}></EventCrud>
    );
}


const EventCrud: React.FC<EventCrudInnerProps> = (props) => {
    const {building, buildingResources, audience, agegroups, partials, existingEvent} = props;
    const t = useTrans();
    const [isEditingResources, setIsEditingResources] = useState(false);
    const createMutation = useCreatePartialApplication();
    const deleteMutation = useDeletePartialApplication();
    const updateMutation = useUpdatePartialApplication();


    const defaultStartEnd = useMemo(() => {
        if (!existingEvent?.dates || !props.selectedTempEvent?.id || props.date_id === undefined) {
            return {
                start: props.selectedTempEvent?.start || new Date(),
                end: props.selectedTempEvent?.end || new Date()
            };
        }
        const dateId = props.selectedTempEvent?.id || props.date_id;
        // Find the date entry matching the selectedTempEvent's id
        const dateEntry = existingEvent.dates.find(d => +d.id === +dateId);

        if (!dateEntry) {
            return {
                start: props.selectedTempEvent?.start || new Date(),
                end: props.selectedTempEvent?.end || new Date()
            };
        }

        return {
            start: applicationTimeToLux(dateEntry.from_).toJSDate(),
            end: applicationTimeToLux(dateEntry.to_).toJSDate()
        };
    }, [existingEvent, props.selectedTempEvent, props.date_id]);
    const {
        control,
        handleSubmit,
        watch,
        setValue,
        getValues,
        formState: {errors, isDirty, dirtyFields}
    } = useForm<EventFormData>({
        resolver: zodResolver(eventFormSchema),
        defaultValues: {
            title: existingEvent?.name ?? '',
            start: defaultStartEnd.start,
            end: defaultStartEnd.end,
            resources: existingEvent?.resources?.map((res) => res.id.toString()) ||
                props.selectedTempEvent?.extendedProps?.resources?.map(String) ||
                [],
            audience: existingEvent?.audience || undefined,
            agegroups: agegroups?.map(ag => {
                // const existing = existingEvent?.agegroups.fin
                return ({
                    id: ag.id,
                    male: existingEvent?.agegroups?.find(eag => eag.id === ag.id)?.male || 0,
                    female: 0,
                    name: ag.name,
                    description: ag.description,
                    sort: ag.sort,
                });
            }) || []
        }
    });

    console.log(getValues());
    const selectedResources = watch('resources');

    const formatDateForInput = (date: Date) => {
        return DateTime.fromJSDate(date).toFormat('yyyy-MM-dd\'T\'HH:mm');
    };

    const onSubmit = (data: EventFormData) => {
        if (!building || !buildingResources) {
            return;
        }
        if (existingEvent) {
            const updatedApplication: IUpdatePartialApplication = {
                id: existingEvent.id,
                building_id: +props.building_id,
            }
            if (dirtyFields.start || dirtyFields.end) {
                updatedApplication.dates = existingEvent.dates?.map(date => {
                    const dateId = props.selectedTempEvent?.id || props.date_id;
                    if (date.id && dateId && +dateId === +date.id) {
                        return {
                            ...date,
                            from_: data.start.toISOString(),
                            to_: data.end.toISOString()
                        }
                    }
                    return date
                })
            }
            if (dirtyFields.resources) {
                updatedApplication.resources = buildingResources.filter(res => data.resources.some(selected => (+selected === res.id)))
            }
            if (dirtyFields.title) {
                updatedApplication.name = data.title
            }
            if (dirtyFields.audience) {
                updatedApplication.audience = data.audience;
            }
            if (dirtyFields.agegroups) {
                updatedApplication.agegroups = data.agegroups.map(ag => ({
                    ...ag,
                    female: 0 // Since we're only tracking male numbers
                }));
            }


            updateMutation.mutate({id: existingEvent.id, application: updatedApplication});
            props.onClose();
            return;
        }

        const newApplication: NewPartialApplication = {
            building_name: building!.name,
            building_id: building!.id,
            dates: [
                {
                    from_: data.start.toISOString(),
                    to_: data.end.toISOString()
                }
            ],
            audience: data.audience,
            agegroups: data.agegroups.map(ag => ({
                ...ag,
                female: 0 // Since we're only tracking male numbers
            })),
            name: data.title,
            resources: data.resources.map(res => (+res)),
            activity_id: buildingResources!.find(a => a.id === +data.resources[0] && !!a.activity_id)?.activity_id || 1

        }

        createMutation.mutate(newApplication);
        props.onClose();
    };

    const handleDelete = () => {
        if (existingEvent) {
            // TODO: fix deleting
            deleteMutation.mutate(existingEvent.id);

            // setTempEvents(prev => {
            //     const newEvents = {...prev};
            //     delete newEvents[selectedTempEvent.id!];
            //     return newEvents;
            // });
        }
        props.onClose();
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
                        <h4>{t('bookingfrontend.chosen rent object')}</h4>
                        <Button
                            variant="tertiary"
                            data-size="sm"
                            onClick={() => setIsEditingResources(true)}
                        >
                            {t('common.edit')}
                        </Button>
                    </div>
                    <div style={{
                        display: 'flex',
                        gap: '0.5rem'
                    }}>

                        <div style={{
                            display: 'flex',
                            flexWrap: 'wrap',
                            gap: '0.5rem'
                        }}>
                            {buildingResources
                                .filter(resource => selectedResources.includes(String(resource.id)))
                                .map(resource => (
                                    <Tag
                                        data-color={'neutral'} data-size={"md"} key={resource.id}
                                        className={styles.selectedResourceItem}>
                                        <ColourCircle resourceId={resource.id} size="medium"/>
                                        <span className={styles.resourceName}>{resource.name}</span>
                                    </Tag>
                                ))}

                        </div>
                        {/*<Button*/}
                        {/*    variant="tertiary"*/}
                        {/*    data-size="sm"*/}
                        {/*    onClick={() => setIsEditingResources(true)}*/}
                        {/*    icon={true}*/}
                        {/*>*/}
                        {/*    <FontAwesomeIcon icon={faPen}/>*/}
                        {/*</Button>*/}
                    </div>


                </div>
            );
        }

        // Show all resources with checkboxes when editing
        return (
            <div className={styles.resourceList}>
                <div className={styles.resourcesHeader}>
                    <h4>{t('bookingfrontend.choose resources')}</h4>
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
                <div style={{
                    display: 'flex',
                    gap: '0.5rem'
                }}>

                    <div style={{
                        display: 'flex',
                        flexWrap: 'wrap',
                        gap: '0.5rem'
                    }}>
                        {buildingResources.map(resource => (
                            // <div key={resource.id} className={styles.resourceItem}>
                            <Chip.Checkbox
                                value={String(resource.id)}
                                id={`resource-${resource.id}`}
                                key={resource.id}
                                data-color={'brand1'}
                                data-size={"md"}
                                checked={selectedResources.includes(String(resource.id))}
                                onChange={() => toggleResource(String(resource.id))}
                                className={styles.resourceItem}
                            >
                                <ColourCircle resourceId={resource.id} size="medium"/>
                                <span>{resource.name}</span>
                            </Chip.Checkbox>
                            // </div>
                        ))}
                    </div>
                </div>
            </div>
        );
    };

    return (
        <form onSubmit={handleSubmit(onSubmit)}>
            <MobileDialog
                open={true}
                onClose={props.onClose}
                size={'hd'}
                title={
                    <div className={styles.dialogHeader}>
                        <h3>{existingEvent ? t('bookingfrontend.edit application') : t('bookingfrontend.new application')}</h3>
                    </div>
                }
                footer={<Fragment>
                    {existingEvent && (
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
                        disabled={!(isDirty || !existingEvent)}
                    >
                        {t('common.save')}
                    </Button>
                </Fragment>}
            >
                <section className={styles.eventForm}>
                    <div className={`${styles.formGroup}`}>
                        <Controller
                            name="title"
                            control={control}
                            render={({field}) => (
                                <Textfield
                                    label={t('bookingfrontend.title')}
                                    {...field}
                                    error={errors.title?.message}
                                    placeholder={t('bookingfrontend.enter_title')}
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
                                        {errors.start &&
                                            <span className={styles.error}>{errors.start.message}</span>}
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
                                        {errors.end &&
                                            <span className={styles.error}>{errors.end.message}</span>}
                                    </>
                                )}
                            />
                        </div>
                    </div>

                    <div className={`${styles.formGroup} ${styles.wide}`}>
                        {renderResourceList()}
                        {errors.resources && (
                            <span className={styles.error}>{errors.resources.message}</span>
                        )}
                    </div>
                    <div className={`${styles.formGroup}`}>
                        <div className={styles.resourcesHeader}>
                            <h4>{t('bookingfrontend.target audience')}</h4>
                        </div>
                        <Controller
                            name="audience"
                            control={control}
                            defaultValue={existingEvent?.audience || []}
                            render={({field}) => (
                                <Field>
                                    <Select
                                        required
                                        {...field}
                                        value={field.value?.[0]}
                                        onChange={(event) => field.onChange([(Number(event.target.value))])}
                                        aria-invalid={!!errors.audience}
                                    >
                                        <Select.Option value="" disabled selected={!field.value?.[0]}>
                                            {t('bookingfrontend.choose target audience')}
                                        </Select.Option>
                                        {audience?.map(item => (
                                            <Select.Option key={item.id} value={item.id}>
                                                {item.name}
                                            </Select.Option>
                                        ))}
                                    </Select>
                                    {errors.audience && (
                                        <ValidationMessage>
                                            {errors.audience.message}
                                        </ValidationMessage>
                                    )}
                                </Field>
                            )}
                        />
                    </div>

                    <div className={`${styles.formGroup}`} style={{gridColumn: 1}}>
                        <div className={styles.resourcesHeader}>
                            <h4>{t('bookingfrontend.estimated number of participants')}</h4>
                        </div>

                        {agegroups?.map((agegroup, index) => (
                            <Fragment key={agegroup.id}>
                                {/* Visible male count input */}
                                <Controller
                                    name={`agegroups.${index}.male`}
                                    control={control}
                                    defaultValue={existingEvent?.agegroups?.find(ag => ag.id === agegroup.id)?.male || 0}
                                    render={({field}) => (
                                        <Textfield
                                            type="number"
                                            label={agegroup.name}
                                            {...field}
                                            value={field.value}
                                            min={0}
                                            description={agegroup.description}
                                            onChange={(e) => field.onChange(Number(e.target.value))}
                                            error={errors.agegroups?.[index]?.male?.message}
                                        />
                                    )}
                                />
                                {/* Hidden fields for other required data */}
                                <Controller
                                    name={`agegroups.${index}.id`}
                                    control={control}
                                    defaultValue={agegroup.id}
                                    render={({field}) => <input type="hidden" {...field} />}
                                />
                                <Controller
                                    name={`agegroups.${index}.female`}
                                    control={control}
                                    defaultValue={0}
                                    render={({field}) => <input type="hidden" {...field} />}
                                />
                                <Controller
                                    name={`agegroups.${index}.name`}
                                    control={control}
                                    defaultValue={agegroup.name}
                                    render={({field}) => <input type="hidden" {...field} />}
                                />
                                <Controller
                                    name={`agegroups.${index}.description`}
                                    control={control}
                                    defaultValue={agegroup.description || ''}
                                    render={({field}) => <input type="hidden" {...field} value={field.value || ''} />}
                                />
                                <Controller
                                    name={`agegroups.${index}.sort`}
                                    control={control}
                                    defaultValue={agegroup.sort}
                                    render={({field}) => <input type="hidden" {...field} />}
                                />
                            </Fragment>
                        ))}
                    </div>
                </section>
            </MobileDialog>
        </form>
    );
};

export default EventCrudWrapper;