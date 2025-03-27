'use client';
import { zodResolver } from "@hookform/resolvers/zod";
import { Button } from "@digdir/designsystemet-react";
import { useForm } from "react-hook-form";
import { Organization } from "@/service/types/api/organization.types";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { patchOrganizationRequest } from "@/service/api/organization";
import { patchOrganizationSchema, UpdatingOrganization } from "./schemas";
import UpdateOrganizationForm from "./form/organization.update.form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faFloppyDisk } from "@fortawesome/free-solid-svg-icons";

interface OrganizationUpdateProps {
    org: Organization;
}

const OrganizationUpdate = ({ org }: OrganizationUpdateProps) => {
    const t = useTrans();
    const queryClient = useQueryClient();

    const patch = useMutation({
        mutationFn: (data: any) => patchOrganizationRequest(org.id, data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['organization', org.id] });
        }
    })

    const {
        control,
        handleSubmit,
        formState: { errors }
    } = useForm({
        resolver: zodResolver(patchOrganizationSchema),
        mode: 'onChange',
        defaultValues: {
            organization: {
                shortname: org.shortname,
                name: org.name,
                homepage: org.homepage,
                phone: org.phone,
                email: org.email,
                city: org.city,
                street: org.street,
                district: org.district,
                zip_code: org.zip_code,
                organization_number: org.organization_number,
                activity_id: org.activity.id,
                show_in_portal: org.show_in_portal
            },
            contacts: [
                {
                    id: org.contacts[0].id,
                    name: org.contacts[0].name,
                    email: org.contacts[0].email,
                    phone: org.contacts[0].phone,
                },
                {
                    id: org.contacts[1].id,
                    name: org.contacts[1].name,
                    email: org.contacts[1].email,
                    phone: org.contacts[1].phone,
                }
            ]
        }
    });

    const save = (org: UpdatingOrganization) => {
        patch.mutate(org);
    }
    return (
        <>
            <UpdateOrganizationForm 
                organization={org} 
                errors={errors} 
                control={control}
            />
            <Button onClick={handleSubmit(save)}>
                <FontAwesomeIcon icon={faFloppyDisk} />
                {t('bookingfrontend.save')}
            </Button>
        </>
    )
}

export default OrganizationUpdate;