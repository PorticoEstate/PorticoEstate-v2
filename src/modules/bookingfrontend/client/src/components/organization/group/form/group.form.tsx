'use client'
import { zodResolver } from "@hookform/resolvers/zod";
import { Button, Textfield } from "@digdir/designsystemet-react";
import { useForm } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { createGroupFormSchema, CreatingGroup } from "../schemas";
import { createGroup } from "@/service/api/organization";
import { useActivityList } from "@/service/api/activity";
import ContactsForm from "./contact.form";
import GroupFormBase from "./base.form";
import { Organization } from "@/service/types/api/organization.types";

interface GroupFormProps {
    data: Organization;
}

const GroupForm = ({ data }: GroupFormProps) => {
    const { data: activities } = useActivityList(data.id);
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
    const create = createGroup(data.id);

    const save = (data: CreatingGroup) => {
        create.mutate(data);
    }

    return (
        <main>
            {/* { headGroup 
                ? <Textfield
                    readOnly
                    value={headGroup.name}
                    label={t('bookingfrontend.head_group')}
                />
                : null
            } */}
            <GroupFormBase 
                control={control}
                errors={errors}
                orgName={data.name}
                activities={activities}
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