import {FC} from "react";
import { notFound } from "next/navigation";
import OrganizationWrapper from "@/components/organization/organization.wrapper";

interface OrganizationParams {
    id: string;
}

interface OrganizationProps {
    params: OrganizationParams;
}

export async function generateMetadata(props: OrganizationProps) {
    const orgId = parseInt(props.params.id, 10);
    if (isNaN(orgId)) return notFound();

    return {
        id: orgId
    }
}


const Organization: FC<OrganizationProps> = async (props: OrganizationProps) => {
    const orgId = parseInt(props.params.id, 10);
    if (isNaN(orgId)) return notFound();

    return <OrganizationWrapper id={orgId}/>;
}

export default Organization;