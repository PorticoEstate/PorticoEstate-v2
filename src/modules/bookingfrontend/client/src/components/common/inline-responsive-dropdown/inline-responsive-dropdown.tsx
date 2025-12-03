'use client'
import React, {FC, ReactNode, useState} from 'react';
import {Dropdown, Button} from "@digdir/designsystemet-react";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {ChevronDownIcon, ChevronUpIcon} from "@navikt/aksel-icons";
import styles from './inline-responsive-dropdown.module.scss';

interface Option {
	value: string;
	label: string;
	disabled?: boolean;
	icon?: ReactNode;
}

interface InlineResponsiveDropdownProps {
	triggerContent: ReactNode;
	title?: string;
	options: Option[];
	currentValue?: string;
	onValueChange: (value: string) => void;
	disabled?: boolean;
	placeholder?: string;
}

const InlineResponsiveDropdown: FC<InlineResponsiveDropdownProps> = ({
																		 triggerContent,
																		 options,
																		 currentValue,
																		 onValueChange,
																		 disabled = false,
																		 placeholder,
																		title
																	 }) => {
	const isMobile = useIsMobile();
	const [isExpanded, setIsExpanded] = useState(false);

	if (isMobile) {
		const handleOptionClick = (value: string) => {
			onValueChange(value);
			setIsExpanded(false);
		};
		const chevron  = isExpanded ? <ChevronUpIcon width="1.875rem" height="1.875rem"/> : <ChevronDownIcon width="1.875rem" height="1.875rem"/>;
		return (
			<div className={styles.mobileExpandableList}>
				<Button
					variant="tertiary"
					color="accent"
					data-size="sm"
					onClick={() => setIsExpanded(!isExpanded)}
					disabled={disabled}
					className={styles.mobileToggleButton}
				>
					{title ? <><span>{title}</span><div>{triggerContent}  {chevron}</div></> : triggerContent}
				</Button>

				{isExpanded && (
					<div className={styles.mobileOptionsList}>
						{options.map((option) => (
							<Button
								variant="tertiary"
								color="accent"
								data-size="sm"
								key={option.value}
								onClick={() => onValueChange(option.value)}
								disabled={option.disabled}
								className={`${styles.mobileOption} ${currentValue === option.value ? styles.selected : ''}`}
							>
								{option.icon ? option.icon : option.icon}{option.label}
							</Button>
						))}
					</div>
				)}
			</div>
		);
	}

	return (
		<Dropdown.TriggerContext>
			<Dropdown.Trigger
				variant={"tertiary"}
				color={"accent"}
				data-size={'sm'}
			>
				{triggerContent}  <ChevronDownIcon width="1.875rem" height="1.875rem"/>
			</Dropdown.Trigger>
			<Dropdown>
				<Dropdown.List>
					{options.map((option) => (
						<Dropdown.Item key={option.value}>
							<Dropdown.Button
								onClick={() => onValueChange(option.value)}
								disabled={option.disabled}
								className={currentValue === option.value ? styles.selected : ''}
							>
								{option.icon ? option.icon : option.icon}{option.label}
							</Dropdown.Button>
						</Dropdown.Item>
					))}
				</Dropdown.List>
			</Dropdown>
		</Dropdown.TriggerContext>
	);
};

export default InlineResponsiveDropdown;