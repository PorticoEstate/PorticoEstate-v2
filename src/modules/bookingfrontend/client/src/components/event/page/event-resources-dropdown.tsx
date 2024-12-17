import { FC, useState } from "react";
import { Dropdown, Chip } from "@digdir/designsystemet-react";

interface ResourcesDropdownpProps {
    resources: string[];
}

const ResourcesDropdown: FC<ResourcesDropdownpProps> = ({ resources }: ResourcesDropdownpProps) => {
    const [open, setOpen] = useState(false);

    const renderItem = (res: string) => (
        <Dropdown.Item key={res}>
            <Chip.Radio disabled={true} defaultChecked>{res}</Chip.Radio>
        </Dropdown.Item>
    )

    return (
        <Dropdown.TriggerContext>
            <Dropdown.Trigger onClick={() => setOpen(!open)}>
                View Resources
                {open ? <span>open</span> : <span>close</span>}
            </Dropdown.Trigger>
            <Dropdown placement='bottom' open={open} onClose={() => setOpen(false)}>
                <Dropdown.List>
                    { resources.map((res) => renderItem(res) )}
                </Dropdown.List>
            </Dropdown>
        </Dropdown.TriggerContext>
    );
}

export default ResourcesDropdown
