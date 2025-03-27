'use client'
import { useState } from 'react';
import { Textfield, Textarea, Dropdown, Label, Button } from "@digdir/designsystemet-react";
import { Controller } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { ShortActivity } from "@/service/types/api/organization.types";
import { faCaretUp, faCaretDown } from "@fortawesome/free-solid-svg-icons";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import styles from '../styles/group-base-form.module.scss';

interface GroupFormBaseProps {
    control: any;
    errors: any;
    orgName: string;
    activities: ShortActivity[];
    currentActivity?: ShortActivity;
}

const GroupFormBase = ({ control, errors, orgName, activities, currentActivity }: GroupFormBaseProps) => {
    const t = useTrans();
    const [activityList, setOpen] = useState(false);
    return (
        <>
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
                        label={t('bookingfrontend.organization_shortname')}
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
                    <div>
                        <Label>{t('bookingfrontend.activity')}</Label>
                        <Button 
                            popovertarget='activity_list' 
                            variant="secondary"
                            onClick={() => setOpen(!activityList)}
                        >
                            { value
                                ? activities?.find((ac) => ac.id === value)?.name
                                : t(('bookingfrontend.please select an activity')) 
                            }
                            {
                                activityList
                                ? <FontAwesomeIcon icon={faCaretUp} />
                                : <FontAwesomeIcon icon={faCaretDown} />
                            }
                        </Button>
                        <Dropdown 
                            open={activityList} 
                            onClose={() => setOpen(false)} 
                            id="activity_list"
                        >
                            <Dropdown.List>
                                { activities?.map((item: ShortActivity) => (
                                    <Dropdown.Item key={item.id} onClick={() => onChange(item.id)}>
                                        <Dropdown.Button>
                                            {item.name}
                                        </Dropdown.Button>
                                    </Dropdown.Item>
                                )) }
                            </Dropdown.List>
                        </Dropdown>
                    </div>
                )}
            />
            <Controller
                name="groupData.description"
                control={control}
                render={({ field }) => (
                    <div>
                        <Label>{t('bookingfrontend.description')}</Label>
                        <Textarea
                            {...field}
                            error={
                                errors.groupData?.name?.message 
                                ? t(errors.groupData?.name.message) 
                                : undefined
                            }
                        />
                    </div>
                )}
            />
        </>
    )

}

export default GroupFormBase;