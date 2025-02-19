'use client'
import { zodResolver } from "@hookform/resolvers/zod";
import { Button, Textfield, Textarea, Dropdown } from "@digdir/designsystemet-react";
import { Controller, useForm } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { createGroupFormSchema, CreatingGroup } from "../schemas";
import { createGroup } from "@/service/api/organization";
import { useActivityList } from "@/service/api/activity";
import { Activity } from "@/service/types/api/activity.types";
import ContactsForm from "./contact.form";

interface GroupFormProps {
    orgId: number;
    orgName: string;
    headGroup?: { id: number; name: string }
}

const GroupForm = ({ orgId, orgName, headGroup }: GroupFormProps) => {
    const { data: activity } = useActivityList();
    const t = useTrans();
    const {
        control,
        handleSubmit,
        formState: { errors },
    } = useForm({
        resolver: zodResolver(createGroupFormSchema),
        defaultValues: {
            groupData: {
                name: '',
                shortname: '',
                activity_id: '',
                description: ''
            },
            groupLeaders: []
        }
    });
    const create = createGroup(orgId);

    const save = (data: CreatingGroup) => {
        create.mutate(data);
    }

    return (
        <main>
            { headGroup 
                ? <Textfield
                    readOnly
                    value={headGroup.name}
                    label={t('bookingfrontend.head_group')}
                />
                : null
            }
            <Controller
                name="groupData.name"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.name')}
                        error={
                            errors.groupData?.name?.message 
                            ? t(errors.groupData?.name.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="groupData.shortname"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.shortname')}
                        error={
                            errors.groupData?.shortname?.message 
                            ? t(errors.groupData?.shortname.message)
                            : undefined
                        }
                    />
                )}
            />
            <Textfield
                readOnly
                value={orgName}
                label={t('bookingfrontend.organization_name')}
            />
            <Controller
                name='groupData.activity_id'
                control={control}
                render={({ field: { onChange, value } }) => (
                    <Dropdown.TriggerContext>
                        <Dropdown.Trigger>
                            { value ? value.id : t(('bookingfrontend.select_activity')) }
                        </Dropdown.Trigger>
                        <Dropdown>
                            <Dropdown.List>
                                { activity?.map((item: Activity) => (
                                    <Dropdown.Item onClick={onChange(item)}>
                                        <Dropdown.Button>
                                            {item.name}
                                        </Dropdown.Button>
                                    </Dropdown.Item>
                                )) }
                            </Dropdown.List>
                        </Dropdown>
                    </Dropdown.TriggerContext>
                )}
            />
            <Controller
                name="groupData.description"
                control={control}
                render={({ field }) => (
                    <Textarea
                        {...field}
                        label={t('bookingfrontend.name')}
                        error={
                            errors.groupData?.name?.message 
                            ? t(errors.groupData?.name.message) 
                            : undefined
                        }
                    />
                )}
            />
            <ContactsForm
                control={control}
                errors={errors}
            />    
            <div>
                <Button onClick={handleSubmit(save)}>{t('bookingfrontend.save')}</Button>
                <Button>{t('bookingfrontend.cancel')}</Button>
            </div>
        </main>
    )

}

export default GroupForm;