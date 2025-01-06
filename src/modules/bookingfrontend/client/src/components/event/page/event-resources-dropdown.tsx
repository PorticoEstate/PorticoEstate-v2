import { FC, useState } from "react";
import { Dropdown, Chip, Tag } from "@digdir/designsystemet-react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faCaretUp, faCaretDown } from "@fortawesome/free-solid-svg-icons";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";

interface ResourcesDropdownpProps {
    resources: string[];
}

const ResourcesDropdown: FC<ResourcesDropdownpProps> = ({ resources }: ResourcesDropdownpProps) => {
    const [open, setOpen] = useState(false);
    const t = useTrans();


    const renderItem = (res: string) => (
        <Dropdown.Item key={res} style={{marginBottom: '0.5rem'}}>
            <Tag data-size="md" data-color={'accent'}>{res}</Tag>
        </Dropdown.Item>
    )

    return (
        <Dropdown.TriggerContext>
            <Dropdown.Trigger variant='tertiary' onClick={() => setOpen(!open)}>
                {t('bookingfrontend.view_resources')}
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
