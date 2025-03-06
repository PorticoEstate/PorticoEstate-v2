'use client';
import { zodResolver } from "@hookform/resolvers/zod";
import { Button } from "@digdir/designsystemet-react";
import { useForm } from "react-hook-form";
import { Organization } from "@/service/types/api/organization.types";
import { patchOrganization } from "@/service/api/organization";
import { patchOrganizationSchema, UpdatingOrganization } from "./schemas";
import UpdateOrganizationForm from "./form/organization.update.form";

interface OrganizationUpdateProps {
    data: Organization;
}

const OrganizationUpdate = ({ data }: OrganizationUpdateProps) => {
    const {
        control,
        handleSubmit,
        formState: { errors }
    } = useForm({
        resolver: zodResolver(patchOrganizationSchema),
        defaultValues: {
            name: data.name,
            shortname: data.shortname,
            homepage: data.homepage,
            phone: data.phone,
            email: data.email,
            city: data.city,
            street: data.street,
            district: data.district,
            zip_code: data.zip_code,
            organization_number: data.organization_number,
            activity_id: data.activity.id,
            contacts: [
                {
                    id: data.contacts[0].id,
                    name: data.contacts[0].name,
                    email: data.contacts[0].email,
                    phone: data.contacts[0].phone,
                },
                {
                    id: data.contacts[1].id,
                    name: data.contacts[1].name,
                    email: data.contacts[1].email,
                    phone: data.contacts[1].phone,
                }
            ]
        }
    });

    const update = patchOrganization(data.id);
    const save = (data: UpdatingOrganization) => {
        update.mutate(data);
    }

    return (
        <>
            <UpdateOrganizationForm 
                organization={data} 
                errors={errors} 
                control={control}
            />
            <Button onClick={handleSubmit(save)}>save</Button>
        </>
    )
}

export default OrganizationUpdate;