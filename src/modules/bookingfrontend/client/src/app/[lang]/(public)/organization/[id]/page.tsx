import {FC} from "react";
import { notFound } from "next/navigation";
import { fetchOrganization } from "@/service/api/organization";
import OrganizationController from "@/components/organization/organization.controller";
import AuthGuard from "@/components/user/auth-guard";

interface OrganizationParams {
    id: string;
}

interface OrganizationProps {
    params: OrganizationParams;
}

export async function generateMetadata(props: OrganizationProps) {
    const orgId = parseInt(props.params.id, 10);
    if (isNaN(orgId)) return notFound();

    return { orgId }
}


const Organization: FC<OrganizationProps> = async (props: OrganizationProps) => {
    const orgId = parseInt(props.params.id, 10);
    if (isNaN(orgId)) return notFound();
    const organization = await fetchOrganization(orgId);
    if (!organization) return notFound();

    return <AuthGuard>
        <OrganizationController data={organization} />
    </AuthGuard>
}

export default Organization;