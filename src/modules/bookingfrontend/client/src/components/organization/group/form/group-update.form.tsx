'use client'
import { zodResolver } from "@hookform/resolvers/zod";
import { Button } from "@digdir/designsystemet-react";
import { useForm } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { createGroupFormSchema } from "../schemas";
import { useActivityList } from "@/service/api/activity";
import { GroupleaderForm } from "./contact.form";
import GroupFormBase from "./base.form";
import { Group } from "@/service/types/api/organization.types";
import { patchGroup } from "@/service/api/organization";

interface GroupUpdateFormProps {
    group: Group
}

const GroupUpdateController = ({ group }: GroupUpdateFormProps) => {
    const { data: activities } = useActivityList(group.organization.id);
    const t = useTrans();
    const {
        control,
        handleSubmit,
        formState: { errors },
    } = useForm({
        resolver: zodResolver(createGroupFormSchema),
        defaultValues: {
            groupData: {
                name: group.name,
                activity_id: group.activity.id,
                shortname: group.shortname,
                description: group.description
            },
            groupLeaders: [
                {
                    name: group.contact[0].name,
                    phone: group.contact[0].phone,
                    email: group.contact[0].email
                },
                {
                    name: group.contact[1].name,
                    phone: group.contact[1].phone,
                    email: group.contact[1].email
                }
            ]
        }
    });
    const update = patchGroup(group.id);
    
    const save = (group: any) => {
        update.mutate(group);
    }
    return (
        <main>
            <GroupFormBase 
                control={control}
                errors={errors}
                orgName={group.organization.name}
                activities={activities}
                currentActivity={group.activity}
            />
            <GroupleaderForm
                number={0}
                control={control}
                errors={errors}
            />
            <GroupleaderForm
                number={1}
                control={control}
                errors={errors}
            />
            <Button onClick={handleSubmit(save)}>{t('bookingfrontend.save')}</Button>
        </main>
    )
}

export default GroupUpdateController;