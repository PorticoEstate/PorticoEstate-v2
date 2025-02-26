'use client';
import { createElement } from 'react';
import {Spinner} from "@digdir/designsystemet-react";
import { useGroupData, useOrganizationData } from "@/service/api/organization";
import GroupView from './group.view';
import GroupForm from './form/group.form';

interface IdsI {
    id?: number;
    orgId?: number;
}
interface GroupWrapper {
    id?: number;
    orgId?: number;
    component: any;
}

function adapter(id?: number, orgId?: number) {
    return id ?
        useGroupData(id) 
        : useOrganizationData(orgId as number);
}

const GroupWrapper = ({ id, orgId, component }: GroupWrapper) => {
    const { data, isLoading } = adapter(id, orgId);
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
        ? <GroupWrapper id={id} component={GroupView}/>
        : <GroupWrapper orgId={orgId} component={GroupForm}/>
}

export default ServerToClientAdapter;