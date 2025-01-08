'use client'
import { Chip } from "@digdir/designsystemet-react";
import { FC } from "react";
import styles from '../event.module.scss';
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";

interface ResourcesGroupProps {
    allResources: Map<number, string>;
    selectedResources: Map<number, string>;
    updateField: (selectedResources: Map<number, string>) => void;
}

const ResourcesGroup: FC<ResourcesGroupProps> = ({ allResources, selectedResources, updateField }: ResourcesGroupProps) => {
    const onChange = (id: number, name: string) => {
        const copy = new Map(selectedResources);
        if (selectedResources.has(id)) copy.delete(id);
        else copy.set(id, name);
        updateField(copy);
    }

    return (
        <div className={styles.editResources}>
            { Array.from(allResources).map(([id, name]) => (
                <Chip.Checkbox 
                    key={id}
                    value={name}
                    id={id + ''}
                    asChild 
                    onClick={() => onChange(id, name)} 
                    checked={selectedResources.has(id)}
                >
                    <div>
                        <ColourCircle size="medium" resourceId={id}/>
                        <label htmlFor={id + ''}>{name}</label>
                    </div>
                </Chip.Checkbox> 
            ))}
        </div>
  );
} 

export default ResourcesGroup;