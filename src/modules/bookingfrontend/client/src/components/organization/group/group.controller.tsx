'use client';
import { Group } from '@/service/types/api/organization.types';
import { useState } from 'react';
import GroupUpdateController from './group.update';
import GroupView from './group.view';
import { Button } from '@digdir/designsystemet-react';
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faPen } from "@fortawesome/free-solid-svg-icons";

interface GroupController {
    data: Group;
}

const GroupController = ({ data }: GroupController) => {
    const [editing, setEditing] = useState(false);

    const btn = (
        <Button variant='secondary' onClick={() => setEditing(!editing)}>
            { !editing
                ? <FontAwesomeIcon icon={faPen} />
                : null
            }
            {editing ? 'Avbryt' : 'Rediger'}
        </Button>
    )

    return (
        <>
            {
                !editing ? btn : null
            }
            { 
                editing 
                ? <GroupUpdateController group={data} button={btn}/>
                : <GroupView group={data}/>
            }
        </>
    )
}

export default GroupController;