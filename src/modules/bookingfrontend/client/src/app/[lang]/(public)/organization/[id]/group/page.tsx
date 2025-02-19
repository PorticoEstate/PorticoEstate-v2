import {FC} from "react";
import { notFound } from "next/navigation";
import GroupForm from "@/components/organization/group/form/group.form";

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

const GroupCreate: FC<GroupProps> = async (props: GroupProps) => {
    const orgId = parseInt(props.params.id, 10);
    if (isNaN(orgId)) return notFound();
    
    return null;
}

export default GroupCreate;