import React, {FC, PropsWithChildren} from 'react';
import {Button, Link as DigdirLink} from "@digdir/designsystemet-react";
import {phpGWLink} from "@/service/util";

interface ApplicationLoginLinkProps extends PropsWithChildren {
	onClick?: () => void;
}

const ApplicationLoginLink: FC<ApplicationLoginLinkProps> = (props) => {
	return (
		<DigdirLink asChild data-color={'accent'} className={'link-text link-text-unset normal'}><button
			onClick={props.onClick}
			style={{
				background: 'none',
				border: 'none',
				padding: 0,
				cursor: 'pointer',
				fontSize: 'inherit',
				display: 'inline'
			}}
		>{props.children}</button></DigdirLink>
	);
}

export default ApplicationLoginLink


