'use client'
import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import { Button } from "@digdir/designsystemet-react";
import { useForm } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { createGroupFormSchema, CreatingGroup } from "./schemas";
import { createGroup } from "@/service/api/organization";
import { useActivityList } from "@/service/api/activity";
import ContactsForm from "./form/contact.form";
import GroupFormBase from "./form/base.form";
import { FloppydiskIcon } from '@navikt/aksel-icons';
import { useRouter } from "next/navigation";

import styles from './styles/group.create.module.scss'

interface GroupFormProps {
    orgId: number;
    orgName: string;
}

const GroupCreate = ({ orgId, orgName }: GroupFormProps) => {
    const router = useRouter();
    const { data: activities } = useActivityList(orgId);
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
    const create = createGroup(orgId, useQueryClient());

    const save = (data: CreatingGroup) => {
        create.mutate(data);
        router.back();
    }

    return (
        <main className={styles.new_group_container}>
            <div className={styles.group_buttons}>
                <Button variant='secondary' onClick={() => router.back()}>
                    {t('bookingfrontend.cancel')}
                </Button>
                <Button onClick={handleSubmit(save)}>
                    <FloppydiskIcon />
                    {t('bookingfrontend.save')}
                </Button>
            </div>
            {/* { headGroup 
                ? <Textfield
                    readOnly
                    value={headGroup.name}
                    label={t('bookingfrontend.head_group')}
                />
                : null
            } */}
            <h2>{t('bookingfrontend.new_group')}</h2>
            <GroupFormBase 
                control={control}
                errors={errors}
                orgName={orgName}
                activities={activities}
            />
            <ContactsForm
                control={control}
                errors={errors}
            />
        </main>
    )

}

export default GroupCreate;