import {FC} from "react";
import { notFound } from "next/navigation";
import GroupCreate from "@/components/organization/group/group.create";
import { fetchOrganization } from "@/service/api/organization";

interface GroupParams {
    id: string;
}

interface GroupProps {
    params: GroupParams;
}

export async function generateMetadata(props: GroupProps) {
    const orgId = parseInt(props.params.id, 10);
    if (isNaN(orgId)) return notFound();

    return {
        id: orgId
    }
}

const GroupCreatePage: FC<GroupProps> = async (props: GroupProps) => {
    const orgId = parseInt(props.params.id, 10);
    if (isNaN(orgId)) return notFound();
    const organization = await fetchOrganization(orgId);
    if (!organization) return notFound();
    
    return <GroupCreate orgName={organization.name} orgId={orgId}/>;
}

export default GroupCreatePage;