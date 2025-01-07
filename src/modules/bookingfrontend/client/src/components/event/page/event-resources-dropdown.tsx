import { FC, useState } from "react";
import { Dropdown, Tag } from "@digdir/designsystemet-react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faCaretUp, faCaretDown } from "@fortawesome/free-solid-svg-icons";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";

interface ResourcesDropdownpProps {
    resources: Map<number, string>
}

const ResourcesDropdown: FC<ResourcesDropdownpProps> = ({ resources }: ResourcesDropdownpProps) => {
    const [open, setOpen] = useState(false);
    const t = useTrans();

    const renderResource = (id: number, name: string) => (
        <Dropdown.Item key={id} style={{ marginBottom: '0.5rem' }}>
            <Tag data-size="md" data-color={'accent'}>
                <ColourCircle size="medium" resourceId={id}/>
                {name}
            </Tag>
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
                    {Array.from(resources).map(([id, name]) => renderResource(id, name))}
                </Dropdown.List>
            </Dropdown>
        </Dropdown.TriggerContext>
    );
}

export default ResourcesDropdown
