'use client'
import { Textfield, Textarea, Dropdown } from "@digdir/designsystemet-react";
import { Controller } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { ShortActivity } from "@/service/types/api/organization.types";

interface GroupFormBaseProps {
    control: any;
    errors: any;
    orgName: string;
    activities: ShortActivity[];
    currentActivity?: ShortActivity;
}

const GroupFormBase = ({ control, errors, orgName, activities, currentActivity }: GroupFormBaseProps) => {
    const t = useTrans();
    if (!activities) return;
    return (
        <main>
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
                            { value
                                ? activities.find((ac) => ac.id === value)?.name
                                : t(('bookingfrontend.select_activity')) 
                            }
                        </Dropdown.Trigger>
                        <Dropdown>
                            <Dropdown.List>
                                { activities.map((item: ShortActivity) => (
                                    <Dropdown.Item key={item.id} onClick={() => onChange(item.id)}>
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
        </main>
    )

}

export default GroupFormBase;