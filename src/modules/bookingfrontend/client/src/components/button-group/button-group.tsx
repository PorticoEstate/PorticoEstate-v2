import {FC, PropsWithChildren} from 'react';
import styles from './button-group.module.scss'
import type {Color, SeverityDanger} from "@digdir/designsystemet-react/colors";

interface ButtonGroupProps extends PropsWithChildren {
	className?: string | undefined;
	'data-color'?: Color | SeverityDanger;

}

const ButtonGroup: FC<ButtonGroupProps> = (props) => {
	return (
		<div className={styles.wrapper}>
			<div className={`${styles.buttonGroup} ${props.className || ''}`}
				 data-color={props["data-color"] || 'accent'}>
				{props.children}
			</div>
		</div>
	);
}

export default ButtonGroup


