'use client';
import { createElement } from 'react';
import {Spinner} from "@digdir/designsystemet-react";
import { useDelegateData, useOrganizationData } from "@/service/api/organization";
import DelegateCreate from './delegate.create';
import DelegateUpdate from './delegate.update';

interface IdsI {
    id?: number;
    orgId?: number;
}
interface DelegateWrapper {
    id?: number;
    orgId?: number;
    component: any;
}

function adapter(id?: number, orgId?: number) {
    return id ?
        useDelegateData(id) 
        : useOrganizationData(orgId as number);
}

const DelegateWrapper = ({ id, orgId, component }: DelegateWrapper) => {
    const { data, error, isLoading } = adapter(id, orgId);
    if (!isLoading && !data) {
        return null;
    }
    if (!isLoading) {
        return createElement(component, { data });
    }
    return <Spinner aria-label='Laster organization data'/>;
} 

const ServerToClientAdapter = ({ id, orgId }: IdsI) => {
    return id 
        ? <DelegateWrapper id={id} component={DelegateUpdate}/>
        : <DelegateWrapper orgId={orgId} component={DelegateCreate}/>
}

export default ServerToClientAdapter;