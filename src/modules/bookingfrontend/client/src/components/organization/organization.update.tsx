'use client';
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { Organization } from "@/service/types/api/organization.types";
import { patchOrganization } from "@/service/api/organization";
import { patchOrganizationSchema, UpdatingOrganization } from "./schema";

interface OrganizationUpdateProps {
    data: Organization;
}

const OrganizationUpdate = ({ data }: OrganizationUpdateProps) => {
    const t = useTrans();
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
            organization_number: data.organization_number
        }
    });

    const update = patchOrganization(data.id);

    const save = (data: UpdatingOrganization) => {
        update.mutate(data);
    }

    return null;
}

export default OrganizationUpdate;