'use client';
import {Spinner} from "@digdir/designsystemet-react";
import { useDelegateData } from "@/service/api/organization";
import DelegateView from "./organization.view";

interface DelegateWrapper {
    id: number;
}

const DelegateWrapper = ({ id }: DelegateWrapper) => {
    const { data: organization, isLoading } = useDelegateData(id);
    if (!isLoading && !organization) {
        return null;
    }
    if (!isLoading) {
        return <DelegateView organization={organization} />;
    }
    return <Spinner aria-label='Laster organization data'/>;
} 

export default DelegateWrapper;