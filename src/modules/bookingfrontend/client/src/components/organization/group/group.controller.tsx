'use client';
import { Group } from '@/service/types/api/organization.types';
import { useState } from 'react';
import GroupUpdateController from './group.update';
import GroupView from './group.view';
import { Button } from '@digdir/designsystemet-react';

interface GroupController {
    data: Group;
}


const GroupController = ({ data }: GroupController) => {
    const [editing, setEditing] = useState(false);
    console.log(data);
    return (
        <>
            { 
                editing 
                ? <GroupUpdateController group={data}/>
                : <GroupView group={data}/>
            }
            <div>
                <Button onClick={() => setEditing(!editing)}>
                    {editing ? 'Avbryt' : 'Rediger'}
                </Button>
            </div>
        </>
    )
}

export default GroupController;