import {FC} from "react";
import { notFound } from "next/navigation";
import DelegateWrapper from "@/components/organization/delegate/delegate.wrapper";

interface DelegateParams {
    delegateId: string;
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


const Delegate: FC<DelegateProps> = async (props: DelegateProps) => {
    const delegateId = parseInt(props.params.delegateId, 10);
    if (isNaN(delegateId)) return notFound();
    
    return <DelegateWrapper id={delegateId}/>;
}

export default Delegate;