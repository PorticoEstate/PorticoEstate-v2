'use client'
import { Chip } from "@digdir/designsystemet-react";
import { FC } from "react";
import styles from '../event.module.scss';
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";

interface ResourcesGroupProps {
    buildingResources: Map<number, string>;
    selectedResources: Map<number, string>;
    updateField: (selectedResources: Map<number, string>) => void;
}

const ResourcesGroup: FC<ResourcesGroupProps> = ({ buildingResources, selectedResources, updateField }: ResourcesGroupProps) => {
    const onChange = (id: number, name: string) => {
        const copy = new Map(selectedResources);
        if (selectedResources.has(id)) copy.delete(id);
        else copy.set(id, name);
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
                    checked={selectedResources.has(id)}
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