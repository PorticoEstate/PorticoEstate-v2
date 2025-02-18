import {FC} from "react";
import { notFound } from "next/navigation";

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


const DelegateCreate: FC<DelegateProps> = async (props: DelegateProps) => {
    const orgId = parseInt(props.params.id, 10);
    if (isNaN(orgId)) return notFound();
    
    return null;
}

export default DelegateCreate;