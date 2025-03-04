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
import GroupFormBase from "./base.form";

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
            <GroupFormBase 
                control={control}
                errors={errors}
                orgName={orgName}
                activity={activity as Activity[]}
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