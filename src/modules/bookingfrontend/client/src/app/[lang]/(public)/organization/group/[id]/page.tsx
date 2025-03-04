import {FC} from "react";
import { notFound } from "next/navigation";
import ServerToClientAdapter from "@/components/organization/group/group.wrapper";

interface GroupParams {
    id: string;
}

interface GroupProps {
    params: GroupParams;
}

export async function generateMetadata(props: GroupProps) {
    const groupId = parseInt(props.params.id, 10);

    if (isNaN(groupId)) return notFound();

    return {
        id: groupId
    }
}

const GroupView: FC<GroupProps> = async (props: GroupProps) => {
    const groupId = parseInt(props.params.id, 10);
    if (isNaN(groupId)) return notFound();
    
    return <ServerToClientAdapter id={groupId}/>
}

export default GroupView;