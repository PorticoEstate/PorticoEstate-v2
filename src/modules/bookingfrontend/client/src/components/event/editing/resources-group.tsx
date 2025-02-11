'use client'
import { Chip } from "@digdir/designsystemet-react";
import { FC } from "react";
import styles from '../event.module.scss';
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";

interface ResourcesGroupProps {
    buildingResources: Map<number, string>;
    selectedResources: {id: number, name: string}[]
    updateField: (selectedResources: { id: number, name: string}[]) => void;
}

const ResourcesGroup: FC<ResourcesGroupProps> = ({ buildingResources, selectedResources, updateField }: ResourcesGroupProps) => {
    const onChange = (id: number, name: string) => {
        let copy;
        const exist = selectedResources.find((item) => item.id === id);
        if (exist) copy = selectedResources.filter((item) => item.id !== exist.id);
        else copy = [...selectedResources, { id, name }];
        console.log(copy, 'copy');
        updateField(copy);  
    }

    return (
        <div className={styles.editResources}>
            { Array.from(buildingResources).map(([id, name]) => (
                <Chip.Checkbox 
                    key={id}
                    asChild
                    value={String(id)}
                    id={`resource-${id}`}
                    onChange={() => onChange(id, name)} 
                    checked={!!selectedResources.find((item) => id === item.id)}
                >   
                    <label htmlFor={`resource-${id}`}>
                        <ColourCircle size="medium" resourceId={id}/>
                        <span>{name}</span>
                    </label>
                </Chip.Checkbox> 
            ))}
        </div>
  );
} 

export default ResourcesGroup;