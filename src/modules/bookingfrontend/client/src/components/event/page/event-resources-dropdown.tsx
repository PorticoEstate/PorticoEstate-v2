import { FC, useState } from "react";
import { Dropdown, Chip } from "@digdir/designsystemet-react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faCaretUp, faCaretDown } from "@fortawesome/free-solid-svg-icons";

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
            <Dropdown.Trigger variant='tertiary' onClick={() => setOpen(!open)}>
                View Resources
                {open ? 
                    <FontAwesomeIcon icon={faCaretUp} /> 
                    : <FontAwesomeIcon icon={faCaretDown} />
                }
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
