'use client';
import { Controller } from "react-hook-form";
import { useState } from 'react';
import { Textfield, Dropdown, Switch, Button, Label } from "@digdir/designsystemet-react";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { useActivityList } from "@/service/api/activity";
import { Organization, ShortActivity } from "@/service/types/api/organization.types";
import { OrganizationContactForm } from "./organizaiton.contact.form";
import { ChevronDownIcon, ChevronUpIcon } from '@navikt/aksel-icons';
import styles from '../styles/organization.update.module.scss';

interface UpdateOrganizationProps {
    organization: Organization;
    errors: any;
    control: any;
}

const UpdateOrganizationForm = ({ organization, errors, control }: UpdateOrganizationProps) => {
    const t = useTrans();
    const [activityList, setOpen] = useState(false);
    const { data: activities } = useActivityList(organization.id);
    return (
        <main className={styles.form_container}>
            <h2>{t('bookingfrontend.organization_details')}</h2>
            <Controller
                name="organization.organization_number"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.organization number')}
                        error={
                            errors.organization?.organization_number?.message 
                            ? t(errors.organization.organization_number.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="organization.name"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.organization_name')}
                        error={
                            errors.organization?.name?.message 
                            ? t(errors.organization.name.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="organization.shortname"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.organization_shortname')}
                        error={
                            errors.organization?.shortname?.message 
                            ? t(errors.organization.shortname.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="organization.street"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.street')}
                        error={
                            errors.organization?.street?.message 
                            ? t(errors.organization.street.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="organization.zip_code"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.zip code')}
                        error={
                            errors.organization?.zip_code?.message 
                            ? t(errors.organization.zip_code.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="organization.district"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.district')}
                        error={
                            errors.organization?.district?.message 
                            ? t(errors.organization.district.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="organization.city"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.city')}
                        error={
                            errors.organization?.city?.message 
                            ? t(errors.organization.city.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="organization.email"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.contact_email')}
                        error={
                            errors.organization?.email?.message 
                            ? t(errors.organization.email.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="organization.phone"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.phone')}
                        error={
                            errors.organization?.phone?.message 
                            ? t(errors.organization?.phone.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="organization.homepage"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.homepage')}
                        error={
                            errors.organization?.homepage?.message 
                            ? t(errors.organization.homepage.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name='organization.activity_id'
                control={control}
                render={({ field: { onChange, value } }) => (
                    <div>
                        <Label>{t('bookingfrontend.activity')}</Label>
                        <Button 
                            popovertarget='activity_list' 
                            variant="secondary"
                            onClick={() => setOpen(!activityList)}
                        >
                            {  value
                                ? organization.activity.name
                                : t(('bookingfrontend.select_activity')) 
                            }
                            {
                                activityList
                                ? <ChevronUpIcon />
                                : <ChevronDownIcon />
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
                name='organization.show_in_portal'
                control={control}
                render={({ field: { onChange, value } }) => (
                    <div className={styles.view_in_portal}>
                        <div>
                            <h4>{t('bookingfrontend.show_in_portal')}</h4>
                            <p>Long text with long description of Switch main purpose</p>
                        </div>
                        <Switch
                            onClick={() => onChange(!value)}
                            checked={value}
                        />
                    </div>
                )}
            >   
            </Controller>
            <OrganizationContactForm 
                control={control}
                errors={errors}
                number={0}
            />
            <OrganizationContactForm 
                control={control}
                errors={errors}
                number={1}
            />
        </main>
    );
}

export default UpdateOrganizationForm;