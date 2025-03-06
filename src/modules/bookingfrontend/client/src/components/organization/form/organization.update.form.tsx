'use client';
import { useForm, Controller } from "react-hook-form";
import { Textfield, Textarea, Dropdown, Switch } from "@digdir/designsystemet-react";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { useActivityList } from "@/service/api/activity";
import { Organization, ShortActivity } from "@/service/types/api/organization.types";
import { OrganizationContactForm } from "./organizaiton.contact.form";

interface UpdateOrganizationProps {
    organization: Organization;
    errors: any;
    control: any;
}

const UpdateOrganizationForm = ({ organization, errors, control }: UpdateOrganizationProps) => {
    const t = useTrans();
    const { data: activities } = useActivityList(organization.id);
    return (
        <main>
            <h4>{t('bookingfrontend.organization_details')}</h4>
            <Controller
                name="organization_number"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.organization_number')}
                        error={
                            errors.organization_number?.message 
                            ? t(errors.organization_number.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="name"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.organization_name')}
                        error={
                            errors.name?.message 
                            ? t(errors?.name.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="shortname"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.shortname')}
                        error={
                            errors.shortname?.message 
                            ? t(errors?.shortname.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="street"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.street')}
                        error={
                            errors.street?.message 
                            ? t(errors?.street.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="zip_code"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.zip_code')}
                        error={
                            errors.zip_code?.message 
                            ? t(errors?.zip_code.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="street"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.street')}
                        error={
                            errors.street?.message 
                            ? t(errors?.street.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="district"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.district')}
                        error={
                            errors.district?.message 
                            ? t(errors?.district.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="city"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.city')}
                        error={
                            errors.city?.message 
                            ? t(errors?.city.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="email"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.email')}
                        error={
                            errors.email?.message 
                            ? t(errors?.email.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="phone"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.phone')}
                        error={
                            errors.phone?.message 
                            ? t(errors?.phone.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name="homepage"
                control={control}
                render={({ field }) => (
                    <Textfield
                        {...field}
                        label={t('bookingfrontend.homepage')}
                        error={
                            errors.homepage?.message 
                            ? t(errors?.homepage.message) 
                            : undefined
                        }
                    />
                )}
            />
            <Controller
                name='activity_id'
                control={control}
                render={({ field: { onChange, value } }) => (
                    <Dropdown.TriggerContext>
                        <Dropdown.Trigger>
                            { value
                                ? organization.activity.name
                                : t(('bookingfrontend.select_activity')) 
                            }
                        </Dropdown.Trigger>
                        <Dropdown>
                            <Dropdown.List>
                                { activities?.map((item: ShortActivity) => (
                                    <Dropdown.Item key={item.id} onClick={() => onChange(item)}>
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
            <div>
                <div>
                    <h5>{t('bookingfrontend.show_in_portal')}</h5>
                    <p>{t('bookingfrontend.control_organization_visibility')}</p>
                </div>
                <Switch
                   position={organization.show_in_portal ? 'end' : 'start'} 
                />
            </div>
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