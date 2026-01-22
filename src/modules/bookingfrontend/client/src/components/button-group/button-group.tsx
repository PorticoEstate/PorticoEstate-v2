import {FC, PropsWithChildren} from 'react';
import styles from './button-group.module.scss'
import type {Color} from "@digdir/designsystemet-types";

interface ButtonGroupProps extends PropsWithChildren {
	className?: string | undefined;
	'data-color'?: Color;

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


