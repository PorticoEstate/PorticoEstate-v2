'use client';
import { createElement } from 'react';
import {Spinner} from "@digdir/designsystemet-react";
import { useGroupData, useOrganizationData } from "@/service/api/organization";
import GroupController from './group.controller';
import GroupCreate from './group.create';

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
    if (!isLoading && data) {
        return createElement(component, { data });
    }
    return <Spinner aria-label='Laster organization data'/>;
} 

const ServerToClientAdapter = ({ id, orgId }: IdsI) => {
    return id 
        ? <GroupWrapper id={id} component={GroupController}/>
        : <GroupWrapper orgId={orgId} component={GroupCreate}/>
}

export default ServerToClientAdapter;