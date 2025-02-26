import {FC} from "react";
import { notFound } from "next/navigation";
import ServerToClientAdapter from "@/components/organization/delegate/delegate.wrapper";

interface DelegateParams {
    id: string;
}

interface DelegateProps {
    params: DelegateParams;
}

export async function generateMetadata(props: DelegateProps) {
    const orgId = parseInt(props.params.id, 10);

    if (isNaN(orgId)) return notFound();

    return {
        id: orgId
    }
}

const DelegateView: FC<DelegateProps> = async (props: DelegateProps) => {
    const delegateId = parseInt(props.params.id, 10);
    if (isNaN(delegateId)) return notFound();
    
    return <ServerToClientAdapter id={delegateId}/>
}

export default DelegateView;