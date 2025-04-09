'use client'
import { zodResolver } from "@hookform/resolvers/zod";
import { Button } from "@digdir/designsystemet-react";
import { useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { CreateOrConnectLeader, updateGroupFormSchema } from "./schemas";
import { useActivityList } from "@/service/api/activity";
import ContactsForm from "./form/contact.form";
import GroupFormBase from "./form/base.form";
import { Contact, Group } from "@/service/types/api/organization.types";
import { patchGroup } from "@/service/api/organization";
import styles from './styles/group.create.module.scss';
import { FloppydiskIcon } from '@navikt/aksel-icons';

interface GroupUpdateFormProps {
    group: Group;
    button: any;
}

const GroupUpdateController = ({ group, button }: GroupUpdateFormProps) => {
    const { data: activities } = useActivityList(group.organization.id);
    const t = useTrans();
    const {
        control,
        handleSubmit,
        reset,
        formState: { errors },
    } = useForm({
        resolver: zodResolver(updateGroupFormSchema),
        mode: 'onChange',
        defaultValues: {
            groupData: {
                name: group.name,
                activity_id: group.activity.id,
                shortname: group.shortname,
                description: group.description
            },
            groupLeaders: group.contact
        }
    });

    type UpdatedLeader = Contact | CreateOrConnectLeader;
    const findGroupLeaderDiff = (defaultLeaders: Contact[], newLeaders: UpdatedLeader[]) => {
        const added = newLeaders.filter((newLeader) => !('id' in newLeader));
    
        const removed = defaultLeaders.filter(
            (defaultLeader) => 
                !newLeaders.some((newLeader) => 'id' in newLeader && newLeader.id === defaultLeader.id)
        );
    
        const updated = newLeaders.filter((newLeader) =>
            defaultLeaders.some(
                (defaultLeader) =>
                    'id' in newLeader && defaultLeader.id === newLeader.id &&
                    JSON.stringify(defaultLeader) !== JSON.stringify(newLeader)
            )
        );
    
        return { added, removed, updated };
    };

    const update = patchGroup(group.organization.id, group.id, useQueryClient());
    const save = (newGroup: any) => {
        const { added, removed } = findGroupLeaderDiff(group.contact, newGroup.groupLeaders);
        update.mutate({ added, removed, groupData: newGroup.groupData });
    }
    
    return (
        <main className={styles.new_group_container}>
            <div className={styles.group_buttons}>
                {button}
                <Button variant='secondary' onClick={() => reset()}>
                    {t('bookingfrontend.reset')}
                </Button>
                <Button onClick={handleSubmit(save)}>
                    <FloppydiskIcon />
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
            <ContactsForm 
                control={control}
                errors={errors}
            />
        </main>
    )
}

export default GroupUpdateController;