'use client'
import { zodResolver } from "@hookform/resolvers/zod";
import { Button } from "@digdir/designsystemet-react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { updateGroupFormSchema } from "./schemas";
import { useActivityList } from "@/service/api/activity";
import { GroupleaderForm } from "./form/contact.form";
import GroupFormBase from "./form/base.form";
import { Group } from "@/service/types/api/organization.types";
import { patchGroupRequest } from "@/service/api/organization";
import styles from './styles/group.create.module.scss';
import leaders from './styles/group.update.module.scss';
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faFloppyDisk } from "@fortawesome/free-solid-svg-icons";

interface GroupUpdateFormProps {
    group: Group;
    button: any;
}

const GroupUpdateController = ({ group, button }: GroupUpdateFormProps) => {
    const { data: activities } = useActivityList(group.organization.id);
    const queryClient = useQueryClient();
    const t = useTrans();
    const {
        control,
        handleSubmit,
        formState: { errors },
    } = useForm({
        resolver: zodResolver(updateGroupFormSchema),
        defaultValues: {
            groupData: {
                name: group.name,
                activity_id: group.activity.id,
                shortname: group.shortname,
                description: group.description
            },
            groupLeaders: [
                {
                    id: group.contact[0].id,
                    name: group.contact[0].name,
                    phone: group.contact[0].phone,
                    email: group.contact[0].email
                },
                {
                    id: group.contact[1].id,
                    name: group.contact[1].name,
                    phone: group.contact[1].phone,
                    email: group.contact[1].email
                }
            ]
        }
    });
        
    const update = useMutation({
        mutationFn: (data: any) => patchGroupRequest(group.id, data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['group', group.id] });
        }
    })

    const save = (group: any) => {
        update.mutate(group);
    }
    return (
        <main className={styles.new_group_container}>
            <div className={styles.group_buttons}>
                {button}
                <Button onClick={handleSubmit(save)}>
                    <FontAwesomeIcon icon={faFloppyDisk} />
                    {t('bookingfrontend.save')}
                </Button>
            </div>
            <h3 style={{ marginTop: '1.25rem', marginBottom: '1rem' }}>
                {t('bookingfrontend.group_details')}
            </h3>
            <GroupFormBase
                control={control}
                errors={errors}
                orgName={group.organization.name}
                activities={activities}
                currentActivity={group.activity}
            />
            <div className={leaders.group_leader_container}>
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
            </div>
        </main>
    )
}

export default GroupUpdateController;