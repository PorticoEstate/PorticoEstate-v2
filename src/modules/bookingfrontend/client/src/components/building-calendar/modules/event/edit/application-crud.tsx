import React, {Fragment, useMemo, useState, FC} from 'react';
import {
    Button,
    Chip, Details,
    Field,
    Select, Spinner,
    Tag,
    Textfield,
    ValidationMessage
} from '@digdir/designsystemet-react';
import {DateTime} from 'luxon';
import MobileDialog from '@/components/dialog/mobile-dialog';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {useBuilding, useBuildingResources} from '@/service/api/building';
import {FCallTempEvent} from '@/components/building-calendar/building-calendar.types';
import ColourCircle from '@/components/building-calendar/modules/colour-circle/colour-circle';
import styles from './application-crud.module.scss';
import {useForm, Controller} from 'react-hook-form';
import {zodResolver} from '@hookform/resolvers/zod';
import {
    useBuildingAgeGroups,
    useBuildingAudience,
    useCreatePartialApplication, useDeleteApplicationDocument, useDeletePartialApplication,
    usePartialApplications,
    useUpdatePartialApplication, useUploadApplicationDocument
} from "@/service/hooks/api-hooks";
import {NewPartialApplication, IUpdatePartialApplication, IApplication} from "@/service/types/api/application.types";
import {applicationTimeToLux} from "@/components/layout/header/shopping-cart/shopping-cart-content";
import {IAgeGroup, IAudience, IBuilding} from "@/service/types/Building";
import CalendarDatePicker from "@/components/date-time-picker/calendar-date-picker";
import {ApplicationFormData, applicationFormSchema} from './application-form';

interface ApplicationCrudProps {
    selectedTempApplication?: Partial<FCallTempEvent>;
    building_id: string | number;
    applicationId?: number;
    date_id?: number;
    onClose: () => void;
}

interface ApplicationCrudInnerProps extends ApplicationCrudProps {
    building?: IBuilding;
    buildingResources?: IResource[];
    partials?: { list: IApplication[], total_sum: number };
    audience?: IAudience[];
    agegroups?: IAgeGroup[];
    existingApplication?: IApplication;
}




const ApplicationCrudWrapper: FC<ApplicationCrudProps> = (props) => {


    const {data: building, isLoading: buildingLoading} = useBuilding(+props.building_id);
    const {data: buildingResources, isLoading: buildingResourcesLoading} = useBuildingResources(props.building_id);
    const {data: partials, isLoading: partialsLoading} = usePartialApplications();
    const {data: audience, isLoading: audienceLoading} = useBuildingAudience(+props.building_id);
    const {data: agegroups, isLoading: agegroupsLoading} = useBuildingAgeGroups(+props.building_id);
    const t = useTrans();


    const existingApplication = useMemo(() => {
        const applicationId = props.applicationId || props.selectedTempApplication?.extendedProps?.applicationId;
        if (applicationId === undefined) {
            return null;
        }
        if (!partials) {
            return undefined;
        }
        return partials.list.find(a => a.id === applicationId) || null;
    }, [props.selectedTempApplication, partials, props.applicationId]);

    if (buildingLoading || buildingResourcesLoading || partialsLoading || agegroupsLoading || audienceLoading || existingApplication === undefined) {
        return null
    }
    return (
        <ApplicationCrud agegroups={agegroups} building={building} existingApplication={existingApplication || undefined}
                   audience={audience} buildingResources={buildingResources}
                   partials={partials} {...props}></ApplicationCrud>
    );
}


const ApplicationCrud: React.FC<ApplicationCrudInnerProps> = (props) => {
    const [filesToUpload, setFilesToUpload] = useState<FileList | null>(null);
    const [isUploadingFiles, setIsUploadingFiles] = useState(false);
    const {building, buildingResources, audience, agegroups, partials, existingApplication} = props;
    const t = useTrans();
    const [isEditingResources, setIsEditingResources] = useState(false);
    const createMutation = useCreatePartialApplication();
    const deleteMutation = useDeletePartialApplication();
    const updateMutation = useUpdatePartialApplication();
    const uploadDocumentMutation = useUploadApplicationDocument();
    const deleteDocumentMutation = useDeleteApplicationDocument();


    const defaultStartEnd = useMemo(() => {
        if (!existingApplication?.dates || !props.selectedTempApplication?.id || props.date_id === undefined) {
            return {
                start: props.selectedTempApplication?.start || new Date(),
                end: props.selectedTempApplication?.end || new Date()
            };
        }
        const dateId = props.selectedTempApplication?.id || props.date_id;
        // Find the date entry matching the selectedTempEvent's id
        const dateEntry = existingApplication.dates.find(d => +d.id === +dateId);

        if (!dateEntry) {
            return {
                start: props.selectedTempApplication?.start || new Date(),
                end: props.selectedTempApplication?.end || new Date()
            };
        }

        return {
            start: applicationTimeToLux(dateEntry.from_).toJSDate(),
            end: applicationTimeToLux(dateEntry.to_).toJSDate()
        };
    }, [existingApplication, props.selectedTempApplication, props.date_id]);
    const {
        control,
        handleSubmit,
        watch,
        setValue,
        getValues,
        formState: {errors, isDirty, dirtyFields}
    } = useForm<ApplicationFormData>({
        resolver: zodResolver(applicationFormSchema),
        defaultValues: {
            title: existingApplication?.name ?? '',
            start: defaultStartEnd.start,
            end: defaultStartEnd.end,
            homepage: existingApplication?.homepage || '',
            description: existingApplication?.description || '',
            equipment: existingApplication?.equipment || '',
            resources: existingApplication?.resources?.map((res) => res.id.toString()) ||
                props.selectedTempApplication?.extendedProps?.resources?.map(String) ||
                [],
            audience: existingApplication?.audience || undefined,
            agegroups: agegroups?.map(ag => {
                // const existing = existingEvent?.agegroups.fin
                return ({
                    id: ag.id,
                    male: existingApplication?.agegroups?.find(eag => eag.id === ag.id)?.male || 0,
                    female: 0,
                    name: ag.name,
                    description: ag.description,
                    sort: ag.sort,
                });
            }) || []
        }
    });

    const selectedResources = watch('resources');

    const formatDateForInput = (date: Date) => {
        return DateTime.fromJSDate(date).toFormat('yyyy-MM-dd\'T\'HH:mm');
    };

    const onSubmit = async (data: ApplicationFormData) => {
        if (!building || !buildingResources) {
            return;
        }
        if (existingApplication) {
            const updatedApplication: IUpdatePartialApplication = {
                id: existingApplication.id,
                building_id: +props.building_id,
            }
            if (dirtyFields.start || dirtyFields.end) {
                updatedApplication.dates = existingApplication.dates?.map(date => {
                    const dateId = props.selectedTempApplication?.id || props.date_id;
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
            if (dirtyFields.agegroups) {
                updatedApplication.agegroups = data.agegroups.map(ag => ({
                    ...ag,
                    female: 0 // Since we're only tracking male numbers
                }));
            }
            const checkFields: (keyof typeof dirtyFields)[] = [
                'title',
                'audience',
                'homepage',
                'description',
                'equipment',
            ]
            for (const checkField of checkFields) {
                if (dirtyFields[checkField]) {
                    (updatedApplication as any)[checkField] = data[checkField];
                }
            }


            const result = await updateMutation.mutateAsync({id: existingApplication.id, application: updatedApplication});

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

        const result = await createMutation.mutateAsync(newApplication);
        if (filesToUpload && filesToUpload.length > 0) {
            setIsUploadingFiles(true);
            const formData = new FormData();
            Array.from(filesToUpload).forEach(file => {
                formData.append('files[]', file);
            });

            await uploadDocumentMutation.mutateAsync({
                id: result.id,
                files: formData
            });
            setIsUploadingFiles(false);
        }
        props.onClose();
    };

    const handleDelete = () => {
        if (existingApplication) {
            // TODO: fix deleting
            deleteMutation.mutate(existingApplication.id);

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
                        <h3>{existingApplication ? t('bookingfrontend.edit application') : t('bookingfrontend.new application')}</h3>
                    </div>
                }
                footer={<Fragment>
                    {existingApplication && (
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
                        disabled={!(isDirty || !existingApplication)}
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
                                    error={errors.title?.message ? t(errors.title.message): undefined}
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
                                        {/*<input*/}
                                        {/*    type="datetime-local"*/}
                                        {/*    {...field}*/}
                                        {/*    value={formatDateForInput(value)}*/}
                                        {/*    onChange={e => onChange(new Date(e.target.value))}*/}
                                        {/*/>*/}
                                        <CalendarDatePicker currentDate={value} view={'timeGridDay'} showTimeSelect onDateChange={onChange} />


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
                        {errors.resources?.message && (
                            <span className={styles.error}>{t(errors.resources.message)}</span>
                        )}
                    </div>
                    <div className={`${styles.formGroup}`}>
                        <div className={styles.resourcesHeader}>
                            <h4>{t('bookingfrontend.target audience')}</h4>
                        </div>
                        <Controller
                            name="audience"
                            control={control}
                            defaultValue={existingApplication?.audience || []}
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
                        <div className={styles.resourcesHeader} style={{flexDirection: 'column', alignItems: 'flex-start'}}>
                            <h4>{t('bookingfrontend.estimated number of participants')}</h4>
                            {errors.agegroups?.['root']?.message && (
                                <span className={styles.error}>{t(errors.agegroups?.['root']?.message)}</span>
                            )}
                        </div>

                        {agegroups?.map((agegroup, index) => (
                            <Fragment key={agegroup.id}>
                                {/* Visible male count input */}
                                <Controller
                                    name={`agegroups.${index}.male`}
                                    control={control}
                                    defaultValue={existingApplication?.agegroups?.find(ag => ag.id === agegroup.id)?.male || 0}
                                    render={({field}) => (
                                        <Textfield
                                            type="number"
                                            label={agegroup.name}
                                            {...field}
                                            value={field.value}
                                            min={0}
                                            description={agegroup.description}
                                            onChange={(e) => field.onChange(Number(e.target.value))}
                                            error={errors.agegroups?.[0]?.message ? t(errors.agegroups?.[0]?.message) : undefined}
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
                                    render={({field}) => <input type="hidden" {...field} value={field.value || ''}/>}
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

                    <div className={`${styles.formGroup} ${styles.wide}`}>
                        <Details data-color={'neutral'}>
                            <Details.Summary>
                                Tilleggsinformasjon (valgfritt) TODO: Translation!
                            </Details.Summary>
                            <Details.Content style={{backgroundColor: "inherit"}} className={styles.eventForm}>
                                <div className={`${styles.formGroup}`} style={{gridColumn: 1}}>
                                    <Controller
                                        name="homepage"
                                        control={control}
                                        render={({field}) => (
                                            <Textfield
                                                label={t('bookingfrontend.homepage')}
                                                {...field}
                                                error={errors.homepage?.message}
                                                placeholder={t('bookingfrontend.event/activity homepage')}
                                            />
                                        )}
                                    />
                                </div>
                                <div className={`${styles.formGroup}`} style={{gridColumn: 1}}>
                                    <Controller
                                        name="description"
                                        control={control}
                                        render={({field}) => (
                                            <Textfield
                                                label={t('bookingfrontend.description')}
                                                {...field}
                                                multiline={true}
                                                rows={3}
                                                error={errors.description?.message}
                                                placeholder={t('bookingfrontend.event/activity description')}
                                            />
                                        )}
                                    />
                                </div>
                                <div className={`${styles.formGroup}`} style={{gridColumn: 1}}>
                                    <Controller
                                        name="equipment"
                                        control={control}
                                        render={({field}) => (
                                            <Textfield
                                                label={t('bookingfrontend.equipment text')}
                                                {...field}
                                                multiline={true}
                                                rows={3}
                                                error={errors.equipment?.message}
                                                placeholder={t('bookingfrontend.equipment text')}
                                            />
                                        )}
                                    />
                                </div>
                                <div className={`${styles.formGroup}`} style={{gridColumn: 1}}>
                                    <div className={styles.resourcesHeader}>
                                        <h4>{t('bookingfrontend.documents')}</h4>
                                    </div>

                                    {existingApplication ? (
                                        // Show existing documents and direct upload for existing applications
                                        <>
                                            {existingApplication.documents?.length > 0 && (
                                                <div className={styles.documentsList}>
                                                    {existingApplication.documents.map(doc => (
                                                        <div key={doc.id} className={styles.documentItem}>
                                                            <span>{doc.name}</span>
                                                            <Button
                                                                variant="tertiary"
                                                                data-color={'danger'}
                                                                // color="danger"
                                                                data-size={'sm'}
                                                                onClick={() => deleteDocumentMutation.mutate(doc.id)}
                                                                loading={deleteDocumentMutation.isPending}
                                                            >
                                                                {t('common.delete')}
                                                            </Button>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}

                                            <input
                                                type="file"
                                                multiple
                                                value={""}
                                                onChange={(e) => {
                                                    if (e.target.files && existingApplication.id) {
                                                        const formData = new FormData();
                                                        Array.from(e.target.files).forEach(file => {
                                                            formData.append('files[]', file);
                                                        });

                                                        uploadDocumentMutation.mutate({
                                                            id: existingApplication.id,
                                                            files: formData
                                                        });
                                                    }
                                                }}
                                            />
                                            {uploadDocumentMutation.isPending && (
                                                <Spinner aria-label={t('common.uploading')}/>
                                            )}
                                        </>
                                    ) : (
                                        // For new applications, just store the files to be uploaded after creation
                                        <>
                                            <input
                                                type="file"
                                                multiple
                                                onChange={(e) => setFilesToUpload(e.target.files)}
                                            />
                                            {filesToUpload && (
                                                <div className={styles.selectedFiles}>
                                                    {Array.from(filesToUpload).map((file, index) => (
                                                        <div key={index} className={styles.selectedFileItem}>
                                                            {file.name}
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                            {isUploadingFiles && (
                                                <Spinner aria-label={t('common.uploading')}/>
                                            )}
                                        </>
                                    )}
                                </div>

                            </Details.Content>
                        </Details>
                    </div>


                </section>
            </MobileDialog>
        </form>
    );
};

export default ApplicationCrudWrapper;