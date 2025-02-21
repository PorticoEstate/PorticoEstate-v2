import { FC, useState } from "react";
import { Dropdown } from "@digdir/designsystemet-react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faCaretUp, faCaretDown } from "@fortawesome/free-solid-svg-icons";

interface YearDropdownpProps {
	onChange: (year: number) => void;
}

const YearDropdown: FC<YearDropdownpProps> = ({ onChange }: YearDropdownpProps) => {
    const [open, setOpen] = useState(false);
    const year = new Date().getFullYear();
    const years = Array.from(new Array(10), (v, idx) => year + idx);
	
	const onClick = (year: number) => {
		console.log(year);
		onChange(year);
		setOpen(false);
	}

    const renderItem = (year: number) => (
        <Dropdown.Item 
			key={year} 
			style={{marginBottom: '0.5rem'}}
			onClick={() => onClick(year)}
		>
            {year}
        </Dropdown.Item>
    )

    return (
        <Dropdown.TriggerContext>
            <Dropdown.Trigger variant='tertiary' onClick={() => setOpen(!open)}>
                {open ? 
                    <FontAwesomeIcon icon={faCaretUp} /> 
                    : <FontAwesomeIcon icon={faCaretDown} />
                }
            </Dropdown.Trigger>
            <Dropdown placement='bottom' open={open} onClose={() => setOpen(false)}>
                <Dropdown.List>
                    { years.map((year) => renderItem(year) )}
                </Dropdown.List>
            </Dropdown>
        </Dropdown.TriggerContext>
    );
}

export default YearDropdown;
