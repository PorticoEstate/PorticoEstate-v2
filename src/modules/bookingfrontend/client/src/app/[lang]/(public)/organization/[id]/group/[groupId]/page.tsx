import {FC} from "react";
import { notFound } from "next/navigation";
import { fetchGroupData } from "@/service/api/organization";
import GroupController from "@/components/organization/group/group.controller";
import AuthGuard from "@/components/user/auth-guard";

interface GroupParams {
    id: string;
    groupId: string;
}

interface GroupProps {
    params: GroupParams;
}

export async function generateMetadata(props: GroupProps) {
    const groupId = parseInt(props.params.groupId, 10);

    if (isNaN(groupId)) return notFound();

    return {
        id: groupId
    }
}

const GroupView: FC<GroupProps> = async (props: GroupProps) => {
    const orgId = parseInt(props.params.id, 10);
    if (isNaN(orgId)) return notFound();
    const groupId = parseInt(props.params.groupId, 10);
    if (isNaN(groupId)) return notFound();
    const group = await fetchGroupData(orgId, groupId);
    if (!group) return notFound();
    
    return <AuthGuard>
        <GroupController data={group}/>
        </AuthGuard>
}

export default GroupView;