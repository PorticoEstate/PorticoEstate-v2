'use client'
import { Chip } from "@digdir/designsystemet-react";
import { FC } from "react";
import styles from '../event.module.scss';

interface ResourcesGroupProps {
    allResources: string[];
    selectedResources: string[];
    updateField: (selectedResources: string[]) => void;
}


const ResourcesGroup: FC<ResourcesGroupProps> = ({ allResources, selectedResources, updateField }: ResourcesGroupProps) => {
    const onChange = (resourceName: string) => {
        const updated = selectedResources.includes(resourceName) ? 
            selectedResources.filter((res) => res !== resourceName)
            : [...selectedResources, resourceName];
        updateField(updated);
    }

    return (
        <div className={styles.editResources}>
            { allResources.map((res) => (
                <Chip.Checkbox 
                    key={res}
                    value={res}
                    id={res}
                    asChild
                    onClick={() => onChange(res)} 
                    checked={selectedResources.includes(res)}
                >
                    <label htmlFor={res}>{res}</label>
                </Chip.Checkbox> 
            ))}
        </div>
  );
} 

export default ResourcesGroup;