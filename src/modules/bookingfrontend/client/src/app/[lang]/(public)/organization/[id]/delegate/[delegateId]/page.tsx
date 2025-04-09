import {FC} from "react";
import { notFound } from "next/navigation";
import DelegateUpdate from "@/components/organization/delegate/delegate.update";
import { fetchDelegateData } from "@/service/api/organization";

interface DelegateParams {
    delegateId: string;
    id: string;
}

interface DelegateProps {
    params: DelegateParams;
}

export async function generateMetadata(props: DelegateProps) {
    const orgId = parseInt(props.params.delegateId, 10);

    if (isNaN(orgId)) return notFound();

    return {
        id: orgId
    }
}

const DelegateView: FC<DelegateProps> = async (props: DelegateProps) => {
    const delegateId = parseInt(props.params.delegateId, 10);
    const orgId = parseInt(props.params.id, 10);
    if (isNaN(delegateId)) return notFound();
    const delegate = await fetchDelegateData(orgId, delegateId);
    if (!delegate) return notFound();
    
    return <DelegateUpdate delegate={delegate} />;
}

export default DelegateView;