'use client'
import { zodResolver } from "@hookform/resolvers/zod";
import { Button } from "@digdir/designsystemet-react";
import { useForm } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { createGroupFormSchema, CreatingGroup } from "./schemas";
import { createGroup } from "@/service/api/organization";
import { useActivityList } from "@/service/api/activity";
import ContactsForm from "./form/contact.form";
import GroupFormBase from "./form/base.form";
import { Organization } from "@/service/types/api/organization.types";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faFloppyDisk } from "@fortawesome/free-solid-svg-icons";

import styles from './styles/group.create.module.scss'

interface GroupFormProps {
    data: Organization;
}

const GroupCreate = ({ data }: GroupFormProps) => {
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
        <main className={styles.new_group_container}>
            <div className={styles.group_buttons}>
                <Button variant='secondary'>{t('bookingfrontend.cancel')}</Button>
                <Button onClick={handleSubmit(save)}>
                    <FontAwesomeIcon icon={faFloppyDisk} />
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
                orgName={data.name}
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