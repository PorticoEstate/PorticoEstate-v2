'use server'
import {FC} from "react";
import { notFound } from "next/navigation";
import DelegateCreate from "@/components/organization/delegate/delegate.create";
import { fetchOrganization } from "@/service/api/organization";
import AuthGuard from "@/components/user/auth-guard";

interface DelegateParams {
    id: string;
}

interface DelegateProps {
    params: DelegateParams;
}

export async function generateMetadata(props: DelegateProps) {
    const orgId = parseInt(props.params.id, 10);

    if (isNaN(orgId)) return notFound();

    return { orgId }
}


const DelegateCreatePage: FC<DelegateProps> = async (props: DelegateProps) => {
    const orgId = parseInt(props.params.id, 10);
    if (isNaN(orgId)) return notFound();
    const organization = await fetchOrganization(orgId);
    if (!organization) return notFound();
    
    return <AuthGuard>
        <DelegateCreate organization={organization} />
    </AuthGuard>
}

export default DelegateCreatePage;