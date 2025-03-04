'use client';
import {Spinner} from "@digdir/designsystemet-react";
import { useOrganizationData } from "@/service/api/organization";
import OrganizationView from "./organization.view";

interface OrganizationWrapper {
    id: number;
}

const OrganizationWrapper = ({ id }: OrganizationWrapper) => {
    const { data: organization, isLoading } = useOrganizationData(id);
    if (!isLoading && !organization) {
        return null;
    }
    if (!isLoading && organization) {
        return <OrganizationView organization={organization} />;
    }
    return <Spinner aria-label='Laster organization data'/>;
} 

export default OrganizationWrapper;