'use client';

import { Group } from "@/service/types/api/organization.types";
import GroupUpdateController from "./form/group-update.form";
import { useState } from "react";
import { Button } from "@digdir/designsystemet-react";

interface GroupViewProps {
    group: Group;
}
interface GroupController {
    data: Group;
}

const GroupView = ({ group }: GroupViewProps) => {
    return null;
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